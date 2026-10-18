<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Research\Application\ModelAdvisoryService;
use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Domain\SignalDecision;
use App\Strategy\Domain\StrategyInput;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use RuntimeException;

final readonly class SignalGenerator
{
    public function __construct(
        private StrategyInputFactory $inputs,
        private StrategyEngine $engine,
        private SignalRepository $signals,
        private CacheFactory $cache,
        private array $config,
        private ?ModelAdvisoryService $advisory = null,
    ) {}

    public function generate(string $symbol, ?DateTimeImmutable $at = null, string $exchange = 'bybit', string $category = 'linear'): SignalDecision
    {
        $at ??= $this->inputs->latestDecisionTime($exchange, $category, $symbol)
            ?? throw new RuntimeException("No closed 15m candle is available for {$symbol}.");
        $key = implode(':', ['signal', $exchange, $category, $symbol, $at->format('U.u'), $this->config['version']]);
        $lock = $this->cache->lock($key, (int) $this->config['lock_seconds']);
        if (! $lock->get()) {
            throw new RuntimeException("Signal generation is already running for {$symbol} at {$at->format(DATE_ATOM)}.");
        }

        try {
            $input = $this->inputs->make($exchange, $category, $symbol, $at);
            $decision = $this->engine->evaluate($input);

            if ($this->advisory !== null) {
                $features = $this->extractFeatures($input);
                $decision = $this->advisory->advise($decision, $features);
            }

            return $this->signals->createIfAbsent($decision);
        } finally {
            $lock->release();
        }
    }

    private function extractFeatures(StrategyInput $input): array
    {
        $latest = $input->entry->latest();
        if ($latest === null) {
            return [];
        }
        $close = (float) $latest->close;
        $high = (float) $latest->high;
        $low = (float) $latest->low;
        $hlSpread = $close > 0 ? (($high - $low) / $close) * 100 : 0.0;
        $atr = (float) ($latest->atr ?? 0.0);
        $recentVol = $close > 0 ? ($atr / $close) * 100 : 0.0;
        $rsi = (float) ($latest->rsi ?? 50.0);
        $volRatio = (float) ($latest->relativeVolume ?? 1.0);

        return [
            'hl_spread_pct' => $hlSpread,
            'recent_volatility' => $recentVol,
            'rsi_14' => $rsi,
            'volume_ratio' => $volRatio,
        ];
    }
}
