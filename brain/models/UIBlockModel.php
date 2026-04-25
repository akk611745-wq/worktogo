<?php

/**
 * BrainCore - UIBlockModel
 *
 * UI BLOCK CONTROL SYSTEM
 *
 * ─── WHAT THIS DOES ──────────────────────────────────────────
 *
 *   Translates a decision (banner, boost_category, action)
 *   into a structured "blocks" array for the frontend.
 *
 *   Brain never controls layout or CSS.
 *   Brain only sends JSON instructions — frontend renders.
 *
 * ─── BLOCK TYPES ─────────────────────────────────────────────
 *
 *   banner    → { type: "banner",   value: "evening_offer" }
 *   category  → { type: "category", value: "electronics"  }
 *   products  → { type: "products", limit: 10             }
 *   popup     → { type: "popup",    value: "promo_code"   }
 *   notice    → { type: "notice",   value: "Free delivery" }
 *
 * ─── SAFE OVERRIDE SYSTEM ────────────────────────────────────
 *
 *   Admin controls each block via brain_ui_settings.
 *   If a block is disabled (is_enabled = 0), it is NEVER
 *   included in the response — regardless of what the rule said.
 *
 *   This is the "safe override" guarantee:
 *     Rule says: show banner
 *     Admin says: banner disabled
 *     Result: banner NOT sent. Admin always wins.
 *
 * ─── DEVICE CONTEXT ──────────────────────────────────────────
 *
 *   Settings are resolved per device_type.
 *   Device-specific row beats universal (NULL) row.
 *
 *   mobile  → fewer products (max_items = 4)
 *   desktop → more products  (max_items = 16)
 *
 * ─── HOW SETTINGS ARE RESOLVED ───────────────────────────────
 *
 *   For each block_type, we load two potential rows:
 *     1. Universal row (device_type IS NULL)
 *     2. Device-specific row (device_type = 'mobile')
 *
 *   Device-specific row WINS on max_items + is_enabled.
 *   If no device-specific row exists → universal row applies.
 *   If no universal row exists → built-in safe defaults apply.
 *
 * ─── PUBLIC INTERFACE ────────────────────────────────────────
 *
 *   UIBlockModel::build(
 *       array  $decision,
 *       string $clientId,
 *       string $deviceType
 *   ): array
 *     → Returns ordered blocks array. Called by DecisionController.
 *
 *   UIBlockModel::getSettings(string $clientId, string $deviceType): array
 *     → Returns resolved settings map for all block types.
 *       Used by admin dashboard to show current config.
 */

class UIBlockModel
{
    // ── All known block types, in default display order ────────
    // Order here is the fallback; sort_order from DB overrides it.
    private const BLOCK_TYPES = ['banner', 'category', 'products', 'popup', 'notice'];

    // ── Safe defaults if no DB row exists for a block ──────────
    // Admin must explicitly configure settings — these are the
    // conservative fallback values Brain uses when nothing is stored.
    private const DEFAULTS = [
        'banner'   => ['max_items' => 1,  'is_enabled' => 1, 'sort_order' => 1],
        'category' => ['max_items' => 5,  'is_enabled' => 1, 'sort_order' => 2],
        'products' => ['max_items' => 10, 'is_enabled' => 1, 'sort_order' => 3],
        'popup'    => ['max_items' => 1,  'is_enabled' => 1, 'sort_order' => 4],
        'notice'   => ['max_items' => 1,  'is_enabled' => 1, 'sort_order' => 5],
    ];

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Build blocks array from a decision
    // ─────────────────────────────────────────────────────────────

