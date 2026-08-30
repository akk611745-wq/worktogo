<?php
/**
 * ToolRegistry.php — WorkToGo Agent Layer :: the tool allow-list (Phase 2)
 * ----------------------------------------------------------------------------
 * The single source of truth for which tools exist and which ROLE may use each.
 * This is the guardrail that keeps the AI on a leash: the AI is only ever shown
 * the catalog for the caller's role, and the orchestrator will only execute a
 * tool that `isExposedTo()` the caller. A tool the AI names that isn't exposed is
 * refused — the AI cannot invent or reach a capability we didn't expose.
 *
 * Per-role toolset (customer-agent vs vendor-agent) falls straight out of each
 * tool's allowedRoles(); admin sees everything.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';
require_once __DIR__ . '/ServiceTools.php';
require_once __DIR__ . '/CommerceTools.php';
require_once __DIR__ . '/WidgetTools.php';

final class ToolRegistry
{
    /** @var array<string,AgentTool> */
    private array $tools = [];

    public function register(AgentTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AgentTool
    {
        return $this->tools[$name] ?? null;
    }

    /** Is this tool exposed to this role? (RBAC allow-list; admin sees all.) */
    public function isExposedTo(string $name, string $role): bool
    {
        $tool = $this->get($name);
        if ($tool === null) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        return in_array($role, $tool->allowedRoles(), true);
    }

    /** @return AgentTool[] tools this role may use */
    public function toolsForRole(string $role): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AgentTool $t) => $role === 'admin' || in_array($role, $t->allowedRoles(), true)
        ));
    }

    /** Just the names — the coarse capability list the wall may pass outward. */
    public function toolNamesForRole(string $role): array
    {
        return array_map(fn (AgentTool $t) => $t->name(), $this->toolsForRole($role));
    }

    /**
     * The catalog shown to the AI (names + descriptions + arg keys + mode) so it
     * can propose a tool. Public, non-sensitive — safe to include in the prompt.
     */
    public function catalogForRole(string $role): array
    {
        return array_map(fn (AgentTool $t) => [
            'name'        => $t->name(),
            'mode'        => $t->mode(),
            'description' => $t->description(),
            'args'        => $t->argSpec(),
        ], $this->toolsForRole($role));
    }

    /** The default registry with all service-agent tools registered. */
    public static function defaultRegistry(): self
    {
        $r = new self();
        // Customer service-agent
        $r->register(new FindVendorsTool());
        $r->register(new GetVendorProfileTool());
        $r->register(new GetPackagesTool());
        $r->register(new GetExpertVisitPriceTool());
        $r->register(new CreateBookingTool());
        $r->register(new RequestExpertVisitTool());
        // Customer commerce-agent (Phase 5)
        $r->register(new BrowseProductsTool());
        $r->register(new GetProductTool());
        $r->register(new ViewCartTool());
        $r->register(new AddToCartTool());
        $r->register(new ListOrdersTool());
        $r->register(new TrackOrderTool());
        $r->register(new CheckoutTool());
        // Vendor service-agent
        $r->register(new ListMyJobsTool());
        $r->register(new GetMyProfileTool());
        // Inline UI-action widgets (Phase 4)
        $r->register(new UploadWorkPhotoTool());   // vendor only (RBAC)
        $r->register(new VerifyPhoneTool());       // any registered user
        return $r;
    }
}
