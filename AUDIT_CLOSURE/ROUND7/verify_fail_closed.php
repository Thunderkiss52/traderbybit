<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/app/Strategy/Domain/SignalQuality.php';
require $root.'/app/PaperTrading/Domain/TradeRiskClass.php';
require $root.'/app/PaperTrading/Domain/QualityRiskGateDecision.php';
require $root.'/app/PaperTrading/Application/PreExecutionQualityRiskGate.php';
require $root.'/app/Research/Application/LearningFeatureStoreService.php';
require $root.'/app/Research/Application/LearningPredictorService.php';
require $root.'/app/Research/Application/ResearchJobService.php';
require $root.'/app/Trading/Application/TradingQueueResolver.php';

use App\PaperTrading\Application\PreExecutionQualityRiskGate;
use App\PaperTrading\Domain\QualityRiskGateDecision;
use App\PaperTrading\Domain\TradeRiskClass;
use App\Research\Application\LearningFeatureStoreService;
use App\Research\Application\LearningPredictorService;
use App\Research\Application\ResearchJobService;
use App\Strategy\Domain\SignalQuality;
use App\Trading\Application\TradingQueueResolver;

function config(string $key): mixed
{
    return match ($key) {
        'trading.queues.bybit' => 'trading-bybit',
        'trading.queues.binance' => 'trading-binance',
        default => null,
    };
}
function check(bool $condition, string $label): void
{
    static $count = 0;
    if (! $condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    $count++;
    echo "PASS {$count}: {$label}\n";
}
$gate = new PreExecutionQualityRiskGate;
$base = [
    'signal_quality' => SignalQuality::High,
    'trade_risk' => TradeRiskClass::Low,
    'side' => 'BUY',
];
check($gate->evaluate($base)['decision'] === QualityRiskGateDecision::InsufficientEvidence, 'no historical edge is not a trade');
check($gate->evaluate($base + ['expected_net_edge' => 1.0, 'sample_size' => 10, 'cost_drag_percent' => 10.0])['allowed'] === false, 'missing status cannot enable entry');
$valid = $base + ['expected_net_edge' => 1.0, 'sample_size' => 10, 'cost_drag_percent' => 10.0, 'evidence_status' => 'VALIDATED'];
check($gate->evaluate($valid)['allowed'] === true, 'scoped validated high/low can pass');
check($gate->evaluate(array_replace($valid, ['evidence_status' => 'STALE']))['allowed'] === false, 'stale evidence blocked');
check($gate->evaluate(array_replace($valid, ['sample_size' => 9]))['allowed'] === false, 'inadequate sample blocked');
check($gate->evaluate(array_replace($valid, ['expected_net_edge' => -0.2]))['allowed'] === false, 'negative edge blocked');
check($gate->evaluate(array_replace($valid, ['cost_drag_percent' => null]))['allowed'] === false, 'missing cost drag blocked');
check($gate->evaluate(array_replace($valid, ['cost_drag_percent' => 120]))['decision'] === QualityRiskGateDecision::Rejected, 'costs exceed TP1 reward');
check($gate->evaluate(array_replace($valid, ['signal_quality' => SignalQuality::Low]))['decision'] === QualityRiskGateDecision::ObservationOnly, 'low quality observation only');
check($gate->evaluate(array_replace($valid, ['trade_risk' => TradeRiskClass::High]))['allowed'] === false, 'high risk blocked');
check(LearningFeatureStoreService::rsiFromAverages(5, 0) === 100.0, 'RSI all gains');
check(LearningFeatureStoreService::rsiFromAverages(0, 5) === 0.0, 'RSI all losses');
check(LearningFeatureStoreService::rsiFromAverages(0, 0) === 50.0, 'RSI flat');
check(LearningFeatureStoreService::rsiFromAverages(1, 1) === 50.0, 'RSI balanced');
check(LearningFeatureStoreService::intervalMinutes('15') === 15, '15 minute format');
check(LearningFeatureStoreService::intervalMinutes('1h') === 60, '1 hour format');
check(LearningFeatureStoreService::intervalMinutes('foo') === null, 'bad timeframe rejected');
check(TradingQueueResolver::forExchange('bybit') === 'trading-bybit', 'Bybit dedicated queue');
check(TradingQueueResolver::forExchange('okx') === 'default', 'new exchange uses shared staffed queue');
check(TradingQueueResolver::forExchange('binance') === 'trading-binance', 'Binance dedicated queue');
$source = new ResearchJobService;
check($source->resolveExchangeProvenance(['exchange' => 'OKX', 'payload' => ['dataset_exchange' => 'okx']]) === 'okx', 'OKX provenance');
try {
    $source->resolveExchangeProvenance(['exchange' => 'bybit', 'payload' => ['exchange_id' => 'binance']]);
    check(false, 'conflicting provenance blocked');
} catch (InvalidArgumentException) {
    check(true, 'conflicting provenance blocked');
}
$nameA = LearningPredictorService::scopedModelName('TP_BEFORE_SL', 'BTCUSDT', 'bybit', 'baseline', '15', 'BUY');
$nameB = LearningPredictorService::scopedModelName('TP_BEFORE_SL', 'BTCUSDT', 'okx', 'baseline', '15', 'SELL');
check($nameA !== $nameB, 'model names isolate exchange and side');
check(strlen($nameA) <= 64, 'model name fits database column');

check($gate->evaluate($base + ['require_validated_edge' => false])['allowed'] === false, 'legacy unchecked override cannot bypass edge requirement');
check(LearningPredictorService::scopedModelName('TP_BEFORE_SL', 'BTCUSDT', 'bybit', 'baseline', '15', 'BUY', 'linear', '1.2.0') !== LearningPredictorService::scopedModelName('TP_BEFORE_SL', 'BTCUSDT', 'bybit', 'baseline', '15', 'BUY', 'linear', '1.3.0'), 'versioned learning identity isolates changes');
