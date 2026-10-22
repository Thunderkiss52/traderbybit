<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class BrowserOriginProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    public function test_cross_site_state_changing_requests_are_rejected(): void
    {
        $this->withHeaders([
            'Sec-Fetch-Site' => 'cross-site',
            'Origin' => 'https://evil.example',
        ])->postJson('/api/v1/backtests', [])
            ->assertStatus(403)
            ->assertJsonPath('research_mode', true)
            ->assertJsonPath('error.code', 'ORIGIN_NOT_ALLOWED');

        Bus::assertNothingDispatched();
    }

    public function test_same_site_state_changing_requests_are_rejected(): void
    {
        $this->withHeaders([
            'Sec-Fetch-Site' => 'same-site',
            'Origin' => 'https://evil.example',
        ])->postJson('/api/v1/backtests', [])->assertStatus(403);
    }

    public function test_foreign_origin_without_browser_hints_is_rejected(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson('/api/v1/backtests', [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ORIGIN_NOT_ALLOWED');
    }

    public function test_non_browser_clients_without_origin_headers_are_allowed(): void
    {
        $this->postJson('/api/v1/backtests', [])->assertUnprocessable();
    }

    public function test_same_origin_requests_reach_the_application(): void
    {
        $this->withHeaders([
            'Sec-Fetch-Site' => 'same-origin',
            'Origin' => 'http://127.0.0.1',
        ])->postJson('/api/v1/backtests', [])->assertUnprocessable();
    }

    public function test_configured_cors_origins_may_submit_state_changing_requests(): void
    {
        config(['cors.allowed_origins' => ['https://partner.example']]);

        $this->withHeaders([
            'Sec-Fetch-Site' => 'cross-site',
            'Origin' => 'https://partner.example',
        ])->postJson('/api/v1/backtests', [])->assertUnprocessable();
    }

    public function test_cross_origin_reads_are_not_rejected(): void
    {
        $this->withHeaders([
            'Sec-Fetch-Site' => 'cross-site',
            'Origin' => 'https://evil.example',
        ])->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_origin_checks_do_not_bypass_the_research_token(): void
    {
        config(['services.research_api_token' => 'secret-test-token']);

        $this->withHeaders([
            'Sec-Fetch-Site' => 'same-origin',
            'Origin' => 'http://127.0.0.1',
        ])->postJson('/api/v1/research/autonomy/experiments', [])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_MISSING');
    }
}
