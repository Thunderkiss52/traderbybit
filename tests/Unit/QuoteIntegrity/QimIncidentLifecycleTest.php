<?php

declare(strict_types=1);

namespace Tests\Unit\QuoteIntegrity;

use App\QuoteIntegrity\Application\QimIncidentService;
use App\QuoteIntegrity\Domain\QimIncidentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class QimIncidentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private QimIncidentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['quote_integrity.enabled' => true]);
        $this->service = new QimIncidentService;
    }

    public function test_initial_incident_starts_in_new_status_with_audit_event(): void
    {
        $incident = $this->service->recordIncident(
            incidentKey: 'inc-test-1',
            title: 'Latency Spike on BTCUSDT',
            severity: 'HIGH',
            priority: 'P2',
            symbol: 'BTCUSDT',
            description: 'Observed WS latency exceeding 5s',
        );

        $this->assertSame(QimIncidentStatus::NEW->value, $incident->status);
        $this->assertSame(1, $incident->occurrence_count);

        $this->assertCount(1, $incident->events);
        $this->assertNull($incident->events[0]->previous_status);
        $this->assertSame(QimIncidentStatus::NEW->value, $incident->events[0]->new_status);
        $this->assertSame('system', $incident->events[0]->actor);
    }

    public function test_valid_lifecycle_transitions_are_append_only(): void
    {
        $incident = $this->service->recordIncident(
            incidentKey: 'inc-test-2',
            title: 'REST/WS Channel Inconsistency',
            severity: 'HIGH',
            priority: 'P2',
            symbol: 'BTCUSDT',
        );

        // 1. NEW -> INVESTIGATING
        $incident = $this->service->transition(
            incident: $incident,
            newStatus: QimIncidentStatus::INVESTIGATING->value,
            actor: 'qa_lead',
            explanation: 'Initiating forensic inspection of raw capture payloads.',
        );
        $this->assertSame(QimIncidentStatus::INVESTIGATING->value, $incident->status);

        // 2. INVESTIGATING -> EXPLAINED
        $incident = $this->service->transition(
            incident: $incident,
            newStatus: QimIncidentStatus::EXPLAINED->value,
            actor: 'senior_backend',
            explanation: 'Discrepancy verified to be upstream Bybit REST revision after funding settlement.',
            evidenceReferences: ['file_uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479'],
        );
        $this->assertSame(QimIncidentStatus::EXPLAINED->value, $incident->status);

        // 3. EXPLAINED -> CLOSED
        $incident = $this->service->transition(
            incident: $incident,
            newStatus: QimIncidentStatus::CLOSED->value,
            actor: 'operator',
            explanation: 'Incident closed; documented in post-mortem report.',
        );
        $this->assertSame(QimIncidentStatus::CLOSED->value, $incident->status);

        // Check full append-only audit trail: initial + 3 transitions = 4 events
        $incident->refresh();
        $this->assertCount(4, $incident->events);
        $this->assertSame(QimIncidentStatus::NEW->value, $incident->events[0]->new_status);
        $this->assertSame(QimIncidentStatus::INVESTIGATING->value, $incident->events[1]->new_status);
        $this->assertSame(QimIncidentStatus::EXPLAINED->value, $incident->events[2]->new_status);
        $this->assertSame(QimIncidentStatus::CLOSED->value, $incident->events[3]->new_status);
    }

    public function test_rejects_transition_without_explanation(): void
    {
        $incident = $this->service->recordIncident(
            incidentKey: 'inc-test-3',
            title: 'Stale Observation',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A reasoned explanation is mandatory');

        $this->service->transition(
            incident: $incident,
            newStatus: QimIncidentStatus::INVESTIGATING->value,
            actor: 'operator',
            explanation: '   ',
        );
    }

    public function test_rejects_illegal_state_transition(): void
    {
        $incident = $this->service->recordIncident(
            incidentKey: 'inc-test-4',
            title: 'Direct transition attempt',
        );

        // NEW cannot jump directly to EXPLAINED without INVESTIGATING
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from NEW to EXPLAINED is not permitted.');

        $this->service->transition(
            incident: $incident,
            newStatus: QimIncidentStatus::EXPLAINED->value,
            actor: 'operator',
            explanation: 'Attempting invalid direct transition',
        );
    }
}
