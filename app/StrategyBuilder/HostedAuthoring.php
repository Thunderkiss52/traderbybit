<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** A configured hosted gateway receives only the owner-consented authoring text and schema profile. */
final readonly class HostedAuthoring
{
    public function __construct(private DefinitionValidator $validator, private AuthoringParser $parser) {}

    public function parse(string $description, bool $consent): array
    {
        $url = (string) config('strategy_builder.hosted.url');
        if (! $consent || config('strategy_builder.hosted.enabled') !== true || ! str_starts_with($url, 'https://')) {
            throw ValidationException::withMessages(['provider' => 'Hosted authoring requires explicit consent and an enabled HTTPS gateway. The deterministic parser remains available.']);
        }
        try {
            $driver = (string) config('strategy_builder.hosted.driver', 'gateway');
            $instructions = 'Return a JSON object containing definition and ambiguities (an array of strings). Never invent missing entry, stop, target, scope, timeframe or risk rules. Put missing or unsupported requirements in ambiguities. Only data-only VECTOR strategy definitions are accepted. Do not generate code or request tools.';
            $payload = [
                'model' => (string) config('strategy_builder.hosted.model'),
                'description' => $description,
                'schema_version' => 2,
                'instructions' => $instructions,
                'schema' => DefinitionSchema::document(),
            ];
            if ($driver === 'chat_completions') {
                if ($payload['model'] === '' || (string) config('strategy_builder.hosted.token') === '') {
                    throw new \RuntimeException('Provider configuration incomplete.');
                }
                $example = $this->parser->parse('Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.')['definition'];
                $payload = ['model' => $payload['model'], 'store' => false, 'max_completion_tokens' => 4096, 'response_format' => ['type' => 'json_object'], 'messages' => [
                    ['role' => 'system', 'content' => $instructions.' Schema: '.json_encode(DefinitionSchema::document(), JSON_THROW_ON_ERROR).' Example definition: '.json_encode($example, JSON_THROW_ON_ERROR)],
                    ['role' => 'user', 'content' => $description],
                ]];
            } elseif ($driver !== 'gateway') {
                throw new \RuntimeException('Unsupported authoring provider driver.');
            }
            $response = Http::timeout(30)->connectTimeout(5)->withoutRedirecting()->acceptJson()->withToken((string) config('strategy_builder.hosted.token'))->post($url, $payload);
            if (! $response->successful() || strlen($response->body()) > 131072) {
                throw new \RuntimeException('Provider unavailable.');
            }
            $payload = $response->json();
            if ($driver === 'chat_completions') {
                $choice = $payload['choices'][0] ?? null;
                if (! is_array($choice) || ($choice['finish_reason'] ?? null) !== 'stop' || ! empty($choice['message']['refusal']) || ! is_string($choice['message']['content'] ?? null)) {
                    throw new \RuntimeException('Incomplete provider interpretation.');
                }
                $payload = json_decode($choice['message']['content'], true, 64, JSON_THROW_ON_ERROR);
            }
            if (! is_array($payload) || ! empty($payload['ambiguities']) || ! is_array($payload['definition'] ?? null)) {
                throw new \RuntimeException('Unsupported or ambiguous interpretation.');
            }

            return ['definition' => $this->validator->validate($payload['definition']), 'assumptions' => [], 'confirmation_required' => true, 'provider' => 'hosted'];
        } catch (\Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            throw ValidationException::withMessages(['provider' => 'Hosted interpretation unavailable or ambiguous. Use deterministic authoring or the visual builder.']);
        }
    }
}
