package signalintel

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"math/big"
	"sort"
)

// Evaluate computes fixed-time research markouts, never hypothetical stop/target replay.
// Entry and exit slippage are embedded in prices; fees and signed funding are separate.
func Evaluate(ctx context.Context, input EvaluationRequest) (EvaluationResponse, error) {
	result := EvaluationResponse{PolicyVersion: LabelPolicy, Provenance: input.Provenance, ResearchOnly: true, Outcomes: []Outcome{}}
	if err := ctx.Err(); err != nil {
		return result, err
	}
	if len(input.HorizonsMinutes) == 0 || len(input.HorizonsMinutes) > 6 || len(input.Candles) > MaxCandles || len(input.Costs.FundingEvents) > MaxCandles {
		return result, fmt.Errorf("INPUT_LIMIT_OR_EMPTY_HORIZONS")
	}
	seen := map[int]bool{}
	for _, horizon := range input.HorizonsMinutes {
		if !supportedMinutes(horizon) || seen[horizon] {
			return result, fmt.Errorf("INVALID_OR_DUPLICATE_HORIZON")
		}
		seen[horizon] = true
	}
	if input.Notional == "" {
		input.Notional = "5"
	}
	if input.HorizonAnchor == "" {
		input.HorizonAnchor = "AVAILABILITY"
	}
	for _, horizon := range input.HorizonsMinutes {
		if _, _, ok := horizonTimes(input.Signal.AvailableMS, horizon, input.HorizonAnchor); !ok {
			return result, fmt.Errorf("UNSUPPORTED_SIGNAL_TIMESTAMP")
		}
	}
	if input.Signal.Regime == "" {
		input.Signal.Regime = "UNCERTAIN"
	}
	horizons := append([]int(nil), input.HorizonsMinutes...)
	sort.Ints(horizons)
	input.HorizonsMinutes = horizons
	// Observation time controls maturity, not immutable dataset/configuration identity.
	identity := input
	identity.AsOfMS = 0
	inputHash := hash(identity)
	commonReason := validateEvaluation(input)
	candles := make(map[int64]Candle, len(input.Candles))
	previous := int64(-1)
	for _, candle := range input.Candles {
		if err := ctx.Err(); err != nil {
			return result, err
		}
		if candle.OpenMS <= previous {
			commonReason = "DUPLICATE_OR_UNORDERED_CANDLES"
		}
		previous = candle.OpenMS
		if reason := validateCandle(candle, input.Signal, input.AsOfMS); reason != "" {
			commonReason = reason
		}
		candles[candle.OpenMS] = candle
	}
	for _, horizon := range horizons {
		if err := ctx.Err(); err != nil {
			return result, err
		}
		outcome := newOutcome(input, horizon, inputHash)
		if commonReason != "" {
			outcome.Status, outcome.Reason = "INVALID_DATA", commonReason
		} else if input.AsOfMS < outcome.EndMS {
			outcome.Status, outcome.Reason = "PENDING", "HORIZON_NOT_MATURE"
		} else {
			outcome = evaluateHorizon(ctx, input, outcome, candles)
		}
		result.Outcomes = append(result.Outcomes, outcome)
	}
	return result, ctx.Err()
}

func newOutcome(input EvaluationRequest, horizon int, inputHash string) Outcome {
	signal := input.Signal
	anchor := input.HorizonAnchor
	if anchor == "" {
		anchor = "AVAILABILITY"
	}
	entry, end, _ := horizonTimes(signal.AvailableMS, horizon, anchor)
	return Outcome{
		SignalID: signal.ID, InputHash: inputHash, Source: "HYPOTHETICAL", ExecutionModel: "HORIZON_MARKOUT",
		HorizonAnchor: anchor, EntryMS: entry, EndMS: end,
		Scope: Scope{Exchange: signal.Exchange, Category: signal.Category, Symbol: signal.Symbol,
			StrategyID: signal.StrategyID, StrategyVersion: signal.StrategyVersion, SourceTimeframeMinutes: signal.SourceTimeframeMinutes,
			HorizonMinutes: horizon, Direction: signal.Direction, Regime: signal.Regime,
			ConfigurationHash: input.Provenance.ConfigurationHash, CodeRevision: input.Provenance.CodeRevision,
			LabelPolicyVersion: LabelPolicy, DataSource: "HYPOTHETICAL", HorizonAnchor: anchor,
			ExecutionHash: executionHash(input, anchor)},
	}
}

