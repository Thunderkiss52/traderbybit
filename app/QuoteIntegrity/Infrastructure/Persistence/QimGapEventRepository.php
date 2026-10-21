<?php

declare(strict_types=1);

namespace App\QuoteIntegrity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Collection;

class QimGapEventRepository
{
    /** @param array<string, mixed> $attributes */
    public function insert(array $attributes): QimGapEventRecord
    {
        return QimGapEventRecord::create($attributes);
    }

    /** @return Collection<int, QimGapEventRecord> */
    public function findByDetectionId(string $detectionId): Collection
    {
        return QimGapEventRecord::where('detection_id', $detectionId)
            ->orderBy('id')
            ->get();
    }
}
