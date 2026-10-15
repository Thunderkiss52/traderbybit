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
        while (true) {
            $trainEnd = $cursor->modify('+'.(int) $config['train_months'].' months');
            $validationEnd = $trainEnd->modify('+'.(int) $config['validation_months'].' months');
            $testEnd = $validationEnd->modify('+'.(int) $config['test_months'].' months');
            if ($testEnd > $to) {
                break;
            }
            $windows[] = [new Segment('train', $cursor, $trainEnd, $index), new Segment('validation', $trainEnd, $validationEnd, $index), new Segment('test', $validationEnd, $testEnd, $index)];
            $cursor = $cursor->modify('+'.(int) $config['step_months'].' months');
            $index++;
        }

        return $windows;
    }
}
