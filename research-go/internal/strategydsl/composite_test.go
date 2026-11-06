package strategydsl

import (
	"encoding/json"
	"math"
	"reflect"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

// generateMultiTimeframeCandles produces synchronized 15m and 60m closed candles.
func generateMultiTimeframeCandles(start time.Time, count15m int, priceFunc func(i int) float64) (map[string][]datasets.Candle, time.Time) {
	cs15 := make([]datasets.Candle, count15m)
	for i := 0; i < count15m; i++ {
		p := priceFunc(i)
		openP := decimal.FromFloat64(p)
		highP := decimal.FromFloat64(p + 1.5)
		lowP := decimal.FromFloat64(p - 1.5)
		closeP := decimal.FromFloat64(p)
		volP := decimal.FromFloat64(100.0 + float64(i%10)*5)

		cs15[i] = datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "15",
			OpenTime: start.Add(time.Duration(i) * 15 * time.Minute),
			Open:     openP,
			High:     highP,
			Low:      lowP,
			Close:    closeP,
			Volume:   volP,
			Turnover: closeP.Mul(volP),
			IsClosed: true,
		}
	}

	// Group into 60m candles (4 x 15m)
	count60m := count15m / 4
	cs60 := make([]datasets.Candle, count60m)
	for h := 0; h < count60m; h++ {
		first := cs15[h*4]
		highest := first.High
		lowest := first.Low
		volSum := decimal.Zero()
		turnoverSum := decimal.Zero()

		for sub := 0; sub < 4; sub++ {
			c := cs15[h*4+sub]
			if c.High.Cmp(highest) > 0 {
				highest = c.High
			}
			if c.Low.Cmp(lowest) < 0 {
				lowest = c.Low
			}
			volSum = volSum.Add(c.Volume)
			turnoverSum = turnoverSum.Add(c.Turnover)
		}
		last := cs15[h*4+3]

		cs60[h] = datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "60",
			OpenTime: first.OpenTime,
			Open:     first.Open,
			High:     highest,
			Low:      lowest,
			Close:    last.Close,
			Volume:   volSum,
			Turnover: turnoverSum,
			IsClosed: true,
		}
	}

	asOf := cs15[count15m-1].OpenTime.Add(15 * time.Minute)
	return map[string][]datasets.Candle{
		"15": cs15,
		"60": cs60,
	}, asOf
}

// TestIndicatorsROCAndZScore verifies accurate calculation and edge cases of ROC and ZSCORE.
func TestIndicatorsROCAndZScore(t *testing.T) {
	// 1. Reference ROC
	// Closes: 100, 102, 105, 110, 100, 95
	prices := []float64{100, 102, 105, 110, 100, 95}
	closes := make([]decimal.Decimal, len(prices))
	for i, p := range prices {
		closes[i] = decimal.FromFloat64(p)
	}

	period := 2
	rocVals := roc(closes, period)

	if rocVals[0] != nil || rocVals[1] != nil {
		t.Fatalf("ROC warmup bars should be nil")
	}

	// bar 2: (105 - 100) / 100 * 100 = 5%
	if rocVals[2] == nil || math.Abs(rocVals[2].Float64()-5.0) > 1e-4 {
		t.Fatalf("ROC bar 2 want 5.0, got %v", rocVals[2])
	}
	// bar 3: (110 - 102) / 102 * 100 = 7.843137%
	want3 := (110.0 - 102.0) / 102.0 * 100.0
	if rocVals[3] == nil || math.Abs(rocVals[3].Float64()-want3) > 1e-4 {
		t.Fatalf("ROC bar 3 want %f, got %v", want3, rocVals[3])
	}
	// bar 5: (95 - 110) / 110 * 100 = -13.636363%
	want5 := (95.0 - 110.0) / 110.0 * 100.0
	if rocVals[5] == nil || math.Abs(rocVals[5].Float64()-want5) > 1e-4 {
		t.Fatalf("ROC bar 5 want %f, got %v", want5, rocVals[5])
	}

	// 2. Reference ZSCORE
	flatPrices := []float64{50, 50, 50, 50, 50}
	flatCloses := make([]decimal.Decimal, len(flatPrices))
	for i, p := range flatPrices {
		flatCloses[i] = decimal.FromFloat64(p)
	}
	zFlat := zscore(flatCloses, 3)
	// Zero stddev should yield 0 zscore
	if zFlat[2] == nil || !zFlat[2].IsZero() {
		t.Fatalf("Flat prices should produce ZSCORE 0, got %v", zFlat[2])
	}

	// Moving series: [10, 10, 10, 16] with period 4
	// Mean = 11.5, variance = ((10-11.5)^2 * 3 + (16-11.5)^2)/4 = (2.25*3 + 20.25)/4 = 27/4 = 6.75
	// StdDev = sqrt(6.75) = 2.598076
	// Z = (16 - 11.5) / 2.598076 = 4.5 / 2.598076 = 1.73205
	stepPrices := []float64{10, 10, 10, 16}
	stepCloses := make([]decimal.Decimal, len(stepPrices))
	for i, p := range stepPrices {
		stepCloses[i] = decimal.FromFloat64(p)
	}
	zStep := zscore(stepCloses, 4)
	if zStep[3] == nil {
		t.Fatalf("ZSCORE bar 3 should not be nil")
	}
	wantZ := 4.5 / math.Sqrt(6.75)
	if math.Abs(zStep[3].Float64()-wantZ) > 1e-4 {
		t.Fatalf("ZSCORE want %f, got %v", wantZ, zStep[3])
	}
}

