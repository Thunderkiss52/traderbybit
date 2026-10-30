<?php

declare(strict_types=1);

namespace App\MarketData\Infrastructure\Persistence;

use App\MarketData\Domain\CandleIntegrityUnavailable;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Independent primary sessions never inherit an ingestion transaction or its snapshot. */
final class CandleIntegrityDatabase
{
    public function connect(): Connection
    {
        $config = DB::connection()->getConfig();
        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new CandleIntegrityUnavailable('PostgreSQL integrity storage required.');
        }
        unset($config['read'], $config['write'], $config['url'], $config['name']);
        $previous = getenv('PGCONNECT_TIMEOUT');
        $connection = null;
        try {
            putenv('PGCONNECT_TIMEOUT=3');
            $connection = app(ConnectionFactory::class)->make($config, 'candle_integrity_writer');
            $connection->setEventDispatcher(app('events'));
            $connection->getPdo();
            $connection->statement("SET statement_timeout = '3000ms'");
            $connection->statement("SET lock_timeout = '1000ms'");

            return $connection;
        } catch (Throwable $e) {
            $connection?->disconnect();
            throw new CandleIntegrityUnavailable('CANDLE_INTEGRITY_UNAVAILABLE: primary integrity connection failed.', previous: $e);
        } finally {
            $previous === false ? putenv('PGCONNECT_TIMEOUT') : putenv('PGCONNECT_TIMEOUT='.$previous);
        }
    }
}
