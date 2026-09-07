<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\TournamentRules;
use PHPUnit\Framework\TestCase;

class TournamentRulesTest extends TestCase
{
    public function test_public_tournament_publishes_without_teams(): void
    {
        $r = TournamentRules::canPublish(['status' => 'draft', 'visibility' => 'public', 'aceptados' => 0]);
        $this->assertTrue($r['ok']);
    }

    public function test_private_requires_two_accepted(): void
    {
        $r = TournamentRules::canPublish(['status' => 'draft', 'visibility' => 'private', 'aceptados' => 1]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('al menos 2', $r['reason']);

        $r = TournamentRules::canPublish(['status' => 'draft', 'visibility' => 'private', 'aceptados' => 2]);
        $this->assertTrue($r['ok']);
    }

    public function test_non_draft_cannot_publish(): void
    {
        $r = TournamentRules::canPublish(['status' => 'open', 'visibility' => 'public']);
        $this->assertFalse($r['ok']);
    }

    public function test_start_requires_draw(): void
    {
        $r = TournamentRules::canStart(['status' => 'open', 'tiene_draw' => false]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('sorteo', $r['reason']);

        $r = TournamentRules::canStart(['status' => 'open', 'tiene_draw' => true]);
        $this->assertTrue($r['ok']);

        $r = TournamentRules::canStart(['status' => 'draft', 'tiene_draw' => true]);
        $this->assertFalse($r['ok']);
    }

    public function test_finish_requires_final_with_winner(): void
    {
        $r = TournamentRules::canFinish(['status' => 'live', 'final_con_ganador' => false]);
        $this->assertFalse($r['ok']);

        $r = TournamentRules::canFinish(['status' => 'live', 'final_con_ganador' => true]);
        $this->assertTrue($r['ok']);

        $r = TournamentRules::canFinish(['status' => 'finished', 'final_con_ganador' => true]);
        $this->assertFalse($r['ok']);
    }

    public function test_editable_fields_by_status(): void
    {
        $draft = TournamentRules::editableFields('draft');
        $this->assertContains('sport', $draft);
        $this->assertContains('format', $draft);
        $this->assertContains('max_participants', $draft);

        $open = TournamentRules::editableFields('open');
        $this->assertNotContains('sport', $open);
        $this->assertNotContains('format', $open);
        $this->assertNotContains('max_participants', $open);
        $this->assertContains('title', $open);

        $this->assertSame([], TournamentRules::editableFields('live'));
        $this->assertSame([], TournamentRules::editableFields('finished'));
    }

    public function test_filter_editable_fields_discards_blocked(): void
    {
        $filtered = TournamentRules::filterEditableFields('open', [
            'title' => 'Nuevo', 'sport' => 'Fútbol', 'format' => 'grupos', 'max_participants' => 99,
        ]);
        $this->assertSame(['title' => 'Nuevo'], $filtered);

        // ENUM mapping while filtering
        $filtered = TournamentRules::filterEditableFields('draft', ['visibility' => 'publico', 'format' => 'grupos']);
        $this->assertSame('public', $filtered['visibility']);
        $this->assertSame('groups', $filtered['format']);
    }

    public function test_validate_registration(): void
    {
        $base = ['status' => 'open', 'is_individual' => false, 'registration_deadline' => null];

        $r = TournamentRules::validateRegistration(['status' => 'draft'], 1, null, false, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no está abierto', $r['reason']);

        // The organizer can register teams directly in draft (wizard/AG-01)
        $r = TournamentRules::validateRegistration(['status' => 'draft'], 1, null, false, false, true);
        $this->assertTrue($r['ok']);

        $r = TournamentRules::validateRegistration($base + ['is_individual' => true], null, null, false, false);
        $this->assertFalse($r['ok']);

        $r = TournamentRules::validateRegistration($base, null, 5, false, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('por equipos', $r['reason']);

        $r = TournamentRules::validateRegistration($base, 1, 5, false, false);
        $this->assertFalse($r['ok']);

        $r = TournamentRules::validateRegistration($base, 1, null, true, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ya tiene', $r['reason']);

        $r = TournamentRules::validateRegistration($base, 1, null, false, true);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cupo', $r['reason']);

        $r = TournamentRules::validateRegistration(array_merge($base, ['registration_deadline' => date('Y-m-d H:i:s', time() - 3600)]), 1, null, false, false);
        $this->assertFalse($r['ok']);

        $r = TournamentRules::validateRegistration($base, 1, null, false, false);
        $this->assertTrue($r['ok']);
    }

    public function test_can_decide(): void
    {
        $this->assertTrue(TournamentRules::canDecide('pending', 'accepted')['ok']);
        $this->assertTrue(TournamentRules::canDecide('pending', 'rejected')['ok']);
        $this->assertFalse(TournamentRules::canDecide('accepted', 'accepted')['ok']);
        $this->assertFalse(TournamentRules::canDecide('pending', 'cancel')['ok']);
    }
}