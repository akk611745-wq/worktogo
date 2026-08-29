<?php
/**
 * AgentTool.php — WorkToGo Agent Layer :: the tool contract (Phase 2)
 * ----------------------------------------------------------------------------
 * A "tool" is a named, safe capability the agent may invoke — and the ONLY way
 * the agent can act. Each tool maps to exactly one EXISTING backend endpoint via
 * the EngineClient; it never touches the DB or an engine's internals directly.
 *
 * Guardrails baked into the contract:
 *   • mode()         — 'read' runs freely; 'write' REQUIRES an explicit user
 *                       confirmation step (enforced by the orchestrator).
 *   • allowedRoles() — RBAC: which roles may use this tool (customer vs vendor).
 *   • argSpec()      — a strict, typed allow-list of arguments. The orchestrator
 *                       filters/casts args through this before the tool runs, so
 *                       neither the AI nor the client can smuggle extra fields
 *                       into a backend call (deny-by-default on arguments).
 *
 * The AI never executes a tool. It only proposes {tool, args}; OUR code validates
 * the tool against the role allow-list, cleans the args, and runs it through the
 * backend, which authorizes and executes.
 */

declare(strict_types=1);

require_once __DIR__ . '/EngineClient.php';
require_once __DIR__ . '/../AgentContext.php';

/** Everything a tool needs to run one call on behalf of one user. */
final class ToolContext
{
    public function __construct(
        public readonly AgentContext $ctx,
        public readonly EngineClient $engine,
        public readonly ?PDO         $db = null,
    ) {}
}

interface AgentTool
{
    /** Canonical tool name the AI/planner references, e.g. 'find_vendors'. */
    public function name(): string;

    /** One-line description for the AI tool catalog (public, non-sensitive). */
    public function description(): string;

    /** 'read' (runs freely) | 'write' (requires user confirmation). */
    public function mode(): string;

    /** Roles allowed to use this tool (RBAC). Admin is always allowed. */
    public function allowedRoles(): array;

    /**
     * Typed argument allow-list: key => 'int'|'string'|'bool', with a trailing
     * '?' marking optional (e.g. 'notes' => 'string?'). Anything not listed is
     * dropped before the tool runs.
     */
    public function argSpec(): array;

    /** Execute against the backend. Args are already cleaned to argSpec. */
    public function run(ToolContext $tc, array $args): EngineResult;
}

/**
 * Shared helpers: strict arg cleaning (cast + drop unknown) and required-field
 * checks. Concrete tools extend this and implement run().
 */
abstract class AbstractTool implements AgentTool
{
    public function mode(): string { return 'read'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return []; }

    /** Cast to declared types and DROP any key not in argSpec (deny-by-default). */
    protected function clean(array $args): array
    {
        $out = [];
        foreach ($this->argSpec() as $key => $type) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $optional = str_ends_with($type, '?');
            $base     = rtrim($type, '?');
            $val      = $args[$key];

            $cast = match ($base) {
                'int'    => (int) $val,
                'bool'   => (bool) $val,
                default  => is_string($val) ? trim($val) : (is_scalar($val) ? (string) $val : ''),
            };
            // Drop empty optionals so they don't override backend defaults.
            if ($optional && ($cast === '' || $cast === 0)) {
                continue;
            }
            $out[$key] = $cast;
        }
        return $out;
    }

    /** Names of required args (no '?') that are absent from $cleaned. */
    protected function missingRequired(array $cleaned): array
    {
        $missing = [];
        foreach ($this->argSpec() as $key => $type) {
            if (!str_ends_with($type, '?') && !array_key_exists($key, $cleaned)) {
                $missing[] = $key;
            }
        }
        return $missing;
    }
}
