<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Transport;

use JsonException;
use Padosoft\LaravelFlowAI\Mcp\Exceptions\McpConnectionException;
use Padosoft\LaravelFlowAI\Mcp\McpClient;

/**
 * MCP over stdio: spawns `$command` as a child process and speaks JSON-RPC
 * 2.0 over its stdin/stdout, one newline-delimited message per line — the
 * transport MCP local servers (an `npx`-launched tool, a `php script.php`
 * server, etc.) universally support, and the only transport this package
 * implements (HTTP/SSE, MCP's remote-server transport, is explicitly out of
 * scope for this PR — see the class doc on {@see McpClient}
 * for why). A request blocks for at most `$timeoutSeconds` waiting for its
 * matching response line before failing with {@see McpConnectionException}.
 *
 * This implements the MINIMAL subset of JSON-RPC 2.0 / MCP needed to
 * initialize a session and call one tool — no batching, no bidirectional
 * server-initiated requests, no resource/prompt endpoints. `laravel-flow-ai`
 * evaluated the official `mcp/sdk` package (PHP Foundation + Symfony,
 * MIT/Apache-2.0) for this instead of hand-rolling the wire protocol, but
 * adding a new third-party runtime dependency this package's own tooling
 * had not already vetted was out of scope for an autonomous change in this
 * session — see the PR description for the full reasoning. Revisit that
 * SDK once a human has evaluated and approved it as a dependency; this
 * hand-rolled transport is deliberately narrow so swapping it out later is
 * a contained change.
 *
 * @internal
 */
final class StdioMcpTransport implements McpTransport
{
    private const READ_CHUNK_BYTES = 65536;

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $nextId = 1;

    /**
     * @param  list<string>  $args
     */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $id = $this->nextId++;

        $this->write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        // MCP permits the server to interleave NOTIFICATIONS (progress,
        // logging, `notifications/message`, etc.) while a request is
        // outstanding — a JSON-RPC notification has no `id` field at all, by
        // spec. Keep reading lines (each still bounded by the same per-line
        // $timeoutSeconds, so a genuinely stuck server still fails fast)
        // until one carries a REAL `id`, then require it to match — a
        // mismatched id (not merely absent) means an out-of-order response
        // this single-request-in-flight transport cannot make sense of.
        while (true) {
            $decoded = $this->readMessage();

            if (! array_key_exists('id', $decoded) || $decoded['id'] === null) {
                continue;
            }

            if ($decoded['id'] !== $id) {
                throw new McpConnectionException("MCP server response id [{$this->stringifyId($decoded['id'])}] does not match request id [{$id}] — out-of-order responses are not supported by this transport.");
            }

            if (array_key_exists('error', $decoded)) {
                /** @var array<string, mixed> $error */
                $error = is_array($decoded['error']) ? $decoded['error'] : [];
                $message = is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
                $code = is_int($error['code'] ?? null) ? $error['code'] : 0;

                throw new McpConnectionException("MCP server returned a JSON-RPC error (code {$code}): {$message}");
            }

            /** @var array<string, mixed> $result */
            $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];

            return $result;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readMessage(): array
    {
        $line = $this->readLine();

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new McpConnectionException("MCP server returned a malformed JSON-RPC response: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($decoded)) {
            throw new McpConnectionException('MCP server response was valid JSON but not a JSON-RPC object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function notify(string $method, array $params = []): void
    {
        // A JSON-RPC NOTIFICATION deliberately omits `id` — the server MUST
        // NOT reply to it, so this never reads a response line.
        $this->write([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ]);
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            // Closing stdin (above) signals EOF, which a WELL-BEHAVED server
            // exits on — but MCP makes no such guarantee, and a server that
            // ignores stdin EOF would make proc_close() below block
            // INDEFINITELY (it waits for the child to actually exit).
            // Terminate explicitly first: a process already exiting on its
            // own from the EOF just received is unaffected by an extra
            // signal, and a stuck one is force-killed instead of hanging
            // node cleanup forever.
            $status = proc_get_status($this->process);

            if ($status['running']) {
                proc_terminate($this->process);
            }

            proc_close($this->process);
        }

        $this->process = null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function write(array $message): void
    {
        $this->ensureStarted();

        try {
            $encoded = json_encode($message, JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            throw new McpConnectionException("Failed to encode an outbound MCP message: {$e->getMessage()}", previous: $e);
        }

        $expectedBytes = strlen($encoded);
        $written = fwrite($this->pipes[0], $encoded);

        if ($written !== $expectedBytes) {
            // fwrite() returning `false`, OR a SHORT write (fewer bytes than
            // requested — a pipe can legitimately write partially, it is
            // NOT reserved to "the write failed"), both leave the server
            // with a truncated, unparseable JSON-RPC line. Either case means
            // the message was NOT reliably delivered.
            throw new McpConnectionException("Failed writing to MCP server process [{$this->command}] — the process may have exited or its input pipe is full.");
        }

        fflush($this->pipes[0]);
    }

    private function readLine(): string
    {
        $this->ensureStarted();

        stream_set_timeout($this->pipes[1], $this->timeoutSeconds);
        $line = fgets($this->pipes[1]);
        $meta = stream_get_meta_data($this->pipes[1]);

        if ($meta['timed_out']) {
            throw new McpConnectionException("MCP server [{$this->command}] did not respond within {$this->timeoutSeconds}s.");
        }

        if ($line === false || trim($line) === '') {
            $stderr = is_resource($this->pipes[2]) ? (string) stream_get_contents($this->pipes[2], self::READ_CHUNK_BYTES) : '';
            $detail = trim($stderr) !== '' ? " stderr: {$stderr}" : '';

            throw new McpConnectionException("MCP server [{$this->command}] closed its output stream unexpectedly.{$detail}");
        }

        return $line;
    }

    private function ensureStarted(): void
    {
        if ($this->process !== null) {
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([$this->command, ...$this->args], $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new McpConnectionException("Failed to spawn MCP server process [{$this->command}].");
        }

        $this->process = $process;
        $this->pipes = $pipes;
    }

    private function stringifyId(mixed $id): string
    {
        if (is_scalar($id)) {
            return (string) $id;
        }

        return get_debug_type($id);
    }
}
