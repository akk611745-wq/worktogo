<?php
/**
 * AgentExceptions.php — WorkToGo Agent Layer (foundation)
 * ----------------------------------------------------------------------------
 * The small, typed exception hierarchy the agent scope throws. Kept in one file
 * so every agent module can depend on the same types without include churn.
 *
 * Design intent:
 *   • PiiLeakException is FAIL-CLOSED. The privacy wall throws it the instant it
 *     detects that outbound text still contains something that looks like PII —
 *     the request is refused, nothing is sent to any provider. A leak is treated
 *     as a hard error, never a warning.
 *   • AgentSecurityException guards the structural invariant that a provider can
 *     only be reached through the wall (a SanitizedPrompt minted anywhere but the
 *     wall throws this).
 *   • AgentDisabledException carries the kill-switch: when the agent is turned off
 *     the caller degrades cleanly (503) — the rest of WorkToGo is unaffected.
 */

declare(strict_types=1);

/** Base type for every agent-scope failure. */
class AgentException extends \RuntimeException {}

/**
 * Thrown when the privacy wall's final tripwire still finds PII in the text that
 * was about to leave. FAIL-CLOSED: the caller must NOT fall back to sending —
 * it must refuse the turn.
 */
class PiiLeakException extends AgentException {}

/**
 * Thrown when the structural wall invariant is violated — e.g. something tried
 * to mint a SanitizedPrompt without going through PiiWall, or hand an unsealed
 * prompt to a provider.
 */
class AgentSecurityException extends AgentException {}

/** Thrown when the agent kill-switch is off (admin-disabled). */
class AgentDisabledException extends AgentException {}
