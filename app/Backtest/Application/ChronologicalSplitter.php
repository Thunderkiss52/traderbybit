<?php

declare(strict_types=1);

namespace App\Backtest\Application;

use App\Backtest\Domain\Segment;
use DateTimeImmutable;
use InvalidArgumentException;

final class ChronologicalSplitter
{
    public function split(DateTimeImmutable $from, DateTimeImmutable $to, array $percentages): array
    {
        if ($from >= $to || array_sum($percentages) !== 100) {
            throw new InvalidArgumentException('Invalid chronological split.');
        }
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        $trainEnd = $from->setTimestamp($from->getTimestamp() + intdiv($seconds * (int) $percentages['train'], 100));
        $validationEnd = $from->setTimestamp($from->getTimestamp() + intdiv($seconds * ((int) $percentages['train'] + (int) $percentages['validation']), 100));

        return [new Segment('train', $from, $trainEnd), new Segment('validation', $trainEnd, $validationEnd), new Segment('test', $validationEnd, $to)];
    }

    public function walkForward(DateTimeImmutable $from, DateTimeImmutable $to, array $config): array
    {
        $windows = [];
        $cursor = $from;
        $index = 0;
        $unit = isset($config['step_minutes']) || isset($config['train_minutes']) ? 'minutes' : (isset($config['step_days']) || isset($config['train_days']) ? 'days' : 'months');
        $trainAmount = (int) ($config["train_{$unit}"] ?? $config['train_months'] ?? 6);
        $validationAmount = (int) ($config["validation_{$unit}"] ?? $config['validation_months'] ?? 2);
        $testAmount = (int) ($config["test_{$unit}"] ?? $config['test_months'] ?? 2);
        $stepAmount = (int) ($config["step_{$unit}"] ?? $config['step_months'] ?? 2);
        if ($trainAmount <= 0 || $validationAmount <= 0 || $testAmount <= 0 || $stepAmount <= 0) {
            throw new InvalidArgumentException('Invalid walk forward step or window duration.');
        }

        while (true) {
            $trainEnd = $cursor->modify("+{$trainAmount} {$unit}");
            $validationEnd = $trainEnd->modify("+{$validationAmount} {$unit}");
            $testEnd = $validationEnd->modify("+{$testAmount} {$unit}");
            if ($testEnd > $to) {
                break;
            }
            $windows[] = [new Segment('train', $cursor, $trainEnd, $index), new Segment('validation', $trainEnd, $validationEnd, $index), new Segment('test', $validationEnd, $testEnd, $index)];
            $cursor = $cursor->modify("+{$stepAmount} {$unit}");
            $index++;
        }

        return $windows;
    }
}
