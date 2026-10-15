<?php

declare(strict_types=1);

namespace App\PaperTrading\Application;

use App\PaperTrading\Domain\AccountStatus;
use App\PaperTrading\Domain\RiskAssessment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final readonly class RiskEngine
{
    public function __construct(private array $config) {}

    public function assess(array $input): RiskAssessment
    {
        $reasons = [];
        $status = AccountStatus::from((string) $input['account_status']);
        if ($status !== AccountStatus::Active) {
            $reasons[] = 'account_not_active';
        }
        $now = $input['at'];
        if (($input['cooldown_until'] ?? null) instanceof DateTimeImmutable && $input['cooldown_until'] > $now) {
            $reasons[] = 'losing_streak_cooldown';
        }
        if ((int) $input['open_positions'] >= (int) $this->config['portfolio']['maximum_open_positions']) {
            $reasons[] = 'maximum_positions_reached';
        }
        $equity = BigDecimal::of((string) $input['equity']);
        if ($equity->isLessThanOrEqualTo(BigDecimal::zero()) || BigDecimal::of((string) $input['free_capital'])->isNegative()) {
            $reasons[] = 'insufficient_free_capital';
        }
        $riskPercent = BigDecimal::of((string) $input['risk_percent']);
        $classLimit = BigDecimal::of((string) $this->config['risk_by_quality'][(string) $input['quality_class']]);
        if ($riskPercent->isGreaterThan($classLimit)) {
            $reasons[] = 'signal_risk_above_class_limit';
        }
        if (BigDecimal::of((string) $input['total_risk_percent'])->plus($riskPercent)->isGreaterThan(BigDecimal::of((string) $this->config['portfolio']['maximum_total_risk_percent']))) {
            $reasons[] = 'portfolio_risk_limit';
        }
        $maxNotional = $equity->multipliedBy((string) $this->config['portfolio']['maximum_notional_percent'])->dividedBy(100, 24, RoundingMode::HalfEven);
        if (BigDecimal::of((string) ($input['notional_after'] ?? '0'))->isGreaterThan($maxNotional)) {
            $reasons[] = 'portfolio_notional_limit';
        }
        $drawdown = BigDecimal::of((string) $input['drawdown_percent']);
        if (! (bool) ($input['drawdown_override'] ?? false)) {
            if ($drawdown->isGreaterThanOrEqualTo(BigDecimal::of((string) $this->config['drawdown']['halt_percent']))) {
                $reasons[] = 'drawdown_halt';
            } elseif ($drawdown->isGreaterThanOrEqualTo(BigDecimal::of((string) $this->config['drawdown']['block_new_entries_percent']))) {
                $reasons[] = 'drawdown_entry_block';
            }
        }
        foreach ((array) ($input['market_failures'] ?? []) as $failure) {
            $reasons[] = (string) $failure;
        }
        if ((bool) ($input['same_direction_correlated_exposure'] ?? false)) {
            $correlation = $input['correlation'] ?? null;
            if ($correlation === null && $this->config['correlation']['insufficient_data_policy'] === 'reject') {
                $reasons[] = 'correlation_data_insufficient';
            } elseif ($correlation !== null && BigDecimal::of((string) $correlation)->abs()->isGreaterThanOrEqualTo(BigDecimal::of((string) $this->config['correlation']['threshold']))) {
                $reasons[] = 'correlation_limit';
            }
        }
        $reasons = array_values(array_unique($reasons));

        return new RiskAssessment($reasons === [], $reasons, [
            'equity' => (string) $input['equity'], 'open_positions' => (int) $input['open_positions'],
            'total_risk_percent' => (string) $input['total_risk_percent'], 'signal_risk_percent' => (string) $input['risk_percent'],
            'drawdown_percent' => (string) $input['drawdown_percent'], 'correlation' => $input['correlation'] ?? null,
        ]);
    }
}
