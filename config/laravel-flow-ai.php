<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic driver
    |--------------------------------------------------------------------------
    |
    | Configuration for the built-in Anthropic LlmClient driver. A missing
    | api_key is not validated here — the driver fails naturally on its
    | first real request (an empty x-api-key header is rejected by
    | Anthropic's API), so a host application that only ever binds the
    | FakeDriver for its own tests is never forced to set this.
    |
    */
    'anthropic' => [
        'api_key' => env('LARAVEL_FLOW_AI_ANTHROPIC_API_KEY', ''),
        'base_url' => env('LARAVEL_FLOW_AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1/messages'),
        'api_version' => env('LARAVEL_FLOW_AI_ANTHROPIC_API_VERSION', '2023-06-01'),
        'timeout_seconds' => env('LARAVEL_FLOW_AI_ANTHROPIC_TIMEOUT_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
    |
    | Enforced by every AI-pack node making an outbound call, BEFORE the call
    | happens. Every gate below is permissive when left at its default (empty
    | allowlist / zero rate limit = unrestricted) — the guardrail MECHANISM
    | always runs, only its rules default to no-op, so tightening these later
    | needs no code change.
    |
    */
    'guardrails' => [
        // Node types allowed to make an outbound AI call. Empty = every type
        // allowed. Example: ['ai.llm.prompt'].
        'allowed_node_types' => [],

        // Hosts an outbound call may reach. Empty = every host allowed.
        // Entries are exact hostnames, or a `*.suffix` glob matching any
        // subdomain of `suffix` (never `suffix` itself). Example:
        // ['api.anthropic.com'].
        'egress_allowlist' => [],

        // Maximum outbound calls per node type within the decay window.
        // 0 = unlimited.
        'rate_limit_max_attempts' => (int) env('LARAVEL_FLOW_AI_RATE_LIMIT_MAX_ATTEMPTS', 0),
        'rate_limit_decay_seconds' => (int) env('LARAVEL_FLOW_AI_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP client
    |--------------------------------------------------------------------------
    |
    | Configuration for the built-in ai.mcp.tool node's stdio transport.
    | Server command/args/tool/arguments are wired INPUT ports, not config
    | here — this section only tunes the transport itself.
    |
    */
    'mcp' => [
        'timeout_seconds' => (int) env('LARAVEL_FLOW_AI_MCP_TIMEOUT_SECONDS', 10),

        // Flow names FlowToolServer may expose as MCP tools. Empty by
        // default — a listed name still needs a PUBLISHED version AND an
        // allowing McpToolAuthorizer to actually be visible; this is only
        // the candidate allowlist. Example: ['send-welcome-email'].
        'exposed_flows' => [],
    ],

];
