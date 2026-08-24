# Changelog

All notable changes to `padosoft/laravel-flow-ai` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0, SemVer applies to source classes annotated `@api`; `@internal` classes may change in any release.

## [1.1.0] — 2026-08-24

### Added

- **Delegated identity for bounded agents (`@api`)** — the run-time half of delegated access for AI agents (OAuth 2.0 Token Exchange, RFC 8693), with NO IAM dependency: this package owns the seam, an identity provider (reference: `padosoft/laravel-iam-agents`) plugs into it.
  - `Contracts\DelegatedIdentityResolver` + `Identity\DelegatedIdentity`: an optional, host-bound resolver hands `Nodes\BoundedAgentNode` the identity the run acts under (`subject` = the user, `actor` = the agent, plus the short-lived delegated token). The token is a secret by construction — private property, `#[SensitiveParameter]`, redacted `__debugInfo()`; its ONLY egress is `environment()`, the env vars (`FLOW_DELEGATED_TOKEN`/`_SUBJECT`/`_ACTOR`) handed to the spawned MCP tool server. Unbound resolver = the pre-existing behavior, unchanged.
  - `Identity\Exceptions\GrantRevokedException`: revocation is a fail-closed STOP. `BoundedAgentNode` halts on it BEFORE spawning the MCP server (spawn-time resolve) and BEFORE every subsequent tool call (per-iteration re-check) — mirroring the tool-allowlist "blocked before it happened" posture; an LLM call never happens on a grant already revoked.
  - `Mcp\FlowToolServer::callTool()` no longer drops the transport-provided `$actor`: a verified `subject` in it becomes the run's persisted `flow_runs.subject` (requires core ≥ 2.2 with the run-subject feature), so MCP-initiated runs are attributable end-to-end.
  - MCP stdio transport env handoff (`@internal` surface): `McpTransportFactory::stdio()` / `StdioMcpTransport` accept extra env vars, MERGED over the parent environment at `proc_open` (empty = plain inheritance, byte-for-byte the previous behavior) — the sanctioned channel for per-run credentials, which never enter run input, transcripts, prompts, or logs.

## [1.0.0] — 2026-07-18

First stable release — the agentic AI layer for [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) (requires core `^2.0`).

### Added

- **LLM prompt node & drivers**: an `ai.llm.prompt` graph node that calls a configured LLM and (optionally) self-repairs a structured-output schema violation by feeding the validation error back to the model and retrying. Pluggable `Llm\` drivers behind a `Contracts\LlmClient` (no real network calls in the test suite — a structural token-sweep test forbids them).
- **MCP client & server** (`Mcp\`): a stdio `McpClient`/`StdioMcpTransport` (tested against a real spawned subprocess) and a `FlowToolServer` that exposes opt-in flows as MCP tools, including a list→invoke→`pending_approval`→poll sequence that surfaces core's human-in-the-loop approval gate. Plus an `ai.mcp.tool` graph node (`Nodes\McpClientNode`, `@api`) that spawns an MCP stdio subprocess and calls a tool from within a flow.
- **Bounded agent node**: an `ai.agent.bounded` node that runs a tool-using agent loop under explicit step/-cost bounds.
- **AI flow builder** (`Builder\FlowBuilderService`): turns a natural-language prompt into an **already-validated** draft `GraphDefinition` (the model picks only from the host's real node catalog; the result runs through core's `GraphValidator` before it is returned — never persisted by the builder).
- **Flow Advisor** (`Advisor\FlowAdvisor`): four **deterministic** (no-LLM, no-network) run-history analyzers — failure hotspots, duration outliers, repeated segments, unused MCP-exposed tools — surfaced as **draft** definition versions (never published) carrying each finding's rationale in `metadata['advisor']`. `flow:suggest` / `flow:improve {flow}` Artisan commands.
- **Guardrails** (`Guardrails\PolicyEngine`): a policy layer that can deny an LLM/agent/MCP action; a denial is folded into the builder/validator error surface.
