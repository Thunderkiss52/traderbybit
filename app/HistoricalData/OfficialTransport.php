<?php

declare(strict_types=1);

namespace App\HistoricalData;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

class OfficialTransport
{
    public function assertUrl(string $url): void
    {
        $p = parse_url($url);
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || ! in_array($p['host'] ?? '', ['data.binance.vision', 'fapi.binance.com'], true) || isset($p['user'],$p['pass']) || isset($p['user']) || isset($p['port']) || isset($p['fragment']) || str_contains($url, '..') || str_contains($url, '\\')) {
            throw new AcquisitionFailure('UNSAFE_SOURCE_URL');
        }
        $path = $p['path'] ?? '';
        $archive = preg_match('~^/data/futures/um/(monthly|daily)/(klines|markPriceKlines|fundingRate)/[A-Z0-9]{3,24}/(?:(?:1m|5m|15m|30m|1h)/)?[A-Z0-9]{3,24}-(?:(?:1m|5m|15m|30m|1h)|fundingRate)-[0-9]{4}-[0-9]{2}(?:-[0-9]{2})?\.zip(?:\.CHECKSUM)?$~D', $path);
        if (($p['host'] === 'data.binance.vision' && (! $archive || isset($p['query']))) || ($p['host'] === 'fapi.binance.com' && $path !== '/fapi/v1/fundingRate')) {
            throw new AcquisitionFailure('UNSAFE_SOURCE_URL');
        }
    }

    public function fetch(string $url, string $target, int $maximum, callable $tick): array
    {
        $this->assertUrl($url);
        $host = parse_url($url, PHP_URL_HOST);
        $reserved = DB::transaction(function () use ($host) {
            $now = CarbonImmutable::now('UTC');
            DB::table('historical_provider_windows')->insertOrIgnore(['host' => $host, 'next_at' => $now]);
            $row = DB::table('historical_provider_windows')->where('host', $host)->lockForUpdate()->first();
            $at = CarbonImmutable::parse($row->next_at)->max($now);
            DB::table('historical_provider_windows')->where('host', $host)->update(['next_at' => $at->addSecond()]);

            return $at;
        }, 3);
        $deadline = microtime(true) + max(0, ($reserved->getTimestampMs() - (int) floor(microtime(true) * 1000)) / 1000);
        while (microtime(true) < $deadline) {
            $tick();
            usleep(100000);
        }
        $addresses = gethostbynamel($host);
        if (! $addresses) {
            throw new AcquisitionFailure('SOURCE_DNS_FAILED', true);
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new AcquisitionFailure('UNSAFE_SOURCE_ADDRESS');
            }
        }
        $options = ['sink' => $target, 'allow_redirects' => false, 'proxy' => '', 'on_headers' => function (ResponseInterface $r) use ($maximum): void {
            if ((int) $r->getHeaderLine('Content-Length') > $maximum) {
                throw new AcquisitionFailure('DOWNLOAD_TOO_LARGE');
            }
        }, 'progress' => function ($total, $downloaded) use ($maximum, $tick): void {
            if ($total > $maximum || $downloaded > $maximum) {
                throw new AcquisitionFailure('DOWNLOAD_TOO_LARGE');
            }$tick();
        }];
        if (! defined('CURLOPT_RESOLVE')) {
            throw new AcquisitionFailure('CURL_REQUIRED_FOR_PINNED_DNS');
        }
        $options['curl'] = [CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]]];
        try {
            $response = Http::timeout((int) config('historical_data.download_timeout'))->connectTimeout(10)->withOptions($options)->get($url);
        } catch (AcquisitionFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof AcquisitionFailure) {
                    throw $cause;
                }
            }throw new AcquisitionFailure('SOURCE_CONNECTION_FAILED', true);
        }
        $code = $response->status();
        if ($code === 404) {
            throw new AcquisitionFailure('SOURCE_UNAVAILABLE');
        }
        if ($code === 429 || $code >= 500) {
            $after = $response->header('Retry-After');
            throw new AcquisitionFailure($code === 429 ? 'RATE_LIMITED' : 'SOURCE_SERVER_FAILED', true, ctype_digit((string) $after) ? min(86400, (int) $after) : null);
        }
        if ($code !== 200) {
            throw new AcquisitionFailure(in_array($code, [403, 418, 451], true) ? 'SOURCE_ACCESS_RESTRICTED' : 'SOURCE_HTTP_REJECTED');
        }
        clearstatcache(true, $target);
        $size = is_file($target) ? filesize($target) : 0;
        if ($size <= 0 || $size > $maximum) {
            throw new AcquisitionFailure('DOWNLOAD_SIZE_INVALID');
        }
        $tick();

        return ['bytes' => $size, 'sha256' => hash_file('sha256', $target)];
    }
}
