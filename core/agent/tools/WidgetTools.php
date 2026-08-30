<?php
/**
 * WidgetTools.php — WorkToGo Agent Layer :: inline UI-action tools (Phase 4)
 * ----------------------------------------------------------------------------
 * Some actions are completed by the USER inside the chat — uploading a photo,
 * entering an OTP — not by a server call the agent makes on their behalf. For
 * those, the agent's job is to DECIDE the action is needed and OPEN the right
 * widget; the widget then completes against the EXISTING backend endpoint from
 * the client (carrying the user's own JWT). That keeps the "someone is doing my
 * work for me" feel without ever leaving the chat, and reuses the real flows via
 * a clean client-side adapter — no engine is edited.
 *
 * These tools are `read` mode (showing a widget mutates nothing on our side; the
 * real write happens when the widget completes). They are RBAC-scoped by the
 * registry (upload = vendor only). They return a widget DESCRIPTOR in their data
 * under `_widget`; the orchestrator lifts it to the reply's top-level `widget`
 * and the chat UI renders + completes it.
 *
 * A descriptor is public/config-only (paths + labels) — it carries no user data.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';

/** Vendor: open an inline photo-upload widget → POST /api/vendor/work-photo. */
final class UploadWorkPhotoTool extends AbstractTool
{
    public function name(): string { return 'upload_work_photo'; }
    public function description(): string { return 'Open an inline widget for the vendor to upload a work photo to their profile.'; }
    public function allowedRoles(): array { return ['vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        return EngineResult::ok(200, ['_widget' => [
            'type'          => 'upload',
            'title'         => 'Apni kaam ki photo upload kijiye',
            'endpoint'      => '/api/vendor/work-photo', // existing engine route (multipart)
            'field'         => 'photo',
            'accept'        => 'image/*',
            'caption_field' => 'caption',
            'success_text'  => 'Photo aapke profile mein add ho gayi ✓',
        ]]);
    }
}

/** Any registered user: open an inline OTP verification widget → existing OTP flow. */
final class VerifyPhoneTool extends AbstractTool
{
    public function name(): string { return 'verify_phone'; }
    public function description(): string { return 'Open an inline OTP widget to verify the user\'s phone number.'; }
    public function allowedRoles(): array { return ['customer', 'vendor_service', 'vendor_shopping']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        return EngineResult::ok(200, ['_widget' => [
            'type'         => 'otp',
            'title'        => 'Apna number verify karein',
            'send_path'    => '/api/auth/otp/send',   // existing auth routes
            'verify_path'  => '/api/auth/otp/verify',
            'success_text' => 'Number verified ✓',
        ]]);
    }
}
