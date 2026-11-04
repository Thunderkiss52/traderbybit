<?php

declare(strict_types=1);

namespace Tests\Feature\StrategyBuilder;

use App\StrategyBuilder\DefinitionStore;
use App\StrategyBuilder\DefinitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DefinitionTest extends TestCase
{
    use RefreshDatabase;

    public static function example(): array
    {
        return ['schema_version' => 1, 'id' => 'user_ema_rsi', 'version' => 1, 'symbols' => ['BTCUSDT'], 'exchanges' => ['bybit'], 'timeframe' => '15', 'indicators' => ['fast' => ['type' => 'EMA', 'period' => 20, 'timeframe' => '15'], 'slow' => ['type' => 'EMA', 'period' => 50, 'timeframe' => '15'], 'rsi' => ['type' => 'RSI', 'period' => 14, 'timeframe' => '15']], 'entry' => ['BUY' => ['and' => [['op' => 'crosses_above', 'left' => 'fast', 'right' => 'slow'], ['op' => 'lt', 'left' => 'rsi', 'right' => 65]]]], 'stop_atr' => 1.5, 'take_atr' => 3, 'risk_percent' => 0.5, 'timeout_bars' => 48, 'cooldown_bars' => 0];
    }

    public function test_versions_are_immutable_idempotent_and_never_provision_accounts(): void
    {
        $count = DB::table('paper_accounts')->count();
        $store = app(DefinitionStore::class);
        $one = $store->save(self::example(), 'test-owner');
        self::assertSame($one->id, $store->save(self::example(), 'test-owner')->id);
        self::assertSame(1, DB::table('strategy_definition_events')->count());
        self::assertSame($count, DB::table('paper_accounts')->count());
        $changed = self::example();
        $changed['stop_atr'] = 2;
        $this->expectException(ValidationException::class);
        $store->save($changed, 'test-owner');
    }

    public function test_rejects_unsafe_or_unsupported_definitions(): void
    {
        foreach (['stop_atr' => null, 'risk_percent' => 6, 'code' => 'exec', 'version' => '1', 'entry' => ['BUY' => ['op' => 'invented', 'left' => 'fast', 'right' => 1]]] as $key => $value) {
            $definition = self::example();
            $definition[$key] = $value;
            try {
                app(DefinitionValidator::class)->validate($definition);
                self::fail('Accepted '.$key);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
}
