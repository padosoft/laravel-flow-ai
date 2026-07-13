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
        // spec. Keep reading messages, skipping id-less ones, until one
        // carries a REAL `id`. `$deadline` is an OVERALL budget for the
        // whole call, not a per-read one: each individual readLine() still
        // has its own bounded wait (so a truly stuck server fails fast), but
        // without a call-wide ceiling a server that keeps emitting
        // notifications indefinitely — never sending the actual response —
        // could keep resetting a per-read-only timer forever and never time
        // out at all.
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            if (microtime(true) >= $deadline) {
                throw new McpConnectionException("MCP server [{$this->command}] did not respond within {$this->timeoutSeconds}s (interleaved server messages kept arriving without the requested response).");
            }

            $decoded = $this->readMessage($deadline);

            if (! array_key_exists('id', $decoded)) {
                // The `id` KEY is genuinely absent — a true JSON-RPC
                // notification (progress, logging, etc.). Keep waiting.
                continue;
            }

            $responseId = $decoded['id'];

            // A null `id` is NOT the same as a missing one: JSON-RPC 2.0
            // reserves it for an ERROR the server could not correlate to any
            // specific request (e.g. a parse error before it could even
            // read ours) — a real failure the client is waiting on, not
            // something to silently skip like a notification. This
            // transport only ever has ONE request in flight at a time, so a
            // null-id message is attributed to the current call (the
            // exact-match check below is simply skipped for it, rather than
            // rejected as a mismatch).
            if ($responseId !== null && $responseId !== $id) {
                throw new McpConnectionException("MCP server response id [{$this->stringifyId($responseId)}] does not match request id [{$id}] — out-of-order responses are not supported by this transport.");
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
    private function readMessage(float $deadline): array
    {
        $line = $this->readLine($deadline);

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

        // A pipe can legitimately accept fewer bytes than requested in ONE
        // fwrite() call (a short write is normal backpressure, not a
        // failure) — loop, advancing past whatever was actually written,
        // until the full message is sent. Only `false`/`0` (no progress at
        // all) is a genuine failure; anything else keeps retrying with the
        // REMAINING tail.
        $remaining = $encoded;

        while ($remaining !== '') {
            $written = fwrite($this->pipes[0], $remaining);

            if ($written === false || $written === 0) {
                throw new McpConnectionException("Failed writing to MCP server process [{$this->command}] — the process may have exited or its input pipe is full.");
            }

            $remaining = substr($remaining, $written);
        }

        fflush($this->pipes[0]);
    }

    private function readLine(float $deadline): string
    {
        $this->ensureStarted();

        // Loops past blank lines (a stray "\n" a server emits is not EOF —
        // only fgets() itself returning false, meaning the stream actually
        // closed, means that) — bounded by the SAME overall $deadline as the
        // rest of request(), not a fresh per-call budget, so a server
        // spamming blank lines forever cannot bypass the timeout either.
        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new McpConnectionException("MCP server [{$this->command}] did not respond within {$this->timeoutSeconds}s.");
            }

            // Split into whole seconds + microseconds rather than
            // (int) ceil($remaining): rounding UP to the next whole second
            // could let a single read's own timeout extend up to ~1s past
            // $deadline, undermining the overall-budget guarantee this
            // method exists to enforce (e.g. 0.1s remaining would otherwise
            // arm a full 1s read timeout).
            $seconds = (int) floor($remaining);
            $microseconds = (int) round(($remaining - $seconds) * 1_000_000);
            stream_set_timeout($this->pipes[1], $seconds, $microseconds);
            $line = fgets($this->pipes[1]);
            $meta = stream_get_meta_data($this->pipes[1]);

            if ($meta['timed_out']) {
                throw new McpConnectionException("MCP server [{$this->command}] did not respond within {$this->timeoutSeconds}s.");
            }

            if ($line === false) {
                $stderr = is_resource($this->pipes[2]) ? (string) stream_get_contents($this->pipes[2], self::READ_CHUNK_BYTES) : '';
                $detail = trim($stderr) !== '' ? " stderr: {$stderr}" : '';

                throw new McpConnectionException("MCP server [{$this->command}] closed its output stream unexpectedly.{$detail}");
            }

            if (trim($line) === '') {
                continue;
            }

            return $line;
        }
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
