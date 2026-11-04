package backtest

import (
	"fmt"
	"github.com/traderbybit/research-go/internal/decimal"
	"time"
)

// ReplayPolicy adds causal lifecycle instructions without changing the legacy runner contract.
type ReplayPolicy struct {
	ExitAtOpen     map[int]map[string]bool
	PartialTargets map[int]PartialTarget
	Funding        []FundingEvent
}
type PartialTarget struct {
	Price    decimal.Decimal
	Fraction decimal.Decimal
}
type FundingEvent struct {
	At   time.Time       `json:"at"`
	Rate decimal.Decimal `json:"rate"`
	Mark decimal.Decimal `json:"mark"`
}
type ReplayExecution struct {
	Kind     string          `json:"kind"`
	At       time.Time       `json:"at"`
	Side     string          `json:"side"`
	Price    decimal.Decimal `json:"price"`
	Quantity decimal.Decimal `json:"quantity"`
	Fee      decimal.Decimal `json:"fee"`
	Funding  decimal.Decimal `json:"funding"`
}
type replayPosition struct {
	initial, gross, fees, funding decimal.Decimal
	partial                       *PartialTarget
	partialDone                   bool
}

func newReplayPosition(quantity, fee decimal.Decimal, partial *PartialTarget) *replayPosition {
	return &replayPosition{initial: quantity, gross: decimal.Zero(), fees: fee, funding: decimal.Zero(), partial: partial}
}
func (e *Engine) exitSlip(price decimal.Decimal, side string) decimal.Decimal {
	if side == "BUY" {
		return price.Sub(price.Mul(e.config.SlippageRate))
	}
	return price.Add(price.Mul(e.config.SlippageRate))
}
func legProfit(side string, quantity, entry, exit decimal.Decimal) decimal.Decimal {
	if side == "BUY" {
		return quantity.Mul(exit.Sub(entry))
	}
	return quantity.Mul(entry.Sub(exit))
}
func (p *ReplayPolicy) validate() error {
	for i, event := range p.Funding {
		if event.At.IsZero() || !event.Mark.IsPositive() || event.Rate.Cmp(decimal.MustFromString("0.1")) > 0 || event.Rate.Cmp(decimal.MustFromString("-0.1")) < 0 || i > 0 && !event.At.After(p.Funding[i-1].At) {
			return fmt.Errorf("invalid or duplicate funding event")
		}
	}
	for _, target := range p.PartialTargets {
		if !target.Price.IsPositive() || !target.Fraction.IsPositive() || target.Fraction.Cmp(decimal.FromInt64(1)) >= 0 {
			return fmt.Errorf("invalid partial target")
		}
	}
	return nil
}
