<?php

declare(strict_types=1);

namespace Tests\Unit\PaperTrading;

use App\PaperTrading\Application\PaperSizingSettings;
use App\PaperTrading\Application\PositionSizingService;
use App\PaperTrading\Domain\InstrumentConstraints;
use Brick\Math\BigDecimal;
use DomainException;
use PHPUnit\Framework\TestCase;

final class R8SizingSettingsTest extends TestCase
{
    private PositionSizingService $sizing;

    private InstrumentConstraints $limits;

    protected function setUp(): void
    {
        parent::setUp();
        $config = require dirname(__DIR__, 3).'/config/paper.php';
        $this->sizing = new PositionSizingService($config);
        $this->limits = new InstrumentConstraints('0.001', '0.001', '1000', '5');
    }

    public function test_normalize_null_and_risk_percent(): void
    {
        self::assertNull(PaperSizingSettings::normalize(null));
        self::assertSame(['mode' => 'risk_percent'], PaperSizingSettings::normalize(['mode' => 'risk_percent']));
    }

    public function test_normalize_fixed_notional(): void
    {
        $normalized = PaperSizingSettings::normalize(['mode' => 'fixed_notional', 'amount' => '1000.50']);
        self::assertSame([
            'mode' => 'fixed_notional',
            'amount' => '1000.50',
        ], $normalized);
    }

    public function test_normalize_fixed_margin(): void
    {
        $normalized = PaperSizingSettings::normalize(['mode' => 'fixed_margin', 'amount' => '500', 'leverage' => '2']);
        self::assertSame([
            'mode' => 'fixed_margin',
            'amount' => '500',
            'leverage' => '2',
        ], $normalized);
    }

    public function test_normalize_rejects_unsupported_mode(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unsupported PAPER sizing mode.');
        PaperSizingSettings::normalize(['mode' => 'martingale']);
    }

    public function test_normalize_rejects_unexpected_keys(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unexpected PAPER sizing settings.');
        PaperSizingSettings::normalize(['mode' => 'fixed_notional', 'amount' => '100', 'extra' => 'hack']);
    }

    public function test_normalize_rejects_invalid_amount(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid fixed PAPER position amount.');
        PaperSizingSettings::normalize(['mode' => 'fixed_notional', 'amount' => '0']);
    }

    public function test_normalize_rejects_excessive_leverage(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PAPER leverage requires verified 1x–2x setting.');
        PaperSizingSettings::normalize(['mode' => 'fixed_margin', 'amount' => '100', 'leverage' => '5']);
    }

    public function test_calculate_with_fixed_notional(): void
    {
        // 1000 USDT notional at price 100 USDT => quantity 10.000
        // Stop at 99 => loss per unit ~ 1 + costs. Risk ceiling: equity 10000 * 0.50% = 50 USDT.
        // Actual loss is ~ 10 * 1.08 = 10.8 USDT < 50 USDT limit.
        $size = $this->sizing->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '100',
            stop: '99',
            limits: $this->limits,
            executionCosts: null,
            sizingSettings: ['mode' => 'fixed_notional', 'amount' => '1000'],
        );

        self::assertTrue($size->allowed);
        self::assertTrue(BigDecimal::of($size->quantity)->isEqualTo('10'));
        self::assertTrue(BigDecimal::of($size->riskCapital)->isLessThan('50'));
    }

    public function test_calculate_with_fixed_notional_exceeding_risk_ceiling_is_rejected(): void
    {
        // 5000 USDT notional at price 100 => quantity 50. Stop at 95 => loss per unit ~ 5 + costs.
        // Total risk is ~ 50 * 5.08 = 254 USDT > 50 USDT allowed risk.
        $size = $this->sizing->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '100',
            stop: '95',
            limits: $this->limits,
            executionCosts: null,
            sizingSettings: ['mode' => 'fixed_notional', 'amount' => '5000'],
        );

        self::assertFalse($size->allowed);
        self::assertSame('sizing_exceeds_signal_risk_limit', $size->reason);
    }

    public function test_calculate_with_fixed_margin_leverage(): void
    {
        // Margin 500 with leverage 2 => target notional 1000 USDT at price 100 => quantity 10
        $size = $this->sizing->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '100',
            stop: '99',
            limits: $this->limits,
            executionCosts: null,
            sizingSettings: ['mode' => 'fixed_margin', 'amount' => '500', 'leverage' => '2'],
        );

        self::assertTrue($size->allowed);
        self::assertTrue(BigDecimal::of($size->quantity)->isEqualTo('10'));
    }

    public function test_calculate_with_invalid_settings_fails_gracefully(): void
    {
        $size = $this->sizing->calculate(
            equity: '10000',
            riskPercent: '0.50',
            entry: '100',
            stop: '95',
            limits: $this->limits,
            executionCosts: null,
            sizingSettings: ['mode' => 'invalid_mode'],
        );

        self::assertFalse($size->allowed);
        self::assertSame('invalid_sizing_settings', $size->reason);
    }
}
