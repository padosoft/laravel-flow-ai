# Laravel Flow AI

> The agentic AI layer for [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow): LLM nodes, MCP client/server, bounded agents, AI flow builder and Flow Advisor.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-flow-ai.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-flow-ai)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)

## Status

✅ **Stable (v1.0.0)** — the agentic AI layer of the **Laravel Flow 2.0** suite; from v1.0.0 the `@api` surface is covered by SemVer. Requires [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) `^2.0`.

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

```bash
composer require padosoft/laravel-flow-ai
```

Requires [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) `^2.0`, which Composer resolves from Packagist automatically.

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

### Driver: one provider, or all of them

The package binds `LlmClient` to `Padosoft\LaravelFlowAI\Llm\AnthropicDriver` by default — one
provider over its raw HTTP API, which is the right amount of machinery for one provider and the
wrong amount for five.

`Padosoft\LaravelFlowAI\Llm\LaravelAiDriver` is the alternative, backed by the official
[`laravel/ai`](https://github.com/laravel/ai) SDK (^0.11). Binding it changes nothing in your
nodes and gets you:

| | |
|---|---|
| **Every provider the SDK supports** | selected by config instead of by swapping a class |
| **Failover** across providers and models | already implemented and tested upstream |
| **Observability, free** | the run emits the 0.11 step and tool events, so [laravel-ai-finops](https://github.com/padosoft/laravel-ai-finops) meters a node's spend **per step** and [laravel-iam-agents](https://github.com/padosoft/laravel-iam-agents) stamps the run's invocation id onto the delegation context — with nothing added to this package |

```php
$this->app->bind(LlmClient::class, fn () => new LaravelAiDriver(provider: 'anthropic'));
```

Two things it deliberately does not do. **Structured output is instructed, not provider-enforced**:
`laravel/ai` takes a schema as Laravel JsonSchema *type objects*, not the raw JSON Schema array
`LlmRequest::$responseSchema` carries, and translating one into the other for arbitrary schemas is a
job with edge cases that would fail quietly — so the schema is stated in the instructions as a
contract, and the caller parses the text, exactly as `LlmClient` already promises. When you need the
provider itself to refuse a non-conforming answer, bind `AnthropicDriver`, which forces it through
tool use. And **one step, never a loop**: a node is a completion, and leaving the step budget at the
SDK default would let a prompt that happened to emit a tool call turn one node into a multi-step run
the flow never authorised.

The package binds `LlmClient` to `Padosoft\LaravelFlowAI\Llm\AnthropicDriver` by default. Swap the binding in your own service provider to point at a different implementation. `Padosoft\LaravelFlowAI\Llm\FakeDriver` — a deterministic, no-network driver constructed with a queue of canned `LlmResponse`s — is available for your own application's tests.

## Delegated identity: agents that act on behalf of a user

`BoundedAgentNode` can run under a **delegated identity** — the pairing of WHO the work is for (`subject`, e.g. `user:42`) and WHICH agent identity performs it (`actor`, e.g. `agent:01J…`), proven by a short-lived delegated access token (OAuth 2.0 Token Exchange, RFC 8693). This package owns the seam and depends on **no IAM package**; the reference provider is [`padosoft/laravel-iam-agents`](https://github.com/padosoft/laravel-iam-agents) on top of [`padosoft/laravel-iam-server`](https://github.com/padosoft/laravel-iam-server).

Bind `Contracts\DelegatedIdentityResolver` (typically container-scoped, so each run resolves fresh) and the node does the rest:

- at spawn, the resolved identity's env vars (`FLOW_DELEGATED_TOKEN`, `FLOW_DELEGATED_SUBJECT`, `FLOW_DELEGATED_ACTOR`) are handed to the spawned MCP tool server — process environment only, **never** run input (core persists `flow_runs.input` unredacted), transcripts, prompts, or logs;
- before **every** tool call the grant is re-checked: a revocation landing mid-run throws `Identity\Exceptions\GrantRevokedException` and the node halts, fail-closed, *before* the call happens — the same posture as the tool allowlist;
- no binding = no delegated identity = the pre-existing behavior, unchanged.

`Mcp\FlowToolServer` closes the loop on the inbound side: a verified `subject` in the transport-provided `$actor` becomes the run's persisted `flow_runs.subject` (core ≥ 2.2), so a run started by an agent on a user's behalf is attributable end-to-end — in the run row, not smuggled through its input.

## License

Apache-2.0. See [LICENSE](LICENSE).