func horizonTimes(available int64, horizon int, anchor string) (int64, int64, bool) {
	if available <= 0 || available > MaxTimestampMS || !supportedMinutes(horizon) {
		return 0, 0, false
	}
	delay := int64(0)
	if anchor == "NEXT_MINUTE_OPEN" && available%MinuteMS != 0 {
		delay = MinuteMS - available%MinuteMS
	}
	duration := int64(horizon) * MinuteMS
	if available > MaxTimestampMS-delay-duration {
		return 0, 0, false
	}
	entry := available + delay
	return entry, entry + duration, true
}

// Execution identity contains assumptions shared by a cohort, excluding prices,
// observation timestamps, candles and realized funding settlements.
func executionHash(input EvaluationRequest, anchor string) string {
	notional := input.Notional
	if notional == "" {
		notional = "5"
	}
	canonical := func(text string) string {
		if value, ok := number(text, true); ok {
			return value.FloatString(12)
		}
		return text
	}
	return hash(struct {
		Notional               string `json:"notional"`
		TickSize               string `json:"tick_size"`
		QuantityStep           string `json:"quantity_step"`
		MinQuantity            string `json:"min_quantity"`
		MaxQuantity            string `json:"max_quantity"`
		MinNotional            string `json:"min_notional"`
		CostSourceID           string `json:"cost_source_id"`
		TakerRate              string `json:"taker_rate"`
		SlippageRate           string `json:"slippage_rate"`
		FundingScheduleID      string `json:"funding_schedule_id"`
		FundingIntervalMinutes int    `json:"funding_interval_minutes"`
		FundingOffsetMinutes   int    `json:"funding_offset_minutes"`
		HorizonAnchor          string `json:"horizon_anchor"`
	}{
		Notional: canonical(notional), TickSize: canonical(input.Instrument.TickSize),
		QuantityStep: canonical(input.Instrument.QuantityStep), MinQuantity: canonical(input.Instrument.MinQuantity),
		MaxQuantity: canonical(input.Instrument.MaxQuantity), MinNotional: canonical(input.Instrument.MinNotional),
		CostSourceID: input.Costs.SourceID, TakerRate: canonical(input.Costs.TakerRate), SlippageRate: canonical(input.Costs.SlippageRate),
		FundingScheduleID: input.Costs.FundingScheduleID, FundingIntervalMinutes: input.Costs.FundingIntervalMinutes,
		FundingOffsetMinutes: input.Costs.FundingOffsetMinutes, HorizonAnchor: anchor,
	})
}