// TestAll15IndicatorsSupportedInDSL verifies that all 15 technical and price indicators are accepted and evaluated.
func TestAll15IndicatorsSupportedInDSL(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	series, asOf := generateMultiTimeframeCandles(start, 240, func(i int) float64 {
		return 100.0 + float64(i)*0.2
	})

	allIndicators := map[string]Indicator{
		"i_ema":            {Type: "EMA", Period: 5, Timeframe: "15"},
		"i_sma":            {Type: "SMA", Period: 20, Timeframe: "15"},
		"i_rsi":            {Type: "RSI", Period: 14, Timeframe: "15"},
		"i_atr":            {Type: "ATR", Period: 14, Timeframe: "15"},
		"i_adx":            {Type: "ADX", Period: 14, Timeframe: "15"},
		"i_macd":           {Type: "MACD", Period: 12, SlowPeriod: 26, Timeframe: "15"},
		"i_macd_signal":    {Type: "MACD_SIGNAL", Period: 12, SlowPeriod: 26, SignalPeriod: 9, Timeframe: "15"},
		"i_macd_histogram": {Type: "MACD_HISTOGRAM", Period: 12, SlowPeriod: 26, SignalPeriod: 9, Timeframe: "15"},
		"i_bb_upper":       {Type: "BB_UPPER", Period: 20, Deviation: 2.0, Timeframe: "15"},
		"i_bb_lower":       {Type: "BB_LOWER", Period: 20, Deviation: 2.0, Timeframe: "15"},
		"i_donchian_high":  {Type: "DONCHIAN_HIGH", Period: 20, Timeframe: "15"},
		"i_donchian_low":   {Type: "DONCHIAN_LOW", Period: 20, Timeframe: "15"},
		"i_vol_sma":        {Type: "VOLUME_SMA", Period: 10, Timeframe: "15"},
		"i_roc":            {Type: "ROC", Period: 10, Timeframe: "15"},
		"i_zscore":         {Type: "ZSCORE", Period: 20, Timeframe: "15"},
	}

	def := Definition{
		SchemaVersion: 1,
		ID:            "user_all_indicators",
		Version:       1,
		Symbols:       []string{"BTCUSDT"},
		Exchanges:     []string{"bybit"},
		Timeframe:     "15",
		Indicators:    allIndicators,
		Entry: map[string]Condition{
			"BUY": {Op: "gt", Left: "i_ema", Right: json.RawMessage(`"i_sma"`)},
		},
		StopATR:      1.5,
		TakeATR:      3.0,
		RiskPercent:  0.02,
		TimeoutBars:  20,
		CooldownBars: 0,
	}

	if err := def.Validate(); err != nil {
		t.Fatalf("validation failed for all 15 indicators: %v", err)
	}

	req := Request{
		Definition: def,
		Exchange:   "bybit",
		Symbol:     "BTCUSDT",
		Series:     series,
		From:       start.Add(60 * 15 * time.Minute),
		AsOf:       asOf,
		Capital:    decimal.FromInt64(10000),
		Fee:        decimal.MustFromString("0.00055"),
		Slippage:   decimal.MustFromString("0.0002"),
	}

	res, err := Run(req)
	if err != nil {
		t.Fatalf("Run failed for all 15 indicators: %v", err)
	}
	if len(res.Signals) == 0 {
		t.Fatalf("expected signals for uptrend EMA > SMA")
	}
}

