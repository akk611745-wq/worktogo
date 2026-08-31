<?php
/**
 * bootstrap.php — WorkToGo Agent Layer :: scope loader (foundation)
 * ----------------------------------------------------------------------------
 * Requires every agent-scope class in dependency order, so both the HTTP entry
 * (heart/api/agent/index.php) and the headless self-test load the layer with one
 * include. Pure includes — no side effects, no DB, no output.
 *
 * The agent lives entirely under core/agent/ (+ its one HTTP entry). It reuses
 * the never-touch engines only later, through clean adapters — it never edits
 * shopping-engine / video-engine / service-engine / orders.js.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentExceptions.php';
require_once __DIR__ . '/AgentContext.php';
require_once __DIR__ . '/SanitizedPrompt.php';
require_once __DIR__ . '/PiiWall.php';
require_once __DIR__ . '/ai/AiResponse.php';
require_once __DIR__ . '/ai/AiProvider.php';
require_once __DIR__ . '/ai/MockAiAdapter.php';
require_once __DIR__ . '/ai/AnthropicAdapter.php';
require_once __DIR__ . '/ai/OpenAiAdapter.php';
require_once __DIR__ . '/ai/GeminiAdapter.php';
require_once __DIR__ . '/ai/AiProviderManager.php';
require_once __DIR__ . '/AgentOrchestrator.php';

// ── Phase 2: action / tool layer ─────────────────────────────────────────────
require_once __DIR__ . '/tools/EngineClient.php';
require_once __DIR__ . '/tools/AgentTool.php';
require_once __DIR__ . '/tools/ServiceTools.php';
require_once __DIR__ . '/tools/CommerceTools.php';
require_once __DIR__ . '/tools/VendorTools.php';
require_once __DIR__ . '/AgentPeople.php';
require_once __DIR__ . '/tools/ConnectionTools.php';
require_once __DIR__ . '/tools/WidgetTools.php';
require_once __DIR__ . '/tools/ToolRegistry.php';
require_once __DIR__ . '/tools/ConfirmToken.php';
require_once __DIR__ . '/ToolPlanner.php';

// ── Phase 4: memory / personalization ────────────────────────────────────────
require_once __DIR__ . '/AgentMemory.php';

// ── Phase 7: governance / admin control-room policy ───────────────────────────
require_once __DIR__ . '/AgentPolicy.php';

require_once __DIR__ . '/AgentActionOrchestrator.php';
