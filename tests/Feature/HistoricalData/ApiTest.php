<?php

declare(strict_types=1);

namespace Tests\Feature\HistoricalData;

use App\HistoricalData\AcquisitionJob;
use App\HistoricalData\Recovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_controls_are_authenticated_durable_idempotent_audited_and_paginated(): void
    {
        config(['services.research_api_token' => 'owner-fixture-token', 'historical_data.reserve_disk_bytes' => 0, 'historical_data.max_staging_bytes' => 104857600]);
        Queue::fake();
        Http::preventStrayRequests();
        $url = '/api/v1/historical/';
        $input = PlannerTest::preset();
        $this->postJson($url.'preview', $input)->assertUnauthorized()->assertJsonPath('error.code', 'TOKEN_MISSING');
        $this->withHeader('X-Research-Token', 'wrong')->getJson($url.'batches')->assertForbidden();
        $this->withHeader('X-Research-Token', 'owner-fixture-token');
        $this->postJson($url.'preview', $input)->assertOk()->assertJsonPath('data.estimate.candle_archives', 240);
        self::assertSame(0, DB::table('historical_download_batches')->count());
        $this->postJson($url.'batches', $input)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'fixture-request-key');
        $id = $this->postJson($url.'batches', $input)->assertCreated()->json('data.id');
        $this->postJson($url.'batches', $input)->assertCreated()->assertJsonPath('data.id', $id);
        Queue::assertNothingPushed();
        app(Recovery::class)->dispatch();
        Queue::assertNothingPushed();
        $entries = $this->getJson($url.'batches/'.$id.'/entries')->assertOk()->assertJsonCount(25, 'data.data')->assertJsonPath('data.total', 336);
        self::assertArrayNotHasKey('lease_owner', $entries->json('data.data.0'));
        self::assertStringNotContainsString('/app/storage', $entries->getContent());
        $this->postJson($url.'batches/'.$id.'/start', [])->assertUnprocessable();
        $this->postJson($url.'batches/'.$id.'/start', ['reference' => 'OWNER-ACK-2026', 'terms_version' => config('historical_data.license.version')])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        Queue::assertPushed(AcquisitionJob::class, 1);
        $this->postJson($url.'batches/'.$id.'/pause')->assertOk()->assertJsonPath('data.status', 'PAUSED');
        $this->postJson($url.'batches/'.$id.'/retry')->assertConflict();
        $this->postJson($url.'batches/'.$id.'/resume')->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->postJson($url.'batches/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson($url.'batches/'.$id.'/resume')->assertConflict();
        $this->getJson($url.'batches/'.$id.'/audit')->assertOk()->assertJsonPath('data.total', 5);
        $this->getJson($url.'strategies')->assertOk()->assertJsonCount(13, 'data');
        $this->getJson($url.'batches/'.$id.'/research')->assertOk()->assertJsonCount(0, 'data.data');
        Http::assertNothingSent();
        self::assertSame(0, DB::table('market_candles')->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
    }

    public function test_no_urls_or_commercial_use_can_be_injected_and_cli_help_and_plan_do_not_download(): void
    {
        config(['services.research_api_token' => 'owner-fixture-token']);
        Http::preventStrayRequests();
        Queue::fake();
        $this->withHeader('X-Research-Token', 'owner-fixture-token');
        $this->postJson('/api/v1/historical/preview', PlannerTest::preset() + ['url' => 'http://localhost/private'])->assertUnprocessable();
        $this->postJson('/api/v1/historical/preview', array_replace(PlannerTest::preset(), ['intended_use' => 'COMMERCIAL']))->assertUnprocessable();
        $this->artisan('historical:data', ['--help' => true])->assertSuccessful();
        $file = tempnam(sys_get_temp_dir(), 'vector-vision-cli-');
        try {
            file_put_contents($file, json_encode(PlannerTest::preset()));
            $this->artisan('historical:data', ['action' => 'plan', '--input' => $file])->assertSuccessful();
        } finally {
            unlink($file);
        }
        self::assertSame(0, DB::table('historical_download_batches')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