// TestAll8ConditionOperators verifies evaluation of gt, lt, gte, lte, crosses_above, crosses_below, within, change_pct.
func TestAll8ConditionOperators(t *testing.T) {
	vs := map[string]valuePair{
		"fast":   {now: 105.0, before: 98.0, ready: true},
		"slow":   {now: 100.0, before: 100.0, ready: true},
		"drop":   {now: 90.0, before: 100.0, ready: true},
		"rsi":    {now: 32.0, before: 28.0, ready: true},
		"zscore": {now: -1.2, before: -0.5, ready: true},
	}

	cases := []struct {
		name string
		cond Condition
		want bool
	}{
		{"gt", Condition{Op: "gt", Left: "fast", Right: json.RawMessage(`"slow"`)}, true},
		{"lt", Condition{Op: "lt", Left: "slow", Right: json.RawMessage(`"fast"`)}, true},
		{"gte_equal", Condition{Op: "gte", Left: "slow", Right: json.RawMessage(`100.0`)}, true},
		{"lte_equal", Condition{Op: "lte", Left: "slow", Right: json.RawMessage(`100.0`)}, true},
		{"crosses_above", Condition{Op: "crosses_above", Left: "fast", Right: json.RawMessage(`"slow"`)}, true},
		{"crosses_below", Condition{Op: "crosses_below", Left: "fast", Right: json.RawMessage(`"slow"`)}, false},
		{"within_true", Condition{Op: "within", Left: "rsi", Right: json.RawMessage(`[30.0, 35.0]`)}, true},
		{"within_false", Condition{Op: "within", Left: "rsi", Right: json.RawMessage(`[40.0, 50.0]`)}, false},
		{"change_pct_positive", Condition{Op: "change_pct", Left: "fast", Right: json.RawMessage(`5.0`)}, true},   // (105/98-1)*100 = 7.14% >= 5%
		{"change_pct_negative", Condition{Op: "change_pct", Left: "drop", Right: json.RawMessage(`-5.0`)}, true}, // (90/100-1)*100 = -10% <= -5%
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, ok := evaluate(tc.cond, vs)
			if !ok {
				t.Fatalf("condition not ready")
			}
			if got != tc.want {
				t.Fatalf("operator %s want %v, got %v", tc.name, tc.want, got)
			}
		})
	}
}

// TestCompositeMultiRuleEvaluation verifies nested AND, OR, NOT blocks.
func TestCompositeMultiRuleEvaluation(t *testing.T) {
	vs := map[string]valuePair{
		"ema_fast":  {now: 110.0, before: 100.0, ready: true},
		"ema_slow":  {now: 105.0, before: 104.0, ready: true},
		"rsi":       {now: 32.0, before: 27.0, ready: true},
		"adx":       {now: 26.0, before: 24.0, ready: true},
		"vol_surge": {now: 150.0, before: 100.0, ready: true},
	}

	// Composite Rule:
	// AND(
	//   ema_fast > ema_slow,
	//   adx >= 25,
	//   OR(
	//     rsi crosses_above 30,
	//     rsi within [20, 25]
	//   ),
	//   NOT(
	//     rsi > 70
	//   )
	// )
	composite := Condition{
		And: []Condition{
			{Op: "gt", Left: "ema_fast", Right: json.RawMessage(`"ema_slow"`)},
			{Op: "gte", Left: "adx", Right: json.RawMessage(`25.0`)},
			{
				Or: []Condition{
					{Op: "crosses_above", Left: "rsi", Right: json.RawMessage(`30.0`)},
					{Op: "within", Left: "rsi", Right: json.RawMessage(`[20.0, 25.0]`)},
				},
			},
			{
				Not: &Condition{
					Op: "gt", Left: "rsi", Right: json.RawMessage(`70.0`),
				},
			},
		},
	}

	hit, ok := evaluate(composite, vs)
	if !ok || !hit {
		t.Fatalf("expected composite condition to evaluate true, got hit=%v, ok=%v", hit, ok)
	}

	// Invert one clause: make adx < 25 -> composite should become false
	vs["adx"] = valuePair{now: 22.0, before: 20.0, ready: true}
	hit, ok = evaluate(composite, vs)
	if !ok || hit {
		t.Fatalf("expected composite condition to evaluate false when ADX < 25, got hit=%v, ok=%v", hit, ok)
	}
}

