<?php

declare(strict_types=1);

namespace App\PatternDiscovery\Application;

use App\MarketData\Infrastructure\Persistence\OpenInterestEventRecord;
use App\PaperTrading\Infrastructure\Persistence\MarketFundingEventRecord;
use App\Strategy\Infrastructure\Persistence\MarketContextRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

final class AsOfMarketContextService
{
    public function at(string $exchange, string $category, string $symbol, DateTimeImmutable $at): ?array
    {
        $context = MarketContextRecord::query()->where(compact('exchange', 'category', 'symbol'))
            ->where('observed_at', '<=', $at)->latest('observed_at')->first(['observed_at', 'funding_rate', 'open_interest_change']);
        $funding = MarketFundingEventRecord::query()->where(compact('exchange', 'category', 'symbol'))
            ->where('funding_at', '<=', $at)->latest('funding_at')->first(['funding_at', 'rate']);
        $openInterest = OpenInterestEventRecord::query()->where(compact('exchange', 'category', 'symbol'))
            ->where('observed_at', '<=', $at)->latest('observed_at')->limit(2)->get(['observed_at', 'open_interest']);
        $latestOi = $openInterest->first();
        $previousOi = $openInterest->get(1);
        $oiChange = null;
        if ($latestOi !== null && $previousOi !== null && ! BigDecimal::of($previousOi->open_interest)->isZero()) {
            $oiChange = (string) BigDecimal::of($latestOi->open_interest)->minus($previousOi->open_interest)
                ->dividedBy($previousOi->open_interest, 12, RoundingMode::HalfEven);
        }
        if ($context === null && $funding === null && $latestOi === null) {
            return null;
        }

        return [
            'observed_at' => $context?->observed_at->getTimestamp(),
            'funding_rate' => $funding?->rate ?? $context?->funding_rate,
            'funding_observed_at' => $funding?->funding_at->getTimestamp() ?? $context?->observed_at->getTimestamp(),
            'open_interest_change' => $oiChange ?? $context?->open_interest_change,
            'open_interest_observed_at' => $latestOi?->observed_at->getTimestamp() ?? $context?->observed_at->getTimestamp(),
        ];
    }
}
