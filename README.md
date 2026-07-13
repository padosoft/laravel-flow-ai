# Laravel Flow AI

> The agentic AI layer for [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow): LLM nodes, MCP client/server, bounded agents, AI flow builder and Flow Advisor.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-flow-ai.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow-ai)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)

## Status

🚧 **Under active development** — this package is part of the **Laravel Flow 2.0 program** and is not yet stable. APIs will change without notice until the first tagged minor release. Follow [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) for the core engine and roadmap.

## What it will provide

- **LLM node** — provider-agnostic prompt nodes with structured output validated against typed ports (schema-violation auto-retry), token/cost tracked into business impact.
- **MCP client node** — call external MCP tools as graph nodes.
- **MCP server (flow-as-tool)** — expose published flows as MCP tools (typed ports → JSON Schema); approval gates pause the calling agent for human sign-off. Disabled by default, per-flow opt-in.
- **Bounded agent node** — LLM+tools loop with hard token/cost/iteration budgets, tool allowlists and approval escape hatches.
- **AI flow builder** — natural language → validated flow graph draft.
- **Flow Advisor** — analyzes your node/MCP catalog and run history to suggest new flows or concrete improvements to existing ones (`flow:suggest`, `flow:improve`), always as reviewable drafts.

## Requirements

- PHP `^8.3`
- Laravel `^13.0`

## Installation

> **Not yet installable this way.** `padosoft/laravel-flow` (this package's required dependency) has no tagged release yet — see the development-time note right below. The command below is the FUTURE installation path, once core tags its first release.

```bash
composer require padosoft/laravel-flow-ai
```

> **Development-time note**: `padosoft/laravel-flow` has no tagged release yet, so this package's `composer.json` resolves it via a local `path` repository pointing at `../padosoft-laravel-flow` (a true sibling checkout, one directory up from this package). This means `padosoft/laravel-flow-ai` is not `composer require`-able outside a monorepo-adjacent dev setup until core cuts its first tagged release — Composer's `repositories` block is only honored while developing directly on this package, not when it is installed as a dependency by a host application. Once core tags a release, this constraint switches to a normal SemVer version range and the path repository is removed.

## Configuration

Publish the config file to customize the built-in Anthropic driver:

```bash
php artisan vendor:publish --tag=laravel-flow-ai-config
```

| Env var | Default | Purpose |
| --- | --- | --- |
| `LARAVEL_FLOW_AI_ANTHROPIC_API_KEY` | _(empty)_ | Anthropic API key. Left empty by default — a host app that only binds `Padosoft\LaravelFlowAI\Llm\FakeDriver` for its own tests is never forced to set this. |
| `LARAVEL_FLOW_AI_ANTHROPIC_BASE_URL` | `https://api.anthropic.com/v1/messages` | Anthropic Messages API endpoint. |
| `LARAVEL_FLOW_AI_ANTHROPIC_API_VERSION` | `2023-06-01` | Anthropic API version header. |
| `LARAVEL_FLOW_AI_ANTHROPIC_TIMEOUT_SECONDS` | `30` | Request timeout in seconds. |

## LLM client contract

Every AI-pack node that talks to a language model depends on `Padosoft\LaravelFlowAI\Contracts\LlmClient`, never a concrete provider SDK directly:

```php
use Padosoft\LaravelFlowAI\Contracts\LlmClient;
use Padosoft\LaravelFlowAI\Llm\LlmRequest;

$response = app(LlmClient::class)->complete(new LlmRequest(
    prompt: 'Summarize this run in one sentence.',
    model: 'claude-sonnet-5',
));

$response->content;         // raw completion text
$response->promptTokens;    // usage, always present
$response->completionTokens;
$response->totalTokens();
```

Pass `responseSchema` (a JSON Schema array) on `LlmRequest` to request structured output — the built-in `AnthropicDriver` forwards it as a forced tool call (Anthropic's Messages API has no separate structured-output field) and hands back the schema-shaped result as JSON text in `$response->content`, ready to `json_decode()`. Validating the decoded value against a node's declared output ports is the caller's responsibility, not this contract's.

The package binds `LlmClient` to `Padosoft\LaravelFlowAI\Llm\AnthropicDriver` by default. Swap the binding in your own service provider to point at a different implementation. `Padosoft\LaravelFlowAI\Llm\FakeDriver` — a deterministic, no-network driver constructed with a queue of canned `LlmResponse`s — is available for your own application's tests.

## License

Apache-2.0. See [LICENSE](LICENSE).
