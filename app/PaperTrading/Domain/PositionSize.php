<?php

declare(strict_types=1);

namespace App\PaperTrading\Domain;

final readonly class PositionSize
{
    public ?string $allocatedMargin;

    public ?string $positionNotional;

    public ?string $plannedRisk;

    public ?string $effectiveLoss;

    public function __construct(
        public bool $allowed,
        public ?string $quantity,
        public string $riskCapital,
        public ?string $effectiveLossPerUnit,
        public ?string $notional,
        public ?string $reason = null,
        public ?string $qtyStep = null,
        ?string $allocatedMargin = null,
        public ?string $leverage = null,
    ) {
        $this->positionNotional = $notional;
        $this->plannedRisk = $riskCapital;
        $this->effectiveLoss = $effectiveLossPerUnit;
        $this->allocatedMargin = $allocatedMargin ?? $notional;
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'quantity' => $this->quantity,
            'position_notional' => $this->positionNotional,
            'allocated_margin' => $this->allocatedMargin,
            'planned_risk' => $this->plannedRisk,
            'effective_loss' => $this->effectiveLoss,
            'leverage' => $this->leverage ?? '1.00',
            'reason' => $this->reason,
            'qty_step' => $this->qtyStep,
        ];
    }
}
