<?php

declare(strict_types=1);

namespace App\StrategyBuilder;

use Symfony\Component\Process\Process;

final readonly class GoEngine
{
    public function __construct(private ExecutableStore $executables) {}

    public function run(array $input, ?string $checksum = null): array
    {
        $binary = $this->executables->resolve($checksum);
        if (! is_executable($binary)) {
            throw new \RuntimeException('Go DSL executable unavailable.');
        }
        $process = new Process([$binary]);
        $process->setTimeout((int) config('strategy_builder.timeout_seconds'));
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Go DSL evaluation rejected input: '.mb_substr($process->getErrorOutput(), 0, 1000));
        }
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result) || ! array_key_exists('signals', $result)) {
            throw new \RuntimeException('Invalid Go DSL response.');
        }

        return $result;
    }
}
