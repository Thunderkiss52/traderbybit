package backtest

import (
	"fmt"
	"github.com/traderbybit/research-go/internal/decimal"
	"math/big"
	"time"
)

// ReplayPolicy adds causal lifecycle instructions without changing the legacy runner contract.
type ReplayPolicy struct {
	ExitAtOpen      map[int]map[string]bool
	PartialTargets  map[int]PartialTarget
	TrailingTargets map[int]TrailingTarget
	Funding         []FundingEvent
}
type PartialTarget struct {
	Price    decimal.Decimal
	Fraction decimal.Decimal
}
type TrailingTarget struct {
	ActivationPrice decimal.Decimal
	Distance        decimal.Decimal
}
type FundingEvent struct {
	RateExact string          `json:"rate_exact,omitempty"`
	MarkExact string          `json:"mark_exact,omitempty"`
	At        time.Time       `json:"at"`
	Rate      decimal.Decimal `json:"rate"`
	Mark      decimal.Decimal `json:"mark"`
}
type ReplayExecution struct {
	Slippage decimal.Decimal `json:"slippage"`
	Kind     string          `json:"kind"`
	At       time.Time       `json:"at"`
	Side     string          `json:"side"`
	Price    decimal.Decimal `json:"price"`
	Quantity decimal.Decimal `json:"quantity"`
	Fee      decimal.Decimal `json:"fee"`
	Funding  decimal.Decimal `json:"funding"`
}
type replayPosition struct {
	initial, gross, fees, funding, slippage decimal.Decimal
	partial                                 *PartialTarget
	partialDone                             bool
	trailing                                *TrailingTarget
	trailingActive                          bool
	extremePrice                            decimal.Decimal
}

func newReplayPosition(quantity, fee decimal.Decimal, partial *PartialTarget, trailing *TrailingTarget, fillPrice decimal.Decimal) *replayPosition {
	active := trailing != nil && trailing.ActivationPrice.IsZero()
	return &replayPosition{
		initial:        quantity,
		gross:          decimal.Zero(),
		fees:           fee,
		funding:        decimal.Zero(),
		slippage:       decimal.Zero(),
		partial:        partial,
		trailing:       trailing,
		trailingActive: active,
		extremePrice:   fillPrice,
	}
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
		if event.RateExact != "" || event.MarkExact != "" {
			if _, err := exactFundingAmount(event, decimal.FromInt64(1)); err != nil {
				return err
			}
		}
		if event.At.IsZero() || !event.Mark.IsPositive() || event.Rate.Cmp(decimal.MustFromString("0.1")) > 0 || event.Rate.Cmp(decimal.MustFromString("-0.1")) < 0 || i > 0 && !event.At.After(p.Funding[i-1].At) {
			return fmt.Errorf("invalid or duplicate funding event")
		}
	}
	for _, target := range p.PartialTargets {
		if !target.Price.IsPositive() || !target.Fraction.IsPositive() || target.Fraction.Cmp(decimal.FromInt64(1)) >= 0 {
			return fmt.Errorf("invalid partial target")
		}
	}
	for _, target := range p.TrailingTargets {
		if !target.Distance.IsPositive() {
			return fmt.Errorf("invalid trailing target")
		}
	}
	return nil
}

func absoluteReplayDecimal(value decimal.Decimal) decimal.Decimal {
	if value.IsNegative() {
		return value.Neg()
	}
	return value
}

func exactFundingAmount(event FundingEvent, quantity decimal.Decimal) (decimal.Decimal, error) {
	rate, mark := event.Rate.String(), event.Mark.String()
	if event.RateExact != "" {
		rate = event.RateExact
	}
	if event.MarkExact != "" {
		mark = event.MarkExact
	}
	r, ok := new(big.Rat).SetString(rate)
	if !ok {
		return decimal.Zero(), fmt.Errorf("invalid exact funding rate")
	}
	m, ok := new(big.Rat).SetString(mark)
	if !ok || m.Sign() <= 0 {
		return decimal.Zero(), fmt.Errorf("invalid exact funding mark")
	}
	convertedRate, err := decimal.FromString(rate)
	if err != nil || convertedRate.Cmp(event.Rate) != 0 {
		return decimal.Zero(), fmt.Errorf("conflicting exact funding rate")
	}
	convertedMark, err := decimal.FromString(mark)
	if err != nil || convertedMark.Cmp(event.Mark) != 0 {
		return decimal.Zero(), fmt.Errorf("conflicting exact funding mark")
	}
	q, ok := new(big.Rat).SetString(quantity.String())
	if !ok {
		return decimal.Zero(), fmt.Errorf("invalid funding quantity")
	}
	return decimal.FromRational(new(big.Rat).Mul(new(big.Rat).Mul(q, m), r)), nil
}