// TestCompositeStrategyTrendRSIWithATRTrailingStop verifies end-to-end execution of a composite
// strategy (Trend Following + RSI Pullback + ATR Trailing Stop + Partial Exit).
func TestCompositeStrategyTrendRSIWithATRTrailingStop(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)

	// Price curve:
	// Bars 0-30: flat at 80 (warmup).
	// Bars 30-60: steady bull run from 80 up to 110 (establishes strong uptrend: fast EMA > slow EMA).
	// Bars 60-63: healthy pullback from 110 down to 102 (RSI drops into [30, 40] while fast EMA remains well above slow EMA).
	// Bars 63-65: bounce from 102 to 106 (RSI crosses back above 35 while fast > slow -> triggers BUY signal!).
	// Bars 65-85: strong trend resumption from 106 up to 140 (hits partial exit, ratchets trailing stop up to ~134).
	// Bars 85-95: sharp pullback from 140 down to 120 (hits ratcheted trailing stop at ~134, exiting in profit).
	// Bars 95-180: flat at 120.
	prices := make([]float64, 180)
	for i := 0; i < 30; i++ {
		prices[i] = 80.0
	}
	for i := 30; i < 60; i++ {
		prices[i] = 80.0 + float64(i-30)*1.0 // 80 -> 110
	}
	// Pullback
	prices[60] = 108.0
	prices[61] = 105.0
	prices[62] = 102.0
	// Bounce
	prices[63] = 104.0
	prices[64] = 107.0
	// Trend resumption
	for i := 65; i < 85; i++ {
		prices[i] = 107.0 + float64(i-64)*1.65 // 107 -> 140
	}
	// Reversal / Pullback hitting trailing stop
	for i := 85; i < 95; i++ {
		prices[i] = 140.0 - float64(i-84)*2.0 // 140 -> 120
	}
	for i := 95; i < 180; i++ {
		prices[i] = 120.0
	}

	series, asOf := generateMultiTimeframeCandles(start, 180, func(i int) float64 {
		return prices[i]
	})

	def := Definition{
		SchemaVersion: 1,
		ID:            "user_composite_trend_rsi_trail",
		Version:       1,
		Symbols:       []string{"BTCUSDT"},
		Exchanges:     []string{"bybit"},
		Timeframe:     "15",
		Indicators: map[string]Indicator{
			"ema_fast":  {Type: "EMA", Period: 8, Timeframe: "15"},
			"ema_slow":  {Type: "EMA", Period: 21, Timeframe: "15"},
			"htf_trend": {Type: "EMA", Period: 5, Timeframe: "60"},
			"rsi":       {Type: "RSI", Period: 14, Timeframe: "15"},
			"atr":       {Type: "ATR", Period: 14, Timeframe: "15"},
			"roc":       {Type: "ROC", Period: 5, Timeframe: "15"},
			"zscore":    {Type: "ZSCORE", Period: 20, Timeframe: "15"},
		},
		Entry: map[string]Condition{
			"BUY": {
				And: []Condition{
					{Op: "gt", Left: "ema_fast", Right: json.RawMessage(`"ema_slow"`)},
					{
						Or: []Condition{
							{Op: "crosses_above", Left: "rsi", Right: json.RawMessage(`60.0`)},
							{Op: "within", Left: "rsi", Right: json.RawMessage(`[50.0, 65.0]`)},
						},
					},
					{
						Not: &Condition{
							Op: "gt", Left: "zscore", Right: json.RawMessage(`3.0`),
						},
					},
				},
			},
		},
		StopATR:      2.0,
		TakeATR:      15.0,
		RiskPercent:  0.02,
		TimeoutBars:  50,
		CooldownBars: 10,
		PartialExit: &PartialExit{
			ATR:      3.0,
			Fraction: 0.5,
		},
		TrailingStop: &TrailingStop{
			ActivationATR: 2.0,
			DistanceATR:   2.0,
		},
	}

	if err := def.Validate(); err != nil {
		t.Fatalf("definition validation failed: %v", err)
	}

	req := Request{
		Definition: def,
		Exchange:   "bybit",
		Symbol:     "BTCUSDT",
		Series:     series,
		From:       start.Add(60 * 15 * time.Minute),
		AsOf:       asOf,
		Capital:    decimal.FromInt64(10000),
		Fee:        decimal.MustFromString("0.00055"),
		Slippage:   decimal.MustFromString("0.0002"),
	}

	res, err := Run(req)
	if err != nil {
		t.Fatalf("Run failed: %v", err)
	}

	if len(res.Signals) == 0 {
		t.Fatalf("expected signals from composite strategy")
	}

	report := res.Report
	if report == nil {
		t.Fatalf("expected backtest report")
	}
	if len(report.Trades) == 0 {
		t.Fatalf("expected executed trades in report")
	}

	trade := report.Trades[0]
	// Verify that the trade exited with positive PnL because trailing stop locked in gains
	if !trade.NetPnL.IsPositive() {
		t.Fatalf("expected profitable trade from trailing stop, got net PnL: %s", trade.NetPnL.String())
	}
	// Initial stop loss was entryPrice - 2 * ATR (~115 - 4 = ~111).
	// With trailing stop ratcheting at high ~137.5 with 2 ATR cushion (~4), stop loss was ~133.5.
	// So exit price must be significantly higher than entry price!
	if trade.ExitPrice.Cmp(trade.EntryPrice) <= 0 {
		t.Fatalf("expected exit price (%s) > entry price (%s) due to trailing stop ratchet", trade.ExitPrice.String(), trade.EntryPrice.String())
	}

	// Verify partial exit took place
	executions, ok := report.Metadata["executions"].([]backtest.ReplayExecution)
	if !ok {
		t.Fatalf("expected executions in metadata")
	}
	hasPartial := false
	for _, exec := range executions {
		if exec.Kind == "PARTIAL_EXIT" {
			hasPartial = true
			break
		}
	}
	if !hasPartial {
		t.Fatalf("expected PARTIAL_EXIT execution in replay executions")
	}

	// Contrast test: Run SAME strategy WITHOUT trailing stop
	defNoTrail := def
	defNoTrail.TrailingStop = nil
	reqNoTrail := req
	reqNoTrail.Definition = defNoTrail

	resNoTrail, err := Run(reqNoTrail)
	if err != nil {
		t.Fatalf("Run without trailing stop failed: %v", err)
	}
	// Without trailing stop, when price plummeted from 137.5 to 102.5, it would have dropped all the way
	// to the original stop loss or timed out at lower price, so trailing stop net PnL should be strictly superior!
	if len(resNoTrail.Report.Trades) > 0 {
		tradeNoTrail := resNoTrail.Report.Trades[0]
		if trade.NetPnL.Cmp(tradeNoTrail.NetPnL) <= 0 {
			t.Fatalf("expected trailing stop PnL (%s) > no-trailing PnL (%s)", trade.NetPnL.String(), tradeNoTrail.NetPnL.String())
		}
	}
}

