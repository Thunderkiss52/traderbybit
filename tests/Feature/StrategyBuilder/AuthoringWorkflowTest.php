<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AuthoringWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_survive_reload_use_revision_checks_and_publish_immutable_versions(): void
    {
        config(['services.research_api_token' => 'owner']);
        $this->withHeader('X-Research-Token', 'owner');
        $id = Uuid::uuid4()->toString();
        $payload = ['id' => $id, 'definition' => ['id' => 'user_incomplete']];
        $first = $this->postJson('/api/v1/strategies/drafts', $payload)->assertOk()->json('data');
        $this->getJson("/api/v1/strategies/drafts/{$id}")->assertOk()->assertJsonPath('data.revision', 1);
        $payload['definition'] = DefinitionTest::example();
        $payload['revision'] = 1;
        $second = $this->postJson('/api/v1/strategies/drafts', $payload)->assertOk()->json('data');
        self::assertSame(2, $second['revision']);
        $this->postJson("/api/v1/strategies/drafts/{$id}/publish", ['revision' => 1, 'checksum' => $first['checksum'], 'interpretation_confirmed' => true])->assertConflict();
        $version = $this->postJson("/api/v1/strategies/drafts/{$id}/publish", ['revision' => 2, 'checksum' => $second['checksum'], 'interpretation_confirmed' => true])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/strategies/drafts/{$id}/publish", ['revision' => 2, 'checksum' => $second['checksum'], 'interpretation_confirmed' => true])->assertCreated()->assertJsonPath('data.id', $version);
        $payload['definition']['stop_atr'] = 2;
        $this->postJson('/api/v1/strategies/drafts', $payload)->assertConflict();
        self::assertSame(0, DB::table('paper_accounts')->count());
        self::assertSame(3, DB::table('strategy_workflow_events')->count());
    }

    public function test_hosted_authoring_requires_consent_and_validates_untrusted_output_without_private_context(): void
    {
        config(['services.research_api_token' => 'owner', 'strategy_builder.hosted.enabled' => true, 'strategy_builder.hosted.url' => 'https://authoring.example/interpret']);
        $this->withHeader('X-Research-Token', 'owner');
        $bad = DefinitionTest::example();
        $bad['exec'] = 'danger';
        Http::fake(['authoring.example/*' => Http::sequence()->push(['definition' => DefinitionTest::example(), 'ambiguities' => []])->push(['definition' => $bad])]);
        $this->postJson('/api/v1/strategies/parse', ['description' => 'My strategy', 'provider' => 'hosted'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->postJson('/api/v1/strategies/parse', ['description' => 'My strategy', 'provider' => 'hosted', 'consent' => true])->assertOk()->assertJsonPath('data.confirmation_required', true);
        Http::assertSent(fn ($r) => array_keys($r->data()) === ['model', 'description', 'schema_version', 'instructions', 'schema']);
        $this->postJson('/api/v1/strategies/parse', ['description' => 'My strategy', 'provider' => 'hosted', 'consent' => true])->assertUnprocessable();
        self::assertSame(0, DB::table('strategy_definitions')->count());
    }

    public function test_chat_provider_is_authoring_only_and_rejects_truncated_output(): void
    {
        config(['services.research_api_token' => 'owner', 'strategy_builder.hosted.enabled' => true, 'strategy_builder.hosted.driver' => 'chat_completions', 'strategy_builder.hosted.url' => 'https://api.openai.com/v1/chat/completions', 'strategy_builder.hosted.model' => 'configured-json-model', 'strategy_builder.hosted.token' => 'provider-test-token']);
        $content = json_encode(['definition' => DefinitionTest::example(), 'ambiguities' => []]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::sequence()->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]]])->push(['choices' => [['finish_reason' => 'length', 'message' => ['content' => $content]]]])]);
        $this->withHeader('X-Research-Token', 'owner');
        $request = ['description' => 'Owner-authored rules only', 'provider' => 'hosted', 'consent' => true];
        $this->postJson('/api/v1/strategies/parse', $request)->assertOk();
        Http::assertSent(fn ($r) => $r['store'] === false && $r['response_format']['type'] === 'json_object' && $r['messages'][1] === ['role' => 'user', 'content' => $request['description']] && ! isset($r['tools']) && count($r['messages']) === 2);
        $this->postJson('/api/v1/strategies/parse', $request)->assertUnprocessable();
        self::assertSame(0, DB::table('strategy_definitions')->count());
        self::assertSame(0, DB::table('paper_accounts')->count());
    }
}