    /**
     * Build the UI blocks array from a resolved decision.
     *
     * This is the main entry point called by DecisionController.
     *
     * Flow:
     *   1. Load resolved settings for client + device (with device override)
     *   2. For each enabled block type, check if decision has relevant data
     *   3. Build the block with correct value + limit from settings
     *   4. Sort by sort_order
     *   5. Return clean array
     *
     * @param  array  $decision    Resolved decision from DecisionModel::resolve()
     * @param  string $clientId
     * @param  string $deviceType  'mobile' | 'tablet' | 'desktop'
     * @return array               Ordered blocks array
     */
    public static function build(
        array  $decision,
        string $clientId,
        string $deviceType
    ): array {
        // ── 1. Load admin settings (device-aware) ─────────────
        $settings = self::resolveSettings($clientId, $deviceType);

        $blocks = [];

        // ── 2. Banner block ────────────────────────────────────
        // Emit if: decision has a 'banner' value AND block is enabled
        $bannerSetting = $settings['banner'];
        if ($bannerSetting['is_enabled'] && !empty($decision['banner'])) {
            $blocks[] = [
                'type'       => 'banner',
                'value'      => $decision['banner'],
                'sort_order' => $bannerSetting['sort_order'],
            ];
        }

        // ── 3. Category block ──────────────────────────────────
        // Emit if: decision has a boost_category AND block is enabled
        $catSetting = $settings['category'];
        if ($catSetting['is_enabled'] && !empty($decision['boost_category'])) {
            $blocks[] = [
                'type'       => 'category',
                'value'      => $decision['boost_category'],
                'sort_order' => $catSetting['sort_order'],
            ];
        }

        // ── 4. Products block ──────────────────────────────────
        // Always emitted if enabled (frontend fetches products independently).
        // limit is set from admin settings — differs by device.
        $prodSetting = $settings['products'];
        if ($prodSetting['is_enabled']) {
            $block = [
                'type'       => 'products',
                'limit'      => (int) $prodSetting['max_items'],
                'sort_order' => $prodSetting['sort_order'],
            ];
            // If decision specifies a category, attach it as a filter hint
            if (!empty($decision['boost_category'])) {
                $block['category_filter'] = $decision['boost_category'];
            }
            $blocks[] = $block;
        }

        // ── 5. Popup block ─────────────────────────────────────
        // Only emitted if action = "show_popup" AND block is enabled
        $popupSetting = $settings['popup'];
        if ($popupSetting['is_enabled'] && ($decision['action'] ?? '') === 'show_popup') {
            $blocks[] = [
                'type'       => 'popup',
                'value'      => $decision['banner'] ?? 'default_popup',
                'sort_order' => $popupSetting['sort_order'],
            ];
        }

        // ── 6. Notice block ────────────────────────────────────
        // Emitted if decision has a 'notice' field AND block is enabled.
        // A rule can set notice via action_exp: "notice: Free delivery today"
        $noticeSetting = $settings['notice'];
        if ($noticeSetting['is_enabled'] && !empty($decision['notice'])) {
            $blocks[] = [
                'type'       => 'notice',
                'value'      => $decision['notice'],
                'sort_order' => $noticeSetting['sort_order'],
            ];
        }

        // ── 7. Sort by sort_order, then strip internal field ──
        usort($blocks, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        // Remove sort_order from output (internal use only)
        return array_map(function (array $block): array {
            unset($block['sort_order']);
            return $block;
        }, $blocks);
    }

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Get resolved settings (for admin dashboard)
    // ─────────────────────────────────────────────────────────────

    /**
     * Return resolved settings for all block types for a client + device.
     *
     * "Resolved" means device-specific row has already beaten universal row.
     * This is what DecisionController shows in debug context.
     *
     * @param  string $clientId
     * @param  string $deviceType
     * @return array  block_type => [max_items, is_enabled, sort_order, source]
     */
    public static function getSettings(string $clientId, string $deviceType): array
    {
        return self::resolveSettings($clientId, $deviceType);
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE: Load + resolve settings from DB
    // ─────────────────────────────────────────────────────────────

    /**
     * Load settings from brain_ui_settings for this client + device.
     *
     * Device override logic:
     *   1. Fetch ALL rows for this client (universal + device-specific)
     *   2. For each block_type, start with universal row (device_type IS NULL)
     *   3. If a device-specific row exists, it overrides max_items + is_enabled
     *   4. If neither exists, fall back to self::DEFAULTS
     *
     * @param  string $clientId
     * @param  string $deviceType  'mobile' | 'tablet' | 'desktop'
     * @return array  block_type => resolved config
     */
    private static function resolveSettings(string $clientId, string $deviceType): array
    {
        $db  = getDB();

        // Fetch all rows for this client — both universal and device-specific
        $sql = "
            SELECT block_type, max_items, is_enabled, device_type, sort_order
            FROM   brain_ui_settings
            WHERE  client_id  = :client_id
            AND    (device_type IS NULL OR device_type = :device_type)
            ORDER  BY
                -- NULL (universal) rows first, then device-specific
                -- When we process, device-specific will overwrite universal
                CASE WHEN device_type IS NULL THEN 0 ELSE 1 END ASC
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':client_id'   => $clientId,
            ':device_type' => $deviceType,
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // ── Build resolved map ────────────────────────────────
        // Start with safe defaults for every block type.
        // Universal rows overwrite defaults.
        // Device-specific rows overwrite universal rows.
        $resolved = self::DEFAULTS;

        foreach ($rows as $row) {
            $type = $row['block_type'];

            if (!isset($resolved[$type])) {
                continue;
            }

            if ($row['device_type'] === null) {
                // Universal row — overwrite defaults
                $resolved[$type] = [
                    'max_items'  => (int) $row['max_items'],
                    'is_enabled' => (bool) $row['is_enabled'],
                    'sort_order' => (int) $row['sort_order'],
                    'source'     => 'db_universal',
                ];
            } else {
                // Device-specific row — wins over universal
                $resolved[$type] = [
                    'max_items'  => (int) $row['max_items'],
                    'is_enabled' => (bool) $row['is_enabled'],
                    'sort_order' => (int) $row['sort_order'],
                    'source'     => 'db_device_' . $row['device_type'],
                ];
            }
        }

        return $resolved;
    }
}
