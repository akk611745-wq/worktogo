<?php
/**
 * AgentPeople.php — WorkToGo Agent Layer :: "my people" store (Phase 8)
 * ----------------------------------------------------------------------------
 * A per-user store of saved recipients (sister, friend, papa — name/phone/
 * address/relation), so a user can order/gift FOR them by talking. It is the
 * agent's own table (agent_people), keyed to the internal user id — the same
 * pattern as AgentMemory / agent_reminders.
 *
 * ══ THE WALL RULE ════════════════════════════════════════════════════════════
 * Recipient PII lives ENTIRELY inside our system and is NEVER sent to the AI:
 *   • tools resolve a person by an internal id, never by handing the AI a name/
 *     number/address;
 *   • names() feeds the privacy wall the saved contact NAMES so the wall scrubs
 *     them out of the user's message before anything leaves;
 *   • only anonymized hints (e.g. "ordering_for_contact") may cross the wall.
 *
 * Fully defensive: a missing table degrades to "no people" — never a broken turn.
 */

declare(strict_types=1);

final class AgentPeople
{
    private static ?bool $tableExists = null;

    public function __construct(private readonly ?PDO $db = null) {}

    /** @return array<int,array> the user's saved people (most-recent first) */
    public function list(int $userId): array
    {
        if (!$this->hasTable() || $userId <= 0) {
            return [];
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT id, name, phone, relation, address_text, city, lat, lng
                   FROM agent_people WHERE user_id = ? ORDER BY updated_at DESC LIMIT 50"
            );
            $stmt->execute([$userId]);
            return array_map([$this, 'shape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function get(int $userId, int $id): ?array
    {
        if (!$this->hasTable() || $userId <= 0 || $id <= 0) {
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT id, name, phone, relation, address_text, city, lat, lng
                   FROM agent_people WHERE id = ? AND user_id = ? LIMIT 1"
            );
            $stmt->execute([$id, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->shape($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Save a person (ownership enforced by user_id). Returns the new id, or 0. */
    public function add(int $userId, array $data): int
    {
        if (!$this->hasTable() || $userId <= 0) {
            return 0;
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return 0;
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO agent_people (user_id, name, phone, relation, address_text, city, lat, lng, created_at, updated_at)
                 VALUES (:uid, :name, :phone, :rel, :addr, :city, :lat, :lng, NOW(), NOW())"
            );
            $stmt->execute([
                ':uid'   => $userId,
                ':name'  => mb_substr($name, 0, 120),
                ':phone' => $this->clip($data['phone'] ?? null, 20),
                ':rel'   => $this->clip($data['relation'] ?? null, 40),
                ':addr'  => $this->clip($data['address_text'] ?? null, 400),
                ':city'  => $this->clip($data['city'] ?? null, 120),
                ':lat'   => is_numeric($data['lat'] ?? null) ? (float) $data['lat'] : null,
                ':lng'   => is_numeric($data['lng'] ?? null) ? (float) $data['lng'] : null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { Logger::warning('agent.people add skipped', ['error' => $e->getMessage()]); }
            return 0;
        }
    }

    /** Resolve a person by relation or name (for "behen ke liye…"). First match. */
    public function findByTerm(int $userId, string $term): ?array
    {
        $term = trim($term);
        if (!$this->hasTable() || $userId <= 0 || $term === '') {
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT id, name, phone, relation, address_text, city, lat, lng
                   FROM agent_people
                  WHERE user_id = ? AND (relation LIKE :t OR name LIKE :t)
                  ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute([$userId, ':t' => '%' . $term . '%']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->shape($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Saved contact NAMES for the privacy wall to scrub out of the message. */
    public function names(int $userId): array
    {
        $names = [];
        foreach ($this->list($userId) as $p) {
            if (!empty($p['name'])) { $names[] = $p['name']; }
        }
        return $names;
    }

    // ── internals ────────────────────────────────────────────────────────────────
    private function shape(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'name'         => $r['name'],
            'phone'        => $r['phone'] ?? null,
            'relation'     => $r['relation'] ?? null,
            'address_text' => $r['address_text'] ?? null,
            'city'         => $r['city'] ?? null,
            'lat'          => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng'          => $r['lng'] !== null ? (float) $r['lng'] : null,
        ];
    }
    private function clip($v, int $len): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $len);
    }
    private function hasTable(): bool
    {
        if (self::$tableExists !== null) { return self::$tableExists; }
        if (!$this->db instanceof PDO) { return self::$tableExists = false; }
        try {
            $stmt = $this->db->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_people'"
            );
            return self::$tableExists = ((int) $stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$tableExists = false;
        }
    }
}
