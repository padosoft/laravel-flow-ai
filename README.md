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

```bash
composer require padosoft/laravel-flow-ai
```

## License

Apache-2.0. See [LICENSE](LICENSE).
