<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

final readonly class PerformanceReport
{
    public function __construct(public array $metrics, public array $dataQuality) {}

    public function toArray(): array
    {
        return ['research_mode' => true, 'metrics' => $this->metrics, 'data_quality' => $this->dataQuality];
    }
}
