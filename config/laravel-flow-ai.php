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

];
