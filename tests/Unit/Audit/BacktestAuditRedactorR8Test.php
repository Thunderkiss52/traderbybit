<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\Backtest\BacktestAuditRedactor;
use PHPUnit\Framework\TestCase;

final class BacktestAuditRedactorR8Test extends TestCase
{
    public function test_nested_credentials_are_not_exported_in_configuration(): void
    {
        self::assertSame([
            'strategy' => 'trend_pullback',
            'nested' => [
                'api_key' => '[REDACTED]',
                'privateKey' => '[REDACTED]',
                'threshold' => '0.5',
            ],
        ], BacktestAuditRedactor::sanitize([
            'strategy' => 'trend_pullback',
            'nested' => [
                'api_key' => 'sensitive',
                'privateKey' => 'sensitive2',
                'threshold' => '0.5',
            ],
        ]));
    }
}
