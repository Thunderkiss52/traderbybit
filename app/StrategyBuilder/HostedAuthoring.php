<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** A configured hosted gateway receives only the owner-consented authoring text and schema profile. */
final readonly class HostedAuthoring
{
    public function __construct(private DefinitionValidator $validator) {}

    public function parse(string $description, bool $consent): array
    {
        $url = (string) config('strategy_builder.hosted.url');
        if (! $consent || config('strategy_builder.hosted.enabled') !== true || ! str_starts_with($url, 'https://')) {
            throw ValidationException::withMessages(['provider' => 'Hosted authoring requires explicit consent and an enabled HTTPS gateway. The deterministic parser remains available.']);
        }
        try {
            $response = Http::timeout(30)->connectTimeout(5)->withoutRedirecting()->acceptJson()->withToken((string) config('strategy_builder.hosted.token'))->post($url, [
                'model' => (string) config('strategy_builder.hosted.model'),
                'description' => $description,
                'schema_version' => 2,
                'instructions' => 'Return a JSON object containing definition and ambiguities. Never invent missing rules. Only data-only VECTOR strategy definitions are accepted.',
                'schema' => DefinitionSchema::document(),
            ]);
            if (! $response->successful() || strlen($response->body()) > 131072) {
                throw new \RuntimeException('Provider unavailable.');
            }
            $payload = $response->json();
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
