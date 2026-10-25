<?php

declare(strict_types=1);

namespace App\Strategy\Research;

use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\SignalSide;
use App\Strategy\Domain\StrategyInput;
use Brick\Math\BigDecimal;

final readonly class BtcEthLeadLagStrategy extends CandidateStrategy
{
    public function id(): string
    {
        return 'btc_eth_lead_lag';
    }

    public function description(): string
    {
        return 'BTC/ETH cross-symbol lead-lag research signal with simulated paper execution.';
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
        $peer = $input->peerEntry;
        if ($peer === null || $peer->bars === []) {
            return $this->skip($input, ['peer_history_unavailable']);
        }
        $peerBars = $peer->bars;
        $peerIndex = count($peerBars) - 1;
        $peerBar = $peerBars[$peerIndex];
        $intervalSeconds = max(60, (int) $peer->interval * 60);
        if ($input->decisionTime->getTimestamp() - $peerBar->closeTime->getTimestamp() > (int) $this->config['max_peer_staleness_bars'] * $intervalSeconds) {
            return $this->skip($input, ['peer_history_stale']);
        }
        $peerLag = (int) $this->config['peer_lag_bars'];
        $localLag = (int) $this->config['local_lag_bars'];
        if ($peerIndex < $peerLag || $index < $localLag) {
            return $this->skip($input, ['insufficient_candidate_history']);
        }
        $peerMove = $this->relativeMove(BigDecimal::of($peerBar->close), BigDecimal::of($peerBars[$peerIndex - $peerLag]->close));
        $localMove = $this->relativeMove(BigDecimal::of($bars[$index]->close), BigDecimal::of($bars[$index - $localLag]->close));
        if ($peerMove === null || $localMove === null) {
            return $this->skip($input, ['peer_or_local_base_price_unavailable']);
        }
        $peerThreshold = BigDecimal::of((string) $this->config['peer_move_threshold']);
        $localThreshold = BigDecimal::of((string) $this->config['local_move_threshold']);
        $side = null;
        $reason = '';
        if ($peerMove->isGreaterThanOrEqualTo($peerThreshold) && $localMove->isLessThanOrEqualTo($localThreshold)) {
            $side = SignalSide::Buy;
            $reason = 'PEER_UP_LOCAL_LAGGING';
        } elseif ($peerMove->isLessThanOrEqualTo($peerThreshold->negated()) && $localMove->isGreaterThanOrEqualTo($localThreshold->negated())) {
            $side = SignalSide::Sell;
            $reason = 'PEER_DOWN_LOCAL_LAGGING';
        }
        if ($side === null) {
            return $this->skip($input, ['no_lead_lag_divergence']);
        }
        $strong = $peerMove->abs()->isGreaterThanOrEqualTo($peerThreshold->multipliedBy(2));
        $peerSymbol = $input->symbol === 'BTCUSDT' ? 'ETHUSDT' : 'BTCUSDT';

        return $this->emit(
            $input,
            $side,
            [$reason, 'peer_move_exceeds_threshold', 'local_move_lags_peer'],
            score: $strong ? 85 : 80,
            risks: [
                'cross_symbol_lead_lag_divergence_risk',
                'peer_staleness_is_bounded_but_asynchrony_can_remain',
            ],
            extraSnapshot: [
                'candidate_features' => [
                    'peer_symbol' => $peerSymbol,
                    'peer_close_time' => $peerBar->closeTime->format('Y-m-d\TH:i:s.v\Z'),
                    'peer_move' => (string) $peerMove,
                    'local_move' => (string) $localMove,
                ],
            ],
        );
    }
}
