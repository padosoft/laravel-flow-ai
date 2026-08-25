# Changelog

All notable changes to `padosoft/laravel-flow-ai` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0, SemVer applies to source classes annotated `@api`; `@internal` classes may change in any release.

## [1.2.0] — 2026-08-25

### Added

- **MCP tool pinning (`@api`)** — an MCP server answers `tools/list` fresh on every handshake, and nothing in the protocol stops it from answering differently tomorrow. A tool whose description quietly grows an extra instruction is, to a model, a different tool at the same name; an allowlist never notices, because the name did not change. Pinning records the digest of each tool's contract and compares it at every handshake.
  - `Mcp\Pinning\ToolContract` digests the fields a caller acts on — `name`, `title`, `description`, `inputSchema`, `outputSchema`, `annotations` — canonicalized so object key order does not move the digest while list order does. Fields the server controls but nobody reads (its own version string, vendor keys) are excluded: a server rewriting a description can leave those untouched, so digesting them adds churn without adding evidence.
  - `Mcp\Pinning\ToolPins` / `PinRegistry` / `PinViolation`: four distinct violation kinds (contract changed, pinned tool missing, unpinned tool, server not pinned) and three modes — `off` (default), `warn` (log and continue; a migration setting), `enforce` (block). A pinset means the catalog is CLOSED: a tool the server advertises that nobody pinned is a violation. An unrecognised mode throws rather than degrading to `off`.
  - `Mcp\Exceptions\McpToolPinMismatchException`: a THIRD failure class alongside `McpConnectionException` and `McpToolExecutionException` — the server answered fine, and that is the problem. `Nodes\McpClientNode` and `Nodes\BoundedAgentNode` map it to `NodeResult::failed()`; the bounded agent halts before the first prompt is built, so a drifted description never reaches the model.
  - The call path is pinned too: `tools/call` names a tool directly and never needs the catalog, so a pinned session verifies it once before the first call. One extra round trip, only when pinning is on, and already paid by any caller that lists first.
  - `flow:mcp-pin {server} {args*}` prints a paste-ready config block (including the server id, the part most likely to be mistyped) and says what changed when re-pinning a drifted server; `--verify` turns it into a CI gate that exits non-zero on drift.
- **AI-BOM (`@api`)** — `Bom\AiBom` and `flow:ai-bom`: the bill of materials `composer.lock` cannot produce, because half an AI supply chain is not packages. Packages, model providers (and where model ids actually come from), pinned MCP servers with their tool digests, exposed flows with their published version and checksum, and the guardrail posture bounding all of it. Derived from configuration and the container only — never by connecting to anything — and no API key is ever read. `--digest` prints a timestamp-free content digest, so `test "$(php artisan flow:ai-bom --digest)" = "$(cat ai-bom.sha)"` is the whole CI gate for "the AI supply chain moved and nobody said so".

### Changed

- `Mcp\McpClient` (`@internal`) accepts an optional `ToolPins`. Unset (the default) leaves every existing session byte-for-byte unchanged, with no extra round trip.

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
