<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\BarState;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class MultiTimeframeTrendFollowingStrategy extends CandidateStrategy
{
    public function id(): string
    {
        return 'multi_timeframe_trend_following';
    }

    public function description(): string
    {
        return 'Higher-timeframe EMA trend filter with a lower-timeframe EMA cross or breakout confirmation.';
    }

    public function evaluate(StrategyInput $input): SignalDecision
    {
        if (($invalid = $this->preflight($input)) !== null) {
            return $invalid;
        }
        $bars = $this->decisionBars($input);
        $index = count($bars) - 1;
        if ($index < $this->minimumBars()) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        $trend = $input->trend->latest();
        $fast = $this->decimal($trend?->emaEntry);
        $slow = $this->decimal($trend?->emaFast);
        if ($fast === null || $slow === null) {
            return $this->skip($input, ['htf_trend_unavailable']);
        }
        $trendDirection = $fast->isGreaterThan($slow) ? 'UP' : ($fast->isLessThan($slow) ? 'DOWN' : null);
        if ($trendDirection === null) {
            return $this->skip($input, ['htf_trend_flat']);
        }

        $mode = strtoupper((string) ($this->config['entry_mode'] ?? 'EMA_CROSS'));
        $side = null;
        $reason = '';
        if (in_array($mode, ['EMA_CROSS', 'EMA_CROSS_OR_BREAKOUT'], true)) {
            $emaFast = $this->ema($bars, (int) $this->config['entry_ema_fast']);
            $emaSlow = $this->ema($bars, (int) $this->config['entry_ema_slow']);
            $fastNow = $emaFast[$index] ?? null;
            $slowNow = $emaSlow[$index] ?? null;
            $fastBefore = $emaFast[$index - 1] ?? null;
            $slowBefore = $emaSlow[$index - 1] ?? null;
            if ($fastNow !== null && $slowNow !== null && $fastBefore !== null && $slowBefore !== null) {
                $crossUp = $fastBefore->isLessThanOrEqualTo($slowBefore) && $fastNow->isGreaterThan($slowNow);
                $crossDown = $fastBefore->isGreaterThanOrEqualTo($slowBefore) && $fastNow->isLessThan($slowNow);
                if ($crossUp && $trendDirection === 'UP') {
                    $side = SignalSide::Buy;
                    $reason = 'HTF_UPTREND_LTF_EMA_CROSS_UP';
                } elseif ($crossDown && $trendDirection === 'DOWN') {
                    $side = SignalSide::Sell;
                    $reason = 'HTF_DOWNTREND_LTF_EMA_CROSS_DOWN';
                }
            }
        }
        if ($side === null && in_array($mode, ['BREAKOUT', 'EMA_CROSS_OR_BREAKOUT'], true)) {
            $lookback = (int) $this->config['breakout_lookback'];
            $extremes = $this->priorExtremes($bars, $index - $lookback, $index);
            if ($extremes !== null) {
                [$lowest, $highest] = $extremes;
                $close = BigDecimal::of($bars[$index]->close);
                if ($close->isGreaterThan($highest) && $trendDirection === 'UP') {
                    $side = SignalSide::Buy;
                    $reason = 'HTF_UPTREND_LTF_BREAKOUT_UP';
                } elseif ($close->isLessThan($lowest) && $trendDirection === 'DOWN') {
                    $side = SignalSide::Sell;
                    $reason = 'HTF_DOWNTREND_LTF_BREAKOUT_DOWN';
                }
            }
        }
        if ($side === null) {
            return $this->skip($input, ['no_ltf_confirmation_in_htf_direction']);
        }
        $trendBar = $this->trendBar($input->trend->bars);
        $strong = $trendBar !== null && $this->decimal($trendBar->adx)?->isGreaterThanOrEqualTo('25') === true;

        return $this->emit(
            $input,
            $side,
            [$reason, 'htf_trend_aligned', 'ltf_confirmation'],
            score: $strong ? 85 : 80,
            risks: ['higher_timeframe_trend_can_reverse_between_signal_and_entry', 'ltf_ema_cross_lags_price'],
            extraSnapshot: [
                'candidate_features' => [
                    'htf_trend' => $trendDirection,
                    'htf_ema_20' => (string) $fast,
                    'htf_ema_50' => (string) $slow,
                    'htf_close_time' => $trend?->closeTime->format('Y-m-d\TH:i:s.v\Z'),
                    'entry_mode' => $mode,
                ],
            ],
        );
    }

    /** @param list<BarState> $bars */
    private function trendBar(array $bars): ?BarState
    {
        return $bars === [] ? null : $bars[array_key_last($bars)];
    }
}
