<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

final class ExecutableStore
{
    public function capture(?string $serverSource = null): string
    {
        $source = $serverSource ?? (string) config('strategy_builder.go_binary');
        if (! is_executable($source)) {
            throw new \RuntimeException('Build the server-controlled Go DSL executable before publishing v2.');
        }
        $hash = hash_file('sha256', $source);
        $directory = storage_path('app/strategy-engines');
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Executable archive unavailable.');
        }
        $target = $directory.'/'.$hash;
        if (! is_file($target)) {
            $temporary = tempnam($directory, 'engine-');
            try {
                if (! copy($source, $temporary) || hash_file('sha256', $temporary) !== $hash || ! chmod($temporary, 0555) || ! rename($temporary, $target)) {
                    throw new \RuntimeException('Executable archival failed.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        $this->resolve($hash);

        return $hash;
    }

    public function resolve(?string $hash): string
    {
        if ($hash === null) {
            $hash = $this->capture();
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \RuntimeException('Invalid executable identity.');
        }
        $path = storage_path('app/strategy-engines/').$hash;
        if (! is_executable($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
            throw new \RuntimeException('Pinned executable missing or modified; evaluation blocked.');
        }

        return $path;
    }
}
