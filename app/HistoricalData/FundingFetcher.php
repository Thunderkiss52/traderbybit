<?php

declare(strict_types=1);

namespace App\HistoricalData;

use App\Exchanges\Binance\BinanceSourceIdentity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

final readonly class FundingFetcher
{
    public function __construct(private OfficialTransport $http) {}

    public function fetch(object $entry, string $directory, callable $tick): array
    {
        $cursor = CarbonImmutable::parse($entry->requested_from)->getTimestampMs();
        $end = CarbonImmutable::parse($entry->requested_to)->getTimestampMs() - 1;
        $out = fopen($directory.'/validated.ndjson', 'wb');
        $hash = hash_init('sha256');
        $count = 0;
        $missing = 0;
        $last = null;
        $pages = [];
        try {
            for ($page = 0; $page < 100; $page++) {
                $url = 'https://fapi.binance.com/fapi/v1/fundingRate?'.http_build_query(['symbol' => $entry->symbol, 'startTime' => $cursor, 'endTime' => $end, 'limit' => 1000]);
                $file = $directory.'/page.json';
                $response = $this->http->fetch($url, $file, 1048576, $tick);
                $pages[] = ['uri' => $url, 'sha256' => $response['sha256']];
                hash_update($hash, $response['sha256']);
                $rows = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
                    throw new AcquisitionFailure('FUNDING_RESPONSE_INVALID');
                }
                foreach ($rows as $row) {
                    if (($row['symbol'] ?? null) !== $entry->symbol || ! is_int($row['fundingTime'] ?? null) || $row['fundingTime'] < $cursor || $row['fundingTime'] > $end || ($last !== null && $row['fundingTime'] <= $last) || ($row['rateType'] ?? 'Regular') !== 'Regular') {
                        throw new AcquisitionFailure('FUNDING_IDENTITY_OR_ORDER_INVALID');
                    }
                    $rate = $row['fundingRate'] ?? null;
                    $mark = $row['markPrice'] ?? null;
                    if (! is_string($rate) || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/D', $rate)) {
                        throw new AcquisitionFailure('FUNDING_RATE_INVALID');
                    }
                    BinanceSourceIdentity::assertPersistable(['rate' => $rate]);
                    if ($mark !== null) {
                        if (! is_string($mark) || ! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$/D', $mark) || ! BigDecimal::of($mark)->isPositive()) {
                            throw new AcquisitionFailure('FUNDING_MARK_INVALID');
                        }BinanceSourceIdentity::assertPersistable(['mark_price' => $mark]);
                    } else {
                        $missing++;
                    }
                    fwrite($out, json_encode(['time' => $row['fundingTime'], 'rate' => $rate, 'mark' => $mark], JSON_THROW_ON_ERROR)."\n");
                    $last = $row['fundingTime'];
                    $count++;
                }
                if (count($rows) < 1000 || $last === $end) {
                    break;
                }if ($last === null || $last < $cursor) {
                    throw new AcquisitionFailure('FUNDING_PAGINATION_STALLED');
                }$cursor = $last + 1;
                $tick();
                if ($page === 99) {
                    throw new AcquisitionFailure('FUNDING_PAGE_BOUND_EXCEEDED');
                }
            }
        } finally {
            fclose($out);
        }
        if ($count === 0) {
            throw new AcquisitionFailure('FUNDING_RECORDS_UNAVAILABLE');
        }

        return ['output' => $directory.'/validated.ndjson', 'rows' => $count, 'duplicates' => 0, 'missing' => 0, 'missing_marks' => $missing, 'sha256' => hash_final($hash), 'pages' => $pages, 'parser' => 'binance-funding-api-ms-v1', 'published_response_complete' => true];
    }
}
