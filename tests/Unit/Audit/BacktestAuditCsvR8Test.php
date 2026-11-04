<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\Backtest\BacktestAuditCsv;
use PHPUnit\Framework\TestCase;

final class BacktestAuditCsvR8Test extends TestCase
{
    public function test_spreadsheet_injection_is_escaped_but_negative_financial_values_remain_numbers(): void
    {
        self::assertSame("'=1+1", BacktestAuditCsv::cell('=1+1'));
        self::assertSame("' @SUM(A1:A2)", BacktestAuditCsv::cell(' @SUM(A1:A2)'));
        self::assertSame('-1.25', BacktestAuditCsv::cell('-1.25'));
        self::assertSame('0.00001', BacktestAuditCsv::cell('0.00001'));
        self::assertSame('true', BacktestAuditCsv::cell(true));
        self::assertSame('', BacktestAuditCsv::cell(null));
    }
}