func validateEvaluation(input EvaluationRequest) string {
	s := input.Signal
	if !identifierPattern.MatchString(s.ID) || !scopeValid(newOutcome(input, 1, "").Scope) {
		return "INVALID_SIGNAL_SCOPE"
	}
	if !identifierPattern.MatchString(input.Provenance.DatasetID) || !hashPattern.MatchString(input.Provenance.DatasetHash) {
		return "MISSING_DATASET_PROVENANCE"
	}
	if s.DecisionMS <= 0 || s.AvailableMS < s.DecisionMS || s.FeatureAvailableMS <= 0 || s.FeatureAvailableMS > s.DecisionMS || input.AsOfMS <= 0 || input.AsOfMS > MaxTimestampMS || s.AvailableMS > input.AsOfMS {
		return "INVALID_OR_FUTURE_SIGNAL_TIMING"
	}
	if input.HorizonAnchor != "AVAILABILITY" && input.HorizonAnchor != "NEXT_MINUTE_OPEN" {
		return "INVALID_HORIZON_ANCHOR"
	}
	if input.HorizonAnchor == "AVAILABILITY" && s.AvailableMS%MinuteMS != 0 {
		return "UNSUPPORTED_RESOLUTION"
	}
	if _, ok := positive(s.ReferencePrice); !ok {
		return "INVALID_REFERENCE_PRICE"
	}
	if s.Category == "spot" && s.Direction == "SELL" {
		return "SPOT_SHORT_UNSUPPORTED"
	}
	i := input.Instrument
	if i.Exchange != s.Exchange || i.Category != s.Category || i.Symbol != s.Symbol || i.AvailableMS <= 0 || i.AvailableMS > s.DecisionMS {
		return "INVALID_OR_FUTURE_INSTRUMENT_SCOPE"
	}
	for _, text := range []string{i.TickSize, i.QuantityStep, i.MinQuantity, i.MaxQuantity} {
		if _, ok := positive(text); !ok {
			return "INVALID_INSTRUMENT_LIMITS"
		}
	}
	minQ, _ := positive(i.MinQuantity)
	maxQ, _ := positive(i.MaxQuantity)
	if minQ.Cmp(maxQ) > 0 {
		return "INVALID_INSTRUMENT_LIMITS"
	}
	if _, ok := number(i.MinNotional, false); !ok {
		return "INVALID_INSTRUMENT_LIMITS"
	}
	notional, ok := positive(input.Notional)
	if !ok || notional.Cmp(big.NewRat(1000000000000, 1)) >= 0 {
		return "INVALID_NOTIONAL"
	}
	if !identifierPattern.MatchString(input.Costs.SourceID) {
		return "MISSING_COST_PROVENANCE"
	}
	for _, text := range []string{input.Costs.TakerRate, input.Costs.SlippageRate} {
		rate, ok := number(text, false)
		if !ok || rate.Cmp(big.NewRat(1, 10)) >= 0 {
			return "INVALID_OR_MISSING_COSTS"
		}
	}
	if !input.Costs.FundingComplete {
		return "FUNDING_INCOMPLETE"
	}
	if s.Category == "linear" {
		c := input.Costs
		if !identifierPattern.MatchString(c.FundingScheduleID) || c.FundingIntervalMinutes < 1 || c.FundingIntervalMinutes > 10080 || c.FundingOffsetMinutes < 0 || c.FundingOffsetMinutes >= c.FundingIntervalMinutes {
			return "INVALID_FUNDING_SCHEDULE"
		}
	}
	return ""
}

