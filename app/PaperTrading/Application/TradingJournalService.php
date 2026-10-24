<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Infrastructure\Persistence\JournalEventRecord;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

final class TradingJournalService
{
    public function append(string $accountId, ?string $signalId, ?string $positionId, string $type, DateTimeImmutable $at, array $payload, string $idempotencyKey): void
    {
        JournalEventRecord::query()->insertOrIgnore([[
            'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'traderbybit:journal:'.$idempotencyKey)->toString(),
            'paper_account_id' => $accountId, 'exchange_id' => PaperAccountRecord::query()->whereKey($accountId)->value('exchange_id')
                ?? throw new \DomainException('Journal account provenance missing.'), 'signal_id' => $signalId, 'position_id' => $positionId,
            'event_type' => $type, 'occurred_at' => $at, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'idempotency_key' => $idempotencyKey, 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }
}
