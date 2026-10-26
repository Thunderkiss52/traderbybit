<?php

declare(strict_types=1);

namespace Tests\Unit\Trading;

use PHPUnit\Framework\TestCase;

final class TradingQueueIsolationTest extends TestCase
{
    public function test_trading_configuration_declares_isolated_queue_names(): void
    {
        $config = require __DIR__.'/../../../config/trading.php';

        self::assertSame('trading-bybit', $config['queues']['bybit']);
        self::assertSame('trading-binance', $config['queues']['binance']);
        self::assertSame('market-ingest', $config['queues']['ingest']);
        self::assertNotSame($config['queues']['bybit'], $config['queues']['binance']);
    }

    public function test_compose_yaml_configures_independent_workers_for_bybit_and_binance(): void
    {
        $composePath = __DIR__.'/../../../compose.yaml';
        self::assertFileExists($composePath);

        $content = (string) file_get_contents($composePath);

        self::assertStringContainsString('queue-bybit:', $content, 'Dedicated queue-bybit worker service must exist in compose.yaml');
        self::assertStringContainsString('queue-binance:', $content, 'Dedicated queue-binance worker service must exist in compose.yaml');
        self::assertStringContainsString('queue:', $content, 'Ingest/default queue worker service must exist in compose.yaml');

        // Extract individual service blocks
        preg_match('/  queue-bybit:\s*\n(.*?)(?=\n  [a-z0-9_-]+:|\Z)/s', $content, $bybitMatch);
        preg_match('/  queue-binance:\s*\n(.*?)(?=\n  [a-z0-9_-]+:|\Z)/s', $content, $binanceMatch);
        preg_match('/  queue:\s*\n(.*?)(?=\n  [a-z0-9_-]+:|\Z)/s', $content, $queueMatch);

        self::assertNotEmpty($bybitMatch[1] ?? null);
        self::assertNotEmpty($binanceMatch[1] ?? null);
        self::assertNotEmpty($queueMatch[1] ?? null);

        // Verify queue-bybit parameters
        self::assertStringContainsString('--queue=trading-bybit', $bybitMatch[1]);
        self::assertStringNotContainsString('trading-binance', $bybitMatch[1], 'Bybit worker must never consume Binance queues');

        // Verify queue-binance parameters
        self::assertStringContainsString('--queue=trading-binance', $binanceMatch[1]);
        self::assertStringNotContainsString('trading-bybit', $binanceMatch[1], 'Binance worker must never consume Bybit queues');

        // Verify default/ingest worker parameters
        self::assertStringContainsString('--queue=market-ingest,default', $queueMatch[1]);
    }

    public function test_vps_resource_limits_are_bounded_for_all_queue_workers(): void
    {
        $content = (string) file_get_contents(__DIR__.'/../../../compose.yaml');

        foreach (['queue', 'queue-bybit', 'queue-binance'] as $queueService) {
            preg_match('/  '.$queueService.':\s*\n(.*?)(?=\n  [a-z0-9_-]+:|\Z)/s', $content, $match);
            self::assertNotEmpty($match[1] ?? null, "Service {$queueService} block must exist");
            self::assertMatchesRegularExpression('/mem_limit:\s*\$\{?[A-Z_]*MEMORY_LIMIT/s', $match[1]);
            self::assertMatchesRegularExpression('/cpus:\s*\$\{?[A-Z_]*CPU_LIMIT/s', $match[1]);
        }
    }
}
