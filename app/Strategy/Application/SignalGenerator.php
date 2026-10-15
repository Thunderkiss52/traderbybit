<?php

declare(strict_types=1);

namespace App\Strategy\Application;

use App\Strategy\Contracts\SignalRepository;
use App\Strategy\Domain\SignalDecision;
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
            return $this->signals->createIfAbsent($this->engine->evaluate($this->inputs->make($exchange, $category, $symbol, $at)));
        } finally {
            $lock->release();
        }
    }
}
