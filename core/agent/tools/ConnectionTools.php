<?php
/**
 * ConnectionTools.php — WorkToGo Agent Layer :: connections / "my people" (Phase 8)
 * ----------------------------------------------------------------------------
 * Lets a user order/gift FOR their saved people by talking ("behen ke liye kapde
 * bhej do"). People management is agent-owned (AgentPeople → agent_people);
 * ordering reuses the EXISTING commerce checkout, delivered to the person.
 *
 * Tools (RBAC = customer):
 *   list_people  [read ]  the user's saved recipients (→ people picker)
 *   add_person   [read ]  opens an inline add-person widget
 *   save_person  [read*]  saves a recipient (*light, reversible, no money)
 *   get_person   [read ]  one recipient
 *   order_for    [WRITE]  checkout the current cart, DELIVERED to the person
 *
 * PRIVACY: the recipient's name/phone/address never reach the AI. Tools resolve a
 * person by an internal id; the wall scrubs saved contact names from the message
 * (see AgentPeople::names); only anonymized hints cross. order_for respects the
 * control-room 'commerce' + 'writes' toggles (see AgentPolicy).
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentTool.php';
require_once __DIR__ . '/../AgentPeople.php';

final class ListPeopleTool extends AbstractTool
{
    public function name(): string { return 'list_people'; }
    public function description(): string { return 'List the user\'s saved people (family/friends) to order or gift for.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $people = (new AgentPeople($tc->db))->list($tc->ctx->userId);
        return EngineResult::ok(200, ['people' => $people]);
    }
}

final class GetPersonTool extends AbstractTool
{
    public function name(): string { return 'get_person'; }
    public function description(): string { return 'Get one saved person by id.'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['id' => 'int']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $p = (new AgentPeople($tc->db))->get($tc->ctx->userId, (int) $a['id']);
        return $p ? EngineResult::ok(200, ['person' => $p]) : EngineResult::fail('person not found', 404);
    }
}

/** Opens the inline add-person form (no data leaves; the AI never sees it). */
final class AddPersonTool extends AbstractTool
{
    public function name(): string { return 'add_person'; }
    public function description(): string { return 'Open an inline form to save a new person (name, phone, relation, address).'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return []; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        return EngineResult::ok(200, ['_widget' => [
            'type'        => 'add_person',
            'title'       => 'Kiske liye order karna hai? Unki detail save kijiye',
            'submit_tool' => 'save_person',
            'fields'      => [
                ['key' => 'name',         'q' => 'Naam',                     'kind' => 'text',   'placeholder' => 'e.g. Priya'],
                ['key' => 'relation',     'q' => 'Rishta (relation)',        'kind' => 'text',   'placeholder' => 'e.g. behen'],
                ['key' => 'phone',        'q' => 'Phone (optional)',         'kind' => 'text',   'placeholder' => '10-digit'],
                ['key' => 'address_text', 'q' => 'Address',                  'kind' => 'text',   'placeholder' => 'ghar ka pata'],
                ['key' => 'city',         'q' => 'City (optional)',          'kind' => 'text',   'placeholder' => 'e.g. Haldwani'],
            ],
        ]]);
    }
}

/** A LIGHT write (saving the user's own contact — reversible, no money → no confirm). */
final class SavePersonTool extends AbstractTool
{
    public function name(): string { return 'save_person'; }
    public function description(): string { return 'Save a person to the user\'s people. Needs a name.'; }
    public function mode(): string { return 'read'; }   // light, reversible, no money
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['name' => 'string', 'phone' => 'string?', 'relation' => 'string?', 'address_text' => 'string?', 'city' => 'string?', 'lat' => 'string?', 'lng' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $people = new AgentPeople($tc->db);
        $id = $people->add($tc->ctx->userId, $a);
        if ($id <= 0) {
            return EngineResult::fail('Could not save this person.', 500);
        }
        return EngineResult::ok(201, ['person' => $people->get($tc->ctx->userId, $id)]);
    }
}

/**
 * The gift write: checkout the user's current cart, DELIVERED to a saved person.
 * Reuses the real /api/shop/checkout (COD); the person's coords direct the rider
 * and the recipient is recorded in the order note. The engine does the city guard,
 * inventory locking, ledger and delivery — untouched. The agent never moves money.
 */
final class OrderForTool extends AbstractTool
{
    public function name(): string { return 'order_for'; }
    public function description(): string { return 'Place the current cart as an order delivered to a saved person.'; }
    public function mode(): string { return 'write'; }
    public function allowedRoles(): array { return ['customer']; }
    public function argSpec(): array { return ['person_id' => 'int', 'gift_note' => 'string?']; }

    public function run(ToolContext $tc, array $args): EngineResult
    {
        $a = $this->clean($args);
        if ($miss = $this->missingRequired($a)) {
            return EngineResult::fail('missing required: ' . implode(', ', $miss), 422);
        }
        $person = (new AgentPeople($tc->db))->get($tc->ctx->userId, (int) $a['person_id']);
        if (!$person) {
            return EngineResult::fail('That person is not in your saved list.', 404);
        }
        $note = 'Gift for ' . $person['name']
              . ($person['address_text'] ? ' — deliver to: ' . $person['address_text'] : '')
              . (!empty($a['gift_note']) ? ' | ' . $a['gift_note'] : '');
        $body = ['notes' => mb_substr($note, 0, 400), 'gift' => true];
        if ($person['lat'] !== null && $person['lng'] !== null) {
            $body['drop_lat'] = $person['lat'];
            $body['drop_lng'] = $person['lng'];
        }
        return $tc->engine->post('/api/shop/checkout', $body);
    }
}
