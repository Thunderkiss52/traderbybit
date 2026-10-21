<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Application;

use App\QuoteIntegrity\Infrastructure\Persistence\QimAlertRecord;
use Carbon\CarbonImmutable;

class QimAlertService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOrDeduplicateAlert(
        string $ruleKey,
        string $incidentGroup,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        string $severity,
        ?string $symbol,
        string $title,
        array $payload,
    ): QimAlertRecord {
        $ruleVersion = (string) config('quote_integrity.rules_version', '1.0.0');

        $dedupString = sprintf(
            '%s:%s:%s:%d:%d',
            $ruleVersion,
            $ruleKey,
            $incidentGroup,
            $windowStart->getTimestampMs(),
            $windowEnd->getTimestampMs(),
        );
        $alertKey = hash('sha256', $dedupString);

        $existing = QimAlertRecord::where('alert_key', $alertKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $now = CarbonImmutable::now();

        return QimAlertRecord::create([
            'alert_key' => $alertKey,
            'rule_version' => $ruleVersion,
            'rule_key' => $ruleKey,
            'incident_group' => $incidentGroup,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'severity' => $severity,
            'symbol' => $symbol,
            'title' => $title,
            'payload' => $payload,
            'created_at' => $now,
        ]);
    }
}