func evaluateHorizon(ctx context.Context, input EvaluationRequest, out Outcome, candles map[int64]Candle) Outcome {
	bad := func(status, reason string) Outcome { out.Status, out.Reason = status, reason; return out }
	for t := out.EntryMS; t < out.EndMS; t += MinuteMS {
		if ctx.Err() != nil {
			return bad("INVALID_DATA", "CANCELLED")
		}
		candle, ok := candles[t]
		if !ok {
			return bad("INVALID_DATA", "MISSING_CANDLE")
		}
		if reason := validateCandle(candle, input.Signal, input.AsOfMS); reason != "" {
			return bad("INVALID_DATA", reason)
		}
	}
	first, last := candles[out.EntryMS], candles[out.EndMS-MinuteMS]
	rawEntry, _ := positive(first.Open)
	rawExit, _ := positive(last.Close)
	fee, _ := number(input.Costs.TakerRate, false)
	slip, _ := number(input.Costs.SlippageRate, false)
	tick, _ := positive(input.Instrument.TickSize)
	step, _ := positive(input.Instrument.QuantityStep)
	notional, _ := positive(input.Notional)
	buy := input.Signal.Direction == "BUY"
	entryFactor, exitFactor := add(big.NewRat(1, 1), slip), sub(big.NewRat(1, 1), slip)
	if !buy {
		entryFactor, exitFactor = exitFactor, entryFactor
	}
	entry := roundStep(mul(rawEntry, entryFactor), tick, buy)
	exit := roundStep(mul(rawExit, exitFactor), tick, !buy)
	if entry.Sign() <= 0 || exit.Sign() <= 0 {
		return bad("UNFILLABLE", "PRICE_ROUNDED_TO_ZERO")
	}
	quantity := roundStep(div(notional, entry), step, false)
	minQ, _ := positive(input.Instrument.MinQuantity)
	maxQ, _ := positive(input.Instrument.MaxQuantity)
	minNotional, _ := number(input.Instrument.MinNotional, false)
	executedNotional := mul(quantity, entry)
	if quantity.Sign() <= 0 || quantity.Cmp(minQ) < 0 {
		return bad("UNFILLABLE", "BELOW_MINIMUM_QUANTITY")
	}
	if quantity.Cmp(maxQ) > 0 {
		return bad("UNFILLABLE", "ABOVE_MAXIMUM_QUANTITY")
	}
	if executedNotional.Cmp(minNotional) < 0 {
		return bad("UNFILLABLE", "BELOW_MINIMUM_NOTIONAL")
	}
	funding, reason := calculateFunding(ctx, input, out, quantity)
	if reason != "" {
		return bad("INVALID_DATA", reason)
	}
	gross := mul(quantity, sub(exit, entry))
	priceReturn := div(sub(rawExit, rawEntry), rawEntry)
	if !buy {
		gross, priceReturn = negate(gross), negate(priceReturn)
	}
	fees := mul(add(executedNotional, mul(quantity, exit)), fee)
	// Round the persisted components first, then derive net from those exact values.
	// This keeps gross - fees + signed funding = net at NUMERIC(24,12) precision.
	gross, _ = new(big.Rat).SetString(gross.FloatString(12))
	fees, _ = new(big.Rat).SetString(fees.FloatString(12))
	funding, _ = new(big.Rat).SetString(funding.FloatString(12))
	net := add(sub(gross, fees), funding)
	netReturn := div(net, executedNotional)
	mfe, mae := big.NewRat(0, 1), big.NewRat(0, 1)
	for t := out.EntryMS; t < out.EndMS; t += MinuteMS {
		if ctx.Err() != nil {
			return bad("INVALID_DATA", "CANCELLED")
		}
		high, _ := positive(candles[t].High)
		low, _ := positive(candles[t].Low)
		favorable, adverse := div(sub(high, entry), entry), div(sub(entry, low), entry)
		if !buy {
			favorable, adverse = div(sub(entry, low), entry), div(sub(high, entry), entry)
		}
		if favorable.Cmp(mfe) > 0 {
			mfe = favorable
		}
		if adverse.Cmp(mae) > 0 {
			mae = adverse
		}
	}
	// NUMERIC(24,12) persistence cannot represent larger magnitudes. Fail explicitly.
	capacity := big.NewRat(1000000000000, 1)
	for _, value := range []*big.Rat{entry, exit, quantity, executedNotional, gross, fees, funding, net, priceReturn, netReturn, mfe, mae} {
		if new(big.Rat).Abs(value).Cmp(capacity) >= 0 {
			return bad("INVALID_DATA", "NUMERIC_CAPACITY_EXCEEDED")
		}
	}
	out.Status, out.Reason = "MATURED", "COMPLETE_COST_ADJUSTED_MARKOUT"
	out.EntryPrice, out.ExitPrice, out.Quantity, out.ExecutedNotional = decimalResult(entry), decimalResult(exit), decimalResult(quantity), decimalResult(executedNotional)
	out.GrossPnL, out.Fees, out.Funding, out.NetPnL = decimalResult(gross), decimalResult(fees), decimalResult(funding), decimalResult(net)
	out.PriceReturn, out.NetReturn, out.MFERatio, out.MAERatio = decimalResult(priceReturn), decimalResult(netReturn), decimalResult(mfe), decimalResult(mae)
	directional, profitable := priceReturn.Sign() > 0, net.Sign() > 0
	out.DirectionalCorrect, out.NetProfitable = &directional, &profitable
	return out
}

