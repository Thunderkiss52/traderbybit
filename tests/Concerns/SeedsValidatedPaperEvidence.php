<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\PaperTrading\Application\PaperExecutionEngine;
use App\PaperTrading\Application\TradingJournalService;
use App\PaperTrading\Infrastructure\Persistence\PaperAccountRecord;
use App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord;
use App\Strategy\Infrastructure\Persistence\SignalRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/** Synthetic archived snapshots for isolated tests; never production performance. */
trait SeedsValidatedPaperEvidence
{
    private function seedValidatedPaperEvidence(SignalRecord $signal, ?DateTimeImmutable $latestClose = null, ?DateTimeImmutable $availableAt = null, ?DateTimeImmutable $restatedAt = null, bool $fundingComplete = true): PaperAccountRecord
    {
        $latestClose ??= $signal->decision_time->toDateTimeImmutable()->modify('-1 day')->setTime(12, 5);
        $identity = implode(':', [$signal->exchange, $signal->strategy_id, $signal->strategy_version, $signal->timeframe, $signal->symbol, $signal->side, $latestClose->format(DATE_ATOM)]);
        $accountId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'synthetic-validated-evidence:'.$identity)->toString();
        if ($existing = PaperAccountRecord::query()->find($accountId)) {
            return $existing;
        }

        $engine = app(PaperExecutionEngine::class);
        $outcomes = [];
        $total = BigDecimal::zero();
        for ($index = 0; $index < 10; $index++) {
            $win = $index < 8;
            $exit = $signal->side === 'BUY' ? ($win ? '110' : '99') : ($win ? '90' : '101');
            $gross = $engine->grossPnl($signal->side, '100', $exit, '10');
            $fees = BigDecimal::of($engine->commission('10', '100', $signal->exchange))
                ->plus($engine->commission('10', $exit, $signal->exchange));
            $net = BigDecimal::of($gross)->minus($fees);
            $outcomes[] = ['gross' => $gross, 'fees' => (string) $fees, 'net' => (string) $net, 'win' => $win];
            $total = $total->plus($net);
        }
        $equity = (string) BigDecimal::of('10000')->plus($total);
        $account = PaperAccountRecord::query()->create([
            'id' => $accountId, 'name' => 'SYNTHETIC archived evidence',
            'status' => 'BLOCKED', 'mode' => 'RESEARCH_FORWARD_PAPER', 'is_primary' => false,
            'exchange_id' => $signal->exchange, 'strategy_id' => $signal->strategy_id,
            'strategy_version' => $signal->strategy_version, 'primary_strategy_id' => null,
            'starting_balance' => '10000', 'current_equity' => $equity, 'peak_equity' => $equity,
            'realized_pnl' => (string) $total, 'unrealized_pnl' => '0', 'reserved_risk' => '0',
            'current_drawdown_percent' => '0', 'maximum_drawdown_percent' => '0',
            'created_at' => $latestClose->modify('-1 day'), 'updated_at' => $latestClose,
        ]);

        foreach ($outcomes as $index => $outcome) {
            $closedAt = $latestClose->modify('-'.($index * 2).' minutes');
            $openedAt = $closedAt->modify('-1 minute');
            $prior = SignalRecord::query()->create(array_replace($signal->attributesToArray(), [
                'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'synthetic-evidence-signal:'.$identity.':'.$index)->toString(),
                'candle_id' => null,
                'decision_time' => $openedAt->modify('-5 minutes'), 'candle_close_time' => $openedAt->modify('-5 minutes'),
                'expires_at' => $closedAt, 'created_at' => $openedAt->modify('-5 minutes'), 'updated_at' => $openedAt->modify('-5 minutes'),
            ]));
            $position = VirtualPositionRecord::query()->create([
                'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'synthetic-evidence-position:'.$identity.':'.$index)->toString(),
                'paper_account_id' => $accountId, 'exchange_id' => $signal->exchange, 'signal_id' => $prior->id,
                'symbol' => $signal->symbol, 'side' => $signal->side, 'quality_class' => 'A',
                'status' => $outcome['win'] ? 'CLOSED_TP' : 'CLOSED_SL',
                'entry_price' => '100', 'stop_loss' => $signal->side === 'BUY' ? '99' : '101',
                'take_profit_1' => $signal->side === 'BUY' ? '110' : '90', 'take_profit_2' => $signal->side === 'BUY' ? '120' : '80',
                'initial_quantity' => '10', 'remaining_quantity' => '0', 'qty_step' => '0.001', 'risk_capital' => '50',
                'gross_pnl' => $outcome['gross'], 'fees' => $outcome['fees'], 'funding' => '0', 'net_pnl' => $outcome['net'],
                'r_multiple' => (string) BigDecimal::of($outcome['net'])->dividedBy('50', 10, RoundingMode::HalfEven),
                'unrealized_pnl' => '0', 'funding_complete' => $fundingComplete,
                'opened_at' => $openedAt, 'closed_at' => $closedAt, 'maximum_holding_until' => $closedAt,
                'execution_model' => 'SYNTHETIC_TEST_SNAPSHOT', 'strategy_version' => $signal->strategy_version,
                'risk_version' => 'synthetic-test', 'execution_version' => 'synthetic-test', 'schema_version' => '1.0.0',
                'created_at' => $availableAt ?? $openedAt, 'updated_at' => $restatedAt ?? $availableAt ?? $closedAt,
            ]);
            app(TradingJournalService::class)->append($accountId, $prior->id, $position->id, 'SYNTHETIC_EVIDENCE_SNAPSHOT', $closedAt,
                ['synthetic_test_only' => true, 'gross_pnl' => $outcome['gross'], 'fees' => $outcome['fees'], 'funding' => '0', 'net_pnl' => $outcome['net']],
                'synthetic-evidence:'.$position->id);
        }

        return $account;
    }
}
