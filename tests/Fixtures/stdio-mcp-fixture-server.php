<?php

declare(strict_types=1);

/**
 * Minimal, self-contained MCP-ish stdio server used ONLY by
 * `tests/Integration/StdioMcpTransportIntegrationTest.php` — spawned as a
 * real subprocess (`php` + this script's path) via the REAL
 * `StdioMcpTransport`, never a fake, so the transport's actual pipe I/O is
 * genuinely exercised. Never hits the network and requires no external
 * package.
 *
 * Deliberately emits ONE `notifications/message` line BEFORE every
 * response, unprompted — the exact server behavior `request()`'s
 * notification-skipping loop exists to tolerate (a real compliant server MAY
 * interleave progress/logging notifications while a request is
 * outstanding).
 */
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    /** @var array<string, mixed> $message */
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $id = $message['id'] ?? null;

    // A JSON-RPC NOTIFICATION (no `id`) — `notifications/initialized` is the
    // only one this fixture receives; it must not be answered.
    if ($id === null) {
        continue;
    }

    fwrite(STDOUT, json_encode([
        'jsonrpc' => '2.0',
        'method' => 'notifications/message',
        'params' => ['level' => 'info', 'data' => 'fixture log line before the real response'],
    ], JSON_THROW_ON_ERROR)."\n");
    fflush(STDOUT);

    $method = $message['method'] ?? '';
    /** @var array<string, mixed> $params */
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
    /** @var array<string, mixed> $arguments */
    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

    // Proves the client's OVERALL request timeout (not just a per-read one)
    // fires even while the server keeps sending live traffic: floods
    // notifications for longer than any reasonable test timeout, then never
    // answers this request's id at all.
    if ($method === 'tools/call' && ($arguments['mode'] ?? null) === 'flood_never_respond') {
        for ($i = 0; $i < 40; $i++) {
            fwrite(STDOUT, json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/message',
                'params' => ['level' => 'info', 'data' => "flood {$i}"],
            ], JSON_THROW_ON_ERROR)."\n");
            fflush(STDOUT);
            usleep(50_000);
        }

        continue;
    }

    // Proves a blank line from the server is skipped, not mistaken for EOF.
    if ($method === 'tools/call' && ($arguments['mode'] ?? null) === 'blank_line_before_response') {
        fwrite(STDOUT, "\n");
        fflush(STDOUT);
    }

    // Proves the WHOLE response line being a JSON ARRAY (not an object at
    // all) is rejected too — a stricter, earlier layer than the
    // scalar_result case below: json_decode(..., true) maps both `{}` and
    // `[]`/`[1,2]` to a PHP array, so a naive is_array() check on the
    // top-level decode would silently accept this as a valid-looking
    // id-less notification and hang until the overall timeout instead of
    // failing fast.
    if ($method === 'tools/call' && ($arguments['mode'] ?? null) === 'array_response') {
        fwrite(STDOUT, json_encode([1, 2, 3], JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);

        continue;
    }

    // Proves a malformed, non-object `result` (a bare scalar) is surfaced as
    // a transport failure, not silently coerced into an empty successful
    // result.
    if ($method === 'tools/call' && ($arguments['mode'] ?? null) === 'scalar_result') {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => 'not-an-object',
        ], JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);

        continue;
    }

    // Proves `result` being a JSON ARRAY (as opposed to the `array_response`
    // mode above, which is the WHOLE line being an array) is ALSO rejected —
    // json_decode(..., true) maps both `{}` and `[]` to the same empty PHP
    // array, so without an explicit shape check `result: []` would pass
    // is_array($decoded['result']) and be silently treated as a successful
    // call with a missing/empty result, hiding the real protocol violation.
    if ($method === 'tools/call' && ($arguments['mode'] ?? null) === 'array_result') {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [],
        ], JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);

        continue;
    }

    // Proves a response with neither a `result` nor an `error` member (a
    // spec-violating message) is surfaced as a transport failure too.
    if ($method === 'tools/call' && ($arguments['mode'] ?? null) === 'no_result_no_error') {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
        ], JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);

        continue;
    }

    $result = match ($method) {
        'initialize' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'fixture', 'version' => '1.0.0']],
        'tools/list' => ['tools' => [['name' => 'echo', 'inputSchema' => ['type' => 'object']]]],
        'tools/call' => (($params['name'] ?? null) === 'fail')
            ? ['content' => [['type' => 'text', 'text' => 'fixture-simulated failure']], 'isError' => true]
            : ['content' => [['type' => 'text', 'text' => json_encode($params['arguments'] ?? [])]], 'isError' => false],
        default => null,
    };

    if ($result === null) {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => "unknown method [{$method}]"],
        ], JSON_THROW_ON_ERROR)."\n");
    } else {
        fwrite(STDOUT, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    fflush(STDOUT);
}
