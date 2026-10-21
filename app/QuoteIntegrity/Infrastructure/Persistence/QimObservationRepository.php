<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class QimObservationRepository
{
    /** @param array<string, mixed> $attributes */
    public function insert(array $attributes): QimObservationRecord
    {
        return QimObservationRecord::create($attributes);
    }

    public function findLatestByLogicalKey(string $logicalKey): ?QimObservationRecord
    {
        return QimObservationRecord::where('logical_key', $logicalKey)
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, QimObservationRecord> */
    public function findAllByLogicalKey(string $logicalKey): Collection
    {
        return QimObservationRecord::where('logical_key', $logicalKey)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{rest: ?QimObservationRecord, ws: ?QimObservationRecord}
     */
    public function findComparablePair(
        string $category,
        string $symbol,
        string $interval,
        CarbonImmutable $candleOpenTime
    ): array {
        $rest = QimObservationRecord::where('channel', 'REST')
            ->where('category', $category)
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('candle_open_time', $candleOpenTime)
            ->orderByDesc('id')
            ->first();

        $ws = QimObservationRecord::where('channel', 'WS')
            ->where('category', $category)
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->where('candle_open_time', $candleOpenTime)
            ->orderByDesc('id')
            ->first();

        return ['rest' => $rest, 'ws' => $ws];
    }
}
