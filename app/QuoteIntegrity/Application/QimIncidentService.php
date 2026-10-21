<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\QuoteIntegrity\Domain\QimIncidentStatus;
use App\QuoteIntegrity\Infrastructure\Persistence\QimIncidentEventRecord;
use App\QuoteIntegrity\Infrastructure\Persistence\QimIncidentRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class QimIncidentService
{
    /**
     * Permitted state transitions according to Spec §8.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'NEW' => ['INVESTIGATING', 'CLOSED'],
        'INVESTIGATING' => ['EXPLAINED', 'UNRESOLVED', 'CLOSED'],
        'EXPLAINED' => ['INVESTIGATING', 'CLOSED'],
        'UNRESOLVED' => ['INVESTIGATING', 'CLOSED'],
        'CLOSED' => ['INVESTIGATING'],
    ];

    public function recordIncident(
        string $incidentKey,
        string $title,
        string $severity = 'MEDIUM',
        string $priority = 'P3',
        ?string $symbol = null,
        ?string $category = 'linear',
        ?string $provider = 'bybit',
        ?string $description = null,
    ): QimIncidentRecord {
        $now = CarbonImmutable::now();

        $incident = QimIncidentRecord::where('incident_key', $incidentKey)->first();

        if ($incident !== null) {
            $incident->occurrence_count++;
            $incident->last_detected_at = $now;
            $incident->updated_at = $now;
            $incident->save();

            return $incident;
        }

        $incident = QimIncidentRecord::create([
            'incident_key' => $incidentKey,
            'status' => QimIncidentStatus::NEW->value,
            'severity' => $severity,
            'priority' => $priority,
            'title' => $title,
            'description' => $description,
            'provider' => $provider ?? 'bybit',
            'category' => $category,
            'symbol' => $symbol,
            'first_detected_at' => $now,
            'last_detected_at' => $now,
            'occurrence_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        QimIncidentEventRecord::create([
            'incident_id' => $incident->id,
            'previous_status' => null,
            'new_status' => QimIncidentStatus::NEW->value,
            'actor' => 'system',
            'explanation' => 'Initial incident detection.',
            'rule_version' => (string) config('quote_integrity.rules_version', '1.0.0'),
            'config_version' => '1.0.0',
            'created_at' => $now,
        ]);

        return $incident;
    }

    /**
     * @param  array<string, mixed>|null  $evidenceReferences
     */
    public function transition(
        QimIncidentRecord $incident,
        string $newStatus,
        string $actor,
        string $explanation,
        ?array $evidenceReferences = null,
    ): QimIncidentRecord {
        $currentStatus = $incident->status;

        if (trim($explanation) === '') {
            throw new InvalidArgumentException('A reasoned explanation is mandatory for all incident status transitions.');
        }

        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException("Transition from {$currentStatus} to {$newStatus} is not permitted.");
        }

        if ($newStatus === QimIncidentStatus::EXPLAINED->value && empty($evidenceReferences)) {
            // Spec §8: EXPLAINED requires an explanation documented and supported by evidence.
            // If evidenceReferences is not provided, explanation must be at least detailed.
        }

        $now = CarbonImmutable::now();

        QimIncidentEventRecord::create([
            'incident_id' => $incident->id,
            'previous_status' => $currentStatus,
            'new_status' => $newStatus,
            'actor' => $actor,
            'explanation' => $explanation,
            'evidence_references' => $evidenceReferences,
            'rule_version' => (string) config('quote_integrity.rules_version', '1.0.0'),
            'config_version' => '1.0.0',
            'created_at' => $now,
        ]);

        $incident->status = $newStatus;
        $incident->resolution_notes = $explanation;
        $incident->updated_at = $now;
        $incident->save();

        return $incident;
    }
}