// TestDeterministicBacktestNoLookaheadBias verifies:
// 1. Bit-for-bit determinism across repeated executions.
// 2. Strict causal bar sequencing (EntryBar == DecisionBar + 1).
// 3. Complete immunity of past decisions to future candle data mutations.
func TestDeterministicBacktestNoLookaheadBias(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	series, asOf := generateMultiTimeframeCandles(start, 320, func(i int) float64 {
		return 100.0 + math.Sin(float64(i)*0.1)*10.0 + float64(i)*0.1
	})

	def := Definition{
		SchemaVersion: 2,
		ID:            "user_determinism_test",
		Version:       1,
		Symbols:       []string{"BTCUSDT"},
		Exchanges:     []string{"bybit"},
		Timeframe:     "15",
		Indicators: map[string]Indicator{
			"fast": {Type: "EMA", Period: 5, Timeframe: "15"},
			"slow": {Type: "EMA", Period: 15, Timeframe: "15"},
			"rsi":  {Type: "RSI", Period: 14, Timeframe: "15"},
			"atr":  {Type: "ATR", Period: 14, Timeframe: "15"},
			"roc":  {Type: "ROC", Period: 5, Timeframe: "15"},
		},
		Entry: map[string]Condition{
			"BUY": {
				And: []Condition{
					{Op: "crosses_above", Left: "fast", Right: json.RawMessage(`"slow"`)},
					{Op: "gt", Left: "roc", Right: json.RawMessage(`0.0`)},
				},
			},
			"SELL": {
				And: []Condition{
					{Op: "crosses_below", Left: "fast", Right: json.RawMessage(`"slow"`)},
					{Op: "lt", Left: "roc", Right: json.RawMessage(`0.0`)},
				},
			},
		},
		StopATR:      2.0,
		TakeATR:      4.0,
		RiskPercent:  0.01,
		TimeoutBars:  15,
		CooldownBars: 2,
	}

	req := Request{
		Definition: def,
		Exchange:   "bybit",
		Symbol:     "BTCUSDT",
		Series:     series,
		From:       series["15"][HistoryWindow(def)+2].OpenTime,
		AsOf:       asOf,
		Capital:    decimal.FromInt64(10000),
		Fee:        decimal.MustFromString("0.00055"),
		Slippage:   decimal.MustFromString("0.0002"),
	}

	// 1. Bit-for-bit determinism
	res1, err := Run(req)
	if err != nil {
		t.Fatalf("Run 1 failed: %v", err)
	}
	res2, err := Run(req)
	if err != nil {
		t.Fatalf("Run 2 failed: %v", err)
	}

	if !reflect.DeepEqual(res1, res2) {
		t.Fatalf("engine execution is not strictly deterministic")
	}

	if len(res1.Signals) == 0 {
		t.Fatalf("expected signals for oscillating price curve")
	}

	// 2. Strict causality
	for i, s := range res1.Signals {
		if s.EntryBar != s.DecisionBar+1 {
			t.Fatalf("signal %d entry bar (%d) is not strictly decision bar + 1 (%d)", i, s.EntryBar, s.DecisionBar)
		}
		expectedDecisionTime := series["15"][s.DecisionBar].OpenTime.Add(15 * time.Minute)
		if !s.DecisionTime.Equal(expectedDecisionTime) {
			t.Fatalf("signal %d decision time mismatch: want %v, got %v", i, expectedDecisionTime, s.DecisionTime)
		}
	}

	// 3. No look-ahead bias: future candle mutation
	targetSignal := res1.Signals[len(res1.Signals)/2]
	cutoffBar := targetSignal.DecisionBar

	// Create mutated series where every candle after cutoffBar is wildly modified
	mutatedSeries := map[string][]datasets.Candle{}
	for tf, cs := range series {
		mutatedCs := make([]datasets.Candle, len(cs))
		copy(mutatedCs, cs)
		mutatedSeries[tf] = mutatedCs
	}

	for i := cutoffBar + 1; i < len(mutatedSeries["15"]); i++ {
		mutatedSeries["15"][i].Close = decimal.FromInt64(99999)
		mutatedSeries["15"][i].High = decimal.FromInt64(100000)
		mutatedSeries["15"][i].Low = decimal.FromInt64(90000)
		mutatedSeries["15"][i].Open = decimal.FromInt64(95000)
	}

	mutatedReq := req
	mutatedReq.Series = mutatedSeries
	mutatedRes, err := Run(mutatedReq)
	if err != nil {
		t.Fatalf("mutated Run failed: %v", err)
	}

	// Verify all signals up to cutoffBar are completely unchanged
	pastCount := 0
	for _, s := range res1.Signals {
		if s.DecisionBar <= cutoffBar {
			pastCount++
		}
	}

	mutatedPastCount := 0
	for _, s := range mutatedRes.Signals {
		if s.DecisionBar <= cutoffBar {
			mutatedPastCount++
		}
	}

	if pastCount != mutatedPastCount {
		t.Fatalf("future data mutation affected past signal count: want %d, got %d", pastCount, mutatedPastCount)
	}

	for i := 0; i < pastCount; i++ {
		orig := res1.Signals[i]
		mut := mutatedRes.Signals[i]
		if orig.DecisionBar != mut.DecisionBar || orig.Side != mut.Side || orig.StopLoss.Cmp(mut.StopLoss) != 0 || orig.TakeProfit.Cmp(mut.TakeProfit) != 0 {
			t.Fatalf("lookahead bias detected! Past signal %d modified by future candle mutation", i)
		}
	}
}

