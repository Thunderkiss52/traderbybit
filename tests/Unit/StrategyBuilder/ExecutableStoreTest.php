<?php

declare(strict_types=1);

namespace Tests\Unit\StrategyBuilder;

use App\StrategyBuilder\ExecutableStore;
use Tests\TestCase;

final class ExecutableStoreTest extends TestCase
{
    public function test_pinned_engine_survives_configured_binary_replacement(): void
    {
        $store = app(ExecutableStore::class);
        $hash = $store->capture();
        config(['strategy_builder.go_binary' => '/nonexistent/replaced-engine']);
        self::assertSame($hash, hash_file('sha256', $store->resolve($hash)));
        $this->expectException(\RuntimeException::class);
        $store->capture();
    }

    public function test_a_modified_archive_is_rejected_before_execution(): void
    {
        $store = app(ExecutableStore::class);
        $store->capture();
        $hash = hash('sha256', random_bytes(32));
        $path = storage_path('app/strategy-engines/'.$hash);
        file_put_contents($path, 'corrupt');
        chmod($path, 0550);
        try {
            $this->expectException(\RuntimeException::class);
            $store->resolve($hash);
        } finally {
            unlink($path);
        }
    }
}
