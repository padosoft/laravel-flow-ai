# Changelog

All notable changes to `padosoft/laravel-flow-ai` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). From v1.0.0, SemVer applies to source classes annotated `@api`; `@internal` classes may change in any release.

## [Unreleased]

## [1.0.0] — 2026-07-18

First stable release — the agentic AI layer for [`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) (requires core `^2.0`).

### Added

- **LLM prompt node & drivers**: an `ai.llm.prompt` graph node that calls a configured LLM and (optionally) self-repairs a structured-output schema violation by feeding the validation error back to the model and retrying. Pluggable `Llm\` drivers behind a `Contracts\LlmClient` (no real network calls in the test suite — a structural token-sweep test forbids them).
- **MCP client & server** (`Mcp\`): a stdio `McpClient`/`StdioMcpTransport` (tested against a real spawned subprocess) and a `FlowToolServer` that exposes opt-in flows as MCP tools, including a list→invoke→`pending_approval`→poll sequence that surfaces core's human-in-the-loop approval gate. Plus an `ai.mcp.tool` graph node (`Nodes\McpClientNode`, `@api`) that spawns an MCP stdio subprocess and calls a tool from within a flow.
- **Bounded agent node**: an `ai.agent.bounded` node that runs a tool-using agent loop under explicit step/-cost bounds.
- **AI flow builder** (`Builder\FlowBuilderService`): turns a natural-language prompt into an **already-validated** draft `GraphDefinition` (the model picks only from the host's real node catalog; the result runs through core's `GraphValidator` before it is returned — never persisted by the builder).
- **Flow Advisor** (`Advisor\FlowAdvisor`): four **deterministic** (no-LLM, no-network) run-history analyzers — failure hotspots, duration outliers, repeated segments, unused MCP-exposed tools — surfaced as **draft** definition versions (never published) carrying each finding's rationale in `metadata['advisor']`. `flow:suggest` / `flow:improve {flow}` Artisan commands.
- **Guardrails** (`Guardrails\PolicyEngine`): a policy layer that can deny an LLM/agent/MCP action; a denial is folded into the builder/validator error surface.