func validateCandle(c Candle, signal Signal, asOf int64) string {
	if c.Exchange != signal.Exchange || c.Category != signal.Category || c.Symbol != signal.Symbol {
		return "CANDLE_SCOPE_MISMATCH"
	}
	if !c.Closed || c.OpenMS < 0 || c.OpenMS > MaxTimestampMS-MinuteMS || c.OpenMS%MinuteMS != 0 || c.CloseMS != c.OpenMS+MinuteMS || c.CloseMS > asOf {
		return "OPEN_OR_FUTURE_CANDLE"
	}
	open, ok1 := positive(c.Open)
	high, ok2 := positive(c.High)
	low, ok3 := positive(c.Low)
	closePrice, ok4 := positive(c.Close)
	if !ok1 || !ok2 || !ok3 || !ok4 || high.Cmp(low) < 0 || high.Cmp(open) < 0 || high.Cmp(closePrice) < 0 || low.Cmp(open) > 0 || low.Cmp(closePrice) > 0 {
		return "INVALID_OHLC"
	}
	return ""
}

func calculateFunding(ctx context.Context, input EvaluationRequest, out Outcome, quantity *big.Rat) (*big.Rat, string) {
	zero := big.NewRat(0, 1)
	if input.Signal.Category == "spot" {
		if len(input.Costs.FundingEvents) != 0 {
			return nil, "SPOT_FUNDING_NOT_APPLICABLE"
		}
		return zero, ""
	}
	costs := input.Costs
	if costs.FundingCoverageFromMS > out.EntryMS || costs.FundingCoverageToMS < out.EndMS || costs.FundingCoverageFromMS <= 0 || costs.FundingCoverageToMS > input.AsOfMS {
		return nil, "FUNDING_COVERAGE_INCOMPLETE"
	}
	interval, offset := int64(costs.FundingIntervalMinutes)*MinuteMS, int64(costs.FundingOffsetMinutes)*MinuteMS
	events := map[int64]FundingEvent{}
	for _, event := range costs.FundingEvents {
		if ctx.Err() != nil {
			return nil, "CANCELLED"
		}
		if event.Exchange != input.Signal.Exchange || event.Category != input.Signal.Category || event.Symbol != input.Signal.Symbol || event.AtMS <= 0 || event.AtMS%MinuteMS != 0 {
			return nil, "FUNDING_SCOPE_MISMATCH"
		}
		if _, exists := events[event.AtMS]; exists {
			return nil, "DUPLICATE_FUNDING_EVENT"
		}
		if (event.AtMS-offset)%interval != 0 {
			return nil, "FUNDING_SCHEDULE_MISMATCH"
		}
		events[event.AtMS] = event
	}
	funding := zero
	first := ((out.EntryMS-offset)/interval+1)*interval + offset
	for boundary := first; boundary <= out.EndMS; boundary += interval {
		if ctx.Err() != nil {
			return nil, "CANCELLED"
		}
		event, ok := events[boundary]
		if !ok {
			return nil, "MISSING_FUNDING_EVENT"
		}
		rate, ok1 := number(event.Rate, true)
		mark, ok2 := positive(event.MarkPrice)
		if !ok1 || !ok2 || new(big.Rat).Abs(rate).Cmp(big.NewRat(1, 10)) >= 0 {
			return nil, "INVALID_FUNDING_EVENT"
		}
		payment := mul(mul(quantity, mark), rate)
		if input.Signal.Direction == "BUY" {
			payment = negate(payment)
		}
		funding = add(funding, payment)
	}
	return funding, ""
}

func hash(value interface{}) string {
	encoded, err := json.Marshal(value)
	if err != nil {
		return ""
	}
	digest := sha256.Sum256(encoded)
	return hex.EncodeToString(digest[:])
}
