<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use App\Exchanges\Application\ExchangeStrategyCompatibilityService;
use App\Strategy\Application\StrategyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class DefinitionStore
{
    public function __construct(private DefinitionValidator $validator, private ExecutableStore $executables, private GoEngine $engine) {}

    public function save(array $definition, string $actor, ?string $engineChecksum = null): object
    {
        $definition = $this->validator->validate($definition);
        $checksum = StrategyRegistry::hash($definition);
        if ($definition['schema_version'] === 2) {
            $engineChecksum ??= $this->executables->capture();
            $this->executables->resolve($engineChecksum);
            $this->engine->run(['definition' => $definition, 'mode' => 'validate'], $engineChecksum);
        }

        $saved = DB::transaction(function () use ($definition, $checksum, $actor, $engineChecksum): object {
            DB::table('strategy_definitions')->insertOrIgnore([
                'strategy_id' => $definition['id'], 'version' => $definition['version'],
                'definition' => json_encode($definition, JSON_THROW_ON_ERROR), 'checksum' => $checksum,
                'engine_checksum' => $engineChecksum, 'status' => 'VALIDATED', 'created_at' => now('UTC'),
            ]);
            $row = DB::table('strategy_definitions')->where('strategy_id', $definition['id'])->where('version', $definition['version'])->lockForUpdate()->first();
            if ($row->checksum !== $checksum) {
                throw ValidationException::withMessages(['version' => 'Immutable version already exists; increment version.']);
            }
            if (! DB::table('strategy_definition_events')->where('definition_id', $row->id)->where('action', 'CREATED')->exists()) {
                DB::table('strategy_definition_events')->insert(['definition_id' => $row->id, 'action' => 'CREATED', 'actor' => $actor, 'details' => json_encode(['checksum' => $checksum]), 'created_at' => now('UTC')]);
            }

            return $row;
        }, 3);

        if (class_exists(ExchangeStrategyCompatibilityService::class)) {
            try {
                app(ExchangeStrategyCompatibilityService::class)->reconcileStrategy($definition['id']);
            } catch (\Throwable) {
                // Non-blocking
            }
        }

        return $saved;
    }
}