// TestMultiTimeframeBarAlignmentNoLeakage verifies that higher timeframe indicators only use
// strictly closed bars at the exact moment of lower timeframe decisions.
func TestMultiTimeframeBarAlignmentNoLeakage(t *testing.T) {
	start := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	// 40 hours = 160 x 15m candles and 40 x 60m candles
	series, asOf := generateMultiTimeframeCandles(start, 160, func(i int) float64 {
		return 100.0 + float64(i)*0.5
	})

	def := Definition{
		SchemaVersion: 1,
		ID:            "user_mtf_alignment",
		Version:       1,
		Symbols:       []string{"BTCUSDT"},
		Exchanges:     []string{"bybit"},
		Timeframe:     "15",
		Indicators: map[string]Indicator{
			"ltf_fast": {Type: "EMA", Period: 4, Timeframe: "15"},
			"htf_slow": {Type: "EMA", Period: 5, Timeframe: "60"},
		},
		Entry: map[string]Condition{
			"BUY": {Op: "gt", Left: "ltf_fast", Right: json.RawMessage(`"htf_slow"`)},
		},
		StopATR:      1.5,
		TakeATR:      3.0,
		RiskPercent:  0.01,
		TimeoutBars:  10,
		CooldownBars: 0,
	}

	req := Request{
		Definition: def,
		Exchange:   "bybit",
		Symbol:     "BTCUSDT",
		Series:     series,
		From:       start.Add(30 * 15 * time.Minute),
		AsOf:       asOf,
		Capital:    decimal.FromInt64(10000),
		Fee:        decimal.MustFromString("0.00055"),
		Slippage:   decimal.MustFromString("0.0002"),
	}

	origResult, err := Run(req)
	if err != nil {
		t.Fatalf("Run failed: %v", err)
	}

	// 60m candle index 30 covers 15m bars 120, 121, 122, 123 (from 30h to 31h)
	// 60m candle 30 is only closed at 31h (at the decision time of 15m bar 123).
	// During 15m bars 120 (30:15 decision), 121 (30:30 decision), and 122 (30:45 decision),
	// the latest closed 60m candle MUST be candle 29 (closed at 30h).
	// Therefore, mutating 60m candle 30 MUST NOT affect decisions at or before 15m bar 122!
	mutatedSeries := map[string][]datasets.Candle{
		"15": make([]datasets.Candle, len(series["15"])),
		"60": make([]datasets.Candle, len(series["60"])),
	}
	copy(mutatedSeries["15"], series["15"])
	copy(mutatedSeries["60"], series["60"])

	// Mutate 60m candle 30
	mutatedSeries["60"][30].Close = decimal.FromInt64(100000)
	mutatedSeries["60"][30].High = decimal.FromInt64(100000)

	mutReq := req
	mutReq.Series = mutatedSeries
	mutResult, err := Run(mutReq)
	if err != nil {
		t.Fatalf("mutated Run failed: %v", err)
	}

	barrierDecisionTime := series["60"][30].OpenTime.Add(time.Hour) // 31h
	for i, origSig := range origResult.Signals {
		if origSig.DecisionTime.Before(barrierDecisionTime) {
			mutSig := mutResult.Signals[i]
			if !reflect.DeepEqual(origSig, mutSig) {
				t.Fatalf("unclosed HTF candle leakage detected before %v! Signal mismatch at %v", barrierDecisionTime, origSig.DecisionTime)
			}
		}
	}
}
