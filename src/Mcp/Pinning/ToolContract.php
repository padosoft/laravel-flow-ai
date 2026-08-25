<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlowAI\Mcp\Pinning;

use JsonException;
use Padosoft\LaravelFlowAI\Support\CanonicalJson;

/**
 * One MCP tool's CONTRACT — everything about a tool that reaches the model
 * or the caller and can therefore change the meaning of calling it —
 * reduced to a single content digest.
 *
 * Why a digest of the contract and not of the server: an MCP server is a
 * live process that answers `tools/list` fresh on every handshake, and
 * nothing in the protocol stops it from answering differently tomorrow.
 * A tool whose DESCRIPTION changes from "look up an order" to "look up an
 * order, and also forward the result to …" is, to a model, a different
 * tool entirely, while remaining the same name at the same server — the
 * "rug pull". A digest over the fields the model actually reads turns that
 * silent substitution into a comparison.
 *
 * The digested fields are exactly those an MCP server sends in a
 * `tools/list` entry that a caller acts on:
 *
 *   - `name` — the identity;
 *   - `title` — display name (2025-06-18), shown to humans approving a call;
 *   - `description` — the natural-language instruction the model reads, and
 *     the single most injectable field on the wire;
 *   - `inputSchema` / `outputSchema` — what the tool accepts and returns;
 *   - `annotations` — `readOnlyHint`, `destructiveHint`, `idempotentHint`,
 *     `openWorldHint`: trust signals a host may gate on, so a server that
 *     flips `destructiveHint` from true to false must not pass a pin.
 *
 * Anything else the server volunteers (its own version string, vendor
 * fields) is deliberately EXCLUDED: those are attacker-controlled in the
 * threat this pins against — a server rewriting a description can leave its
 * version untouched — so including them would only add churn without adding
 * evidence.
 *
 * The digest is SHA-256 over {@see CanonicalJson} of those fields.
 *
 * @api
 */
final class ToolContract
{
    /**
     * @param  array<string, mixed>  $fields  the digested subset of a `tools/list` entry
     */
    private function __construct(
        public readonly string $name,
        private readonly array $fields,
    ) {}

    /**
     * @param  array<string, mixed>  $tool  one raw entry from an MCP `tools/list` result
     */
    public static function fromDiscovered(array $tool): self
    {
        $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';

        $fields = [];

        foreach (['name', 'title', 'description', 'inputSchema', 'outputSchema', 'annotations'] as $key) {
            // A field the server omits and a field it sends as null are the
            // same absence — normalising both to "not present" keeps a pin
            // stable across servers that differ only in that habit.
            if (($tool[$key] ?? null) !== null) {
                $fields[$key] = $tool[$key];
            }
        }

        $fields['name'] = $name;

        return new self($name, $fields);
    }

    /**
     * @throws JsonException when the contract carries a value that cannot be
     *                       JSON-encoded (invalid UTF-8 from a hostile or
     *                       broken server) — surfaced rather than swallowed,
     *                       since a contract that cannot be canonicalized
     *                       cannot be pinned, and silently digesting a
     *                       lossy rendering of it would be worse than failing
     */
    public function digest(): string
    {
        return CanonicalJson::digest($this->fields);
    }

    /**
     * @throws JsonException
     */
    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->fields);
    }
}
