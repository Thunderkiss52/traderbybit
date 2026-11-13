package signalintel

import (
	"math/big"
	"regexp"
)

var decimalPattern = regexp.MustCompile(`^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$`)
var identifierPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$`)
var exchangePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{1,31}$`)
var hashPattern = regexp.MustCompile(`^[a-f0-9]{64}$`)
var revisionPattern = regexp.MustCompile(`^(?:[a-f0-9]{40}|[a-f0-9]{64})$`)

func number(text string, signed bool) (*big.Rat, bool) {
	if !decimalPattern.MatchString(text) {
		return nil, false
	}
	n, ok := new(big.Rat).SetString(text)
	return n, ok && (signed || n.Sign() >= 0)
}

func positive(text string) (*big.Rat, bool) {
	n, ok := number(text, false)
	return n, ok && n.Sign() > 0
}

func add(a, b *big.Rat) *big.Rat { return new(big.Rat).Add(a, b) }
func sub(a, b *big.Rat) *big.Rat { return new(big.Rat).Sub(a, b) }
func mul(a, b *big.Rat) *big.Rat { return new(big.Rat).Mul(a, b) }
func div(a, b *big.Rat) *big.Rat { return new(big.Rat).Quo(a, b) }
func negate(a *big.Rat) *big.Rat { return new(big.Rat).Neg(a) }

func roundStep(value, step *big.Rat, up bool) *big.Rat {
	units := div(value, step)
	whole, remainder := new(big.Int).QuoRem(units.Num(), units.Denom(), new(big.Int))
	if up && remainder.Sign() > 0 {
		whole.Add(whole, big.NewInt(1))
	}
	return mul(new(big.Rat).SetInt(whole), step)
}

func decimalResult(n *big.Rat) *string {
	text := n.FloatString(12)
	return &text
}

func supportedMinutes(minutes int) bool {
	switch minutes {
	case 1, 3, 5, 15, 30, 60:
		return true
	}
	return false
}

func scopeValid(scope Scope) bool {
	return exchangePattern.MatchString(scope.Exchange) && identifierPattern.MatchString(scope.Symbol) &&
		identifierPattern.MatchString(scope.StrategyID) && identifierPattern.MatchString(scope.StrategyVersion) &&
		(scope.Category == "linear" || scope.Category == "spot") && supportedMinutes(scope.SourceTimeframeMinutes) &&
		supportedMinutes(scope.HorizonMinutes) && (scope.Direction == "BUY" || scope.Direction == "SELL") &&
		identifierPattern.MatchString(scope.Regime) && hashPattern.MatchString(scope.ConfigurationHash) &&
		revisionPattern.MatchString(scope.CodeRevision) && scope.LabelPolicyVersion == LabelPolicy &&
		hashPattern.MatchString(scope.ExecutionHash) && (scope.HorizonAnchor == "AVAILABILITY" || scope.HorizonAnchor == "NEXT_MINUTE_OPEN") &&
		(scope.DataSource == "HYPOTHETICAL" || scope.DataSource == "SHADOW" || scope.DataSource == "PAPER_FORWARD")
}
