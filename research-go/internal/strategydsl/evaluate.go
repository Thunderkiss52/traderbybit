// Package strategydsl evaluates data-only strategy definitions. It cannot access accounts or exchanges.
package strategydsl

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"regexp"
	"sort"
	"strconv"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"github.com/traderbybit/research-go/internal/simulation"
)

type Indicator struct {
	Type         string  `json:"type"`
	Period       int     `json:"period"`
	Timeframe    string  `json:"timeframe"`
	SlowPeriod   int     `json:"slow_period,omitempty"`
	SignalPeriod int     `json:"signal_period,omitempty"`
	Deviation    float64 `json:"deviation,omitempty"`
}
type Condition struct {
	And   []Condition     `json:"and,omitempty"`
	Or    []Condition     `json:"or,omitempty"`
	Not   *Condition      `json:"not,omitempty"`
	Op    string          `json:"op,omitempty"`
	Left  string          `json:"left,omitempty"`
	Right json.RawMessage `json:"right,omitempty"`
}
type Definition struct {
	SchemaVersion int                       `json:"schema_version"`
	ID            string                    `json:"id"`
	Version       int                       `json:"version"`
	Symbols       []string                  `json:"symbols"`
	Exchanges     []string                  `json:"exchanges"`
	Timeframe     string                    `json:"timeframe"`
	Indicators    map[string]Indicator      `json:"indicators"`
	Entry         map[string]Condition      `json:"entry"`
	StopATR       float64                   `json:"stop_atr"`
	TakeATR       float64                   `json:"take_atr"`
	RiskPercent   float64                   `json:"risk_percent"`
	TimeoutBars   int                       `json:"timeout_bars"`
	CooldownBars  int                       `json:"cooldown_bars"`
	Exit          map[string]Condition      `json:"exit,omitempty"`
	Regime        *Condition                `json:"regime,omitempty"`
	PartialExit   *PartialExit              `json:"partial_exit,omitempty"`
	TrailingStop  *TrailingStop             `json:"trailing_stop,omitempty"`
	TrailingATR   float64                   `json:"trailing_atr,omitempty"`
	Funding       *FundingConfig            `json:"funding,omitempty"`
	Parameters    map[string]ParameterRange `json:"parameters,omitempty"`
}
type TrailingStop struct {
	ActivationATR float64 `json:"activation_atr,omitempty"`
	DistanceATR   float64 `json:"distance_atr"`
}
type PartialExit struct {
	ATR      float64 `json:"atr"`
	Fraction float64 `json:"fraction"`
}
type FundingConfig struct {
	Enabled       bool `json:"enabled"`
	IntervalHours int  `json:"interval_hours"`
}
type ParameterRange struct {
	Default float64 `json:"default"`
	Min     float64 `json:"min"`
	Max     float64 `json:"max"`
	Step    float64 `json:"step"`
}
type Request struct {
	Context                context.Context              `json:"-"`
	PublishedFundingEvents bool                         `json:"published_funding_events,omitempty"`
	IncludeFeatures        bool                         `json:"include_features,omitempty"`
	Seed                   int64                        `json:"seed,omitempty"`
	Definition             Definition                   `json:"definition"`
	Mode                   string                       `json:"mode,omitempty"`
	Funding                []backtest.FundingEvent      `json:"funding,omitempty"`
	Exchange               string                       `json:"exchange"`
	Symbol                 string                       `json:"symbol"`
	Series                 map[string][]datasets.Candle `json:"series"`
	AsOf                   time.Time                    `json:"as_of"`
	From                   time.Time                    `json:"from"`
	Capital                decimal.Decimal              `json:"capital"`
	Fee                    decimal.Decimal              `json:"fee"`
	Slippage               decimal.Decimal              `json:"slippage"`
}
type Result struct {
	Signals       []backtest.Signal        `json:"signals"`
	Report        *backtest.BacktestReport `json:"report"`
	PaperEligible bool                     `json:"paper_eligible"`
	Limitations   []string                 `json:"limitations"`
}

func contains(xs []string, value string) bool {
	for _, x := range xs {
		if x == value {
			return true
		}
	}
	return false
}
func frame(s string) int {
	switch s {
	case "5":
		return 300
	case "15":
		return 900
	case "30":
		return 1800
	case "60":
		return 3600
	}
	return 0
}

func (d Definition) Validate() error {
	if err := validateParameters(d); err != nil {
		return err
	}
	if !regexp.MustCompile(`^user_[a-z0-9_]{1,48}$`).MatchString(d.ID) || len(d.Symbols) == 0 || len(d.Symbols) > 20 || len(d.Exchanges) == 0 || len(d.Exchanges) > 10 {
		return fmt.Errorf("invalid strategy identity or scope lists")
	}
	for _, list := range []struct {
		values  []string
		pattern string
	}{{d.Symbols, `^[A-Z0-9]{3,24}$`}, {d.Exchanges, `^[a-z0-9_-]{2,32}$`}} {
		seen := map[string]bool{}
		for _, value := range list.values {
			if seen[value] || !regexp.MustCompile(list.pattern).MatchString(value) {
				return fmt.Errorf("invalid or duplicate scope")
			}
			seen[value] = true
		}
	}
	if (d.SchemaVersion != 1 && d.SchemaVersion != 2) || d.Version < 1 || len(d.ID) < 6 || d.ID[:5] != "user_" || frame(d.Timeframe) == 0 || len(d.Indicators) == 0 || len(d.Indicators) > 32 || len(d.Entry) == 0 || len(d.Entry) > 2 || !(d.StopATR > 0 && d.StopATR <= 20) || !(d.TakeATR > 0 && d.TakeATR <= 100) || !(d.RiskPercent > 0 && d.RiskPercent <= 0.5) || d.TimeoutBars < 1 || d.TimeoutBars > 1000 || d.CooldownBars < 0 || d.CooldownBars > 1000 {
		return fmt.Errorf("invalid definition or risk limits")
	}
	for name, i := range d.Indicators {
		if !regexp.MustCompile(`^[a-z][a-z0-9_]{0,31}$`).MatchString(name) {
			return fmt.Errorf("invalid indicator name")
		}
		if name == "" || i.Period < 2 || i.Period > 500 || frame(i.Timeframe) < frame(d.Timeframe) || !contains([]string{"SMA", "EMA", "RSI", "ATR", "VOLUME_SMA", "PRICE", "MACD", "BB_UPPER", "BB_LOWER", "DONCHIAN_HIGH", "DONCHIAN_LOW", "ADX", "MACD_SIGNAL", "MACD_HISTOGRAM", "OPEN", "HIGH", "LOW", "VOLUME", "TURNOVER", "BODY", "RANGE", "ROC", "ZSCORE"}, i.Type) {
			return fmt.Errorf("unsupported indicator %s", name)
		}
	}
	for _, ind := range d.Indicators {
		if ind.SlowPeriod != 0 && (ind.SlowPeriod <= ind.Period || ind.SlowPeriod > 1000) {
			return fmt.Errorf("invalid slow period")
		}
		if ind.SignalPeriod != 0 && (ind.SignalPeriod < 2 || ind.SignalPeriod > 500) {
			return fmt.Errorf("invalid signal period")
		}
		if ind.Deviation != 0 && !(ind.Deviation > 0 && ind.Deviation <= 10) {
			return fmt.Errorf("invalid band deviation")
		}
	}
	if d.TrailingStop != nil {
		if !(d.TrailingStop.DistanceATR > 0 && d.TrailingStop.DistanceATR <= 20) {
			return fmt.Errorf("invalid trailing stop distance")
		}
		if d.TrailingStop.ActivationATR < 0 || d.TrailingStop.ActivationATR > 50 {
			return fmt.Errorf("invalid trailing stop activation")
		}
	}
	if d.TrailingATR != 0 && !(d.TrailingATR > 0 && d.TrailingATR <= 20) {
		return fmt.Errorf("invalid trailing atr")
	}
	if d.PartialExit != nil && (!(d.PartialExit.ATR > 0 && d.PartialExit.ATR < d.TakeATR) || !(d.PartialExit.Fraction > 0 && d.PartialExit.Fraction < 1)) {
		return fmt.Errorf("invalid partial exit")
	}
	if d.Funding != nil && d.Funding.Enabled && d.Funding.IntervalHours != 1 && d.Funding.IntervalHours != 4 && d.Funding.IntervalHours != 8 {
		return fmt.Errorf("unsupported funding schedule")
	}
	if d.Regime != nil {
		if err := validateCondition(*d.Regime, d.Indicators, 0); err != nil {
			return err
		}
	}
	for side, c := range d.Exit {
		if side != "BUY" && side != "SELL" {
			return fmt.Errorf("invalid exit side")
		}
		if err := validateCondition(c, d.Indicators, 0); err != nil {
			return err
		}
	}
	for _, p := range d.Parameters {
		if !(p.Min <= p.Default && p.Default <= p.Max && p.Step > 0) {
			return fmt.Errorf("invalid parameter range")
		}
	}
	for side, c := range d.Entry {
		if side != "BUY" && side != "SELL" {
			return fmt.Errorf("invalid direction")
		}
		if err := validateCondition(c, d.Indicators, 0); err != nil {
			return err
		}
	}
	return nil
}
func validateCondition(c Condition, indicators map[string]Indicator, depth int) error {
	if depth > 8 {
		return fmt.Errorf("condition too deep")
	}
	modes := 0
	if c.And != nil {
		modes++
		if len(c.And) < 1 || len(c.And) > 16 {
			return fmt.Errorf("invalid AND")
		}
		for _, n := range c.And {
			if err := validateCondition(n, indicators, depth+1); err != nil {
				return err
			}
		}
	}
	if c.Or != nil {
		modes++
		if len(c.Or) < 1 || len(c.Or) > 16 {
			return fmt.Errorf("invalid OR")
		}
		for _, n := range c.Or {
			if err := validateCondition(n, indicators, depth+1); err != nil {
				return err
			}
		}
	}
	if c.Not != nil {
		modes++
		if err := validateCondition(*c.Not, indicators, depth+1); err != nil {
			return err
		}
	}
	if c.Op != "" {
		if string(c.Right) == "null" || len(c.Right) == 0 {
			return fmt.Errorf("missing right operand")
		}
		modes++
		if _, ok := indicators[c.Left]; !ok {
			return fmt.Errorf("unknown left operand")
		}
		if !contains([]string{"gt", "lt", "gte", "lte", "crosses_above", "crosses_below", "within", "change_pct"}, c.Op) {
			return fmt.Errorf("unknown comparison")
		}
		var x float64
		var ref string
		var bounds []float64
		if c.Op == "within" {
			if json.Unmarshal(c.Right, &bounds) != nil || len(bounds) != 2 || bounds[0] > bounds[1] {
				return fmt.Errorf("invalid range")
			}
		} else if json.Unmarshal(c.Right, &x) != nil {
			if c.Op == "change_pct" || json.Unmarshal(c.Right, &ref) != nil {
				return fmt.Errorf("invalid right operand")
			}
			if _, ok := indicators[ref]; !ok {
				return fmt.Errorf("unknown right operand")
			}
		}
	}
	if modes != 1 || (c.Op == "" && (c.Left != "" || c.Right != nil)) {
		return fmt.Errorf("ambiguous condition")
	}
	return nil
}

func values(cs []datasets.Candle, ind Indicator) []*decimal.Decimal {
	closes := make([]decimal.Decimal, len(cs))
	for i, c := range cs {
		closes[i] = c.Close
		if ind.Type == "VOLUME_SMA" {
			closes[i] = c.Volume
		}
	}
	switch ind.Type {
	case "SMA", "VOLUME_SMA":
		return algorithms.CalculateSMA(closes, ind.Period)
	case "EMA":
		return algorithms.CalculateEMA(closes, ind.Period)
	case "RSI":
		return algorithms.CalculateRSI(closes, ind.Period)
	case "ATR":
		return algorithms.CalculateATR(cs, ind.Period)
	case "BB_UPPER":
		return algorithms.CalculateBollingerBands(closes, ind.Period, bandDeviation(ind)).Upper
	case "BB_LOWER":
		return algorithms.CalculateBollingerBands(closes, ind.Period, bandDeviation(ind)).Lower
	case "DONCHIAN_HIGH":
		return algorithms.CalculateDonchianChannels(cs, ind.Period).Upper
	case "DONCHIAN_LOW":
		return algorithms.CalculateDonchianChannels(cs, ind.Period).Lower
	case "MACD":
		fast := algorithms.CalculateEMA(closes, ind.Period)
		slowPeriod := ind.SlowPeriod
		if slowPeriod == 0 {
			slowPeriod = ind.Period * 2
		}
		slow := algorithms.CalculateEMA(closes, slowPeriod)
		out := make([]*decimal.Decimal, len(cs))
		for i := range out {
			if fast[i] != nil && slow[i] != nil {
				v := fast[i].Sub(*slow[i])
				out[i] = &v
			}
		}
		return out
	default:
		return extendedValues(cs, ind, closes)
	}
}

type valuePair struct {
	now, before float64
	ready       bool
}

func evaluate(c Condition, vs map[string]valuePair) (bool, bool) {
	if c.Not != nil {
		v, ok := evaluate(*c.Not, vs)
		return !v, ok
	}
	if c.And != nil || c.Or != nil {
		nodes := c.And
		result := true
		if c.Or != nil {
			nodes = c.Or
			result = false
		}
		for _, n := range nodes {
			v, ok := evaluate(n, vs)
			if !ok {
				return false, false
			}
			if c.And != nil {
				result = result && v
			} else {
				result = result || v
			}
		}
		return result, true
	}
	l := vs[c.Left]
	if !l.ready {
		return false, false
	}
	var r float64
	var ref string
	previous := 0.0
	if c.Op == "within" {
		var bounds []float64
		_ = json.Unmarshal(c.Right, &bounds)
		return l.now >= bounds[0] && l.now <= bounds[1], true
	}
	if json.Unmarshal(c.Right, &r) != nil {
		_ = json.Unmarshal(c.Right, &ref)
		v := vs[ref]
		if !v.ready {
			return false, false
		}
		r = v.now
		previous = v.before
	} else {
		previous = r
	}
	switch c.Op {
	case "gt":
		return l.now > r, true
	case "lt":
		return l.now < r, true
	case "gte":
		return l.now >= r, true
	case "lte":
		return l.now <= r, true
	case "crosses_above":
		return l.now > r && l.before <= previous, true
	case "crosses_below":
		return l.now < r && l.before >= previous, true
	case "change_pct":
		if l.before == 0 {
			return false, false
		}
		change := (l.now/l.before - 1) * 100
		if r < 0 {
			return change <= r, true
		}
		return change >= r, true
	}
	return false, false
}

func bandDeviation(ind Indicator) float64 {
	if ind.Deviation == 0 {
		return 2
	}
	return ind.Deviation
}

func Run(req Request) (*Result, error) {
	if req.Context == nil {
		req.Context = context.Background()
	}
	d := req.Definition
	if req.Mode != "" && req.Mode != "backtest" && req.Mode != "signal" && req.Mode != "validate" {
		return nil, fmt.Errorf("unsupported execution mode")
	}
	if err := d.Validate(); err != nil {
		return nil, err
	}
	if req.Mode == "validate" {
		blocked := PaperBlockers(d)
		return &Result{Signals: []backtest.Signal{}, PaperEligible: len(blocked) == 0, Limitations: blocked}, nil
	}
	if !contains(d.Exchanges, req.Exchange) || !contains(d.Symbols, req.Symbol) || req.AsOf.IsZero() || req.From.IsZero() || req.From.After(req.AsOf) || (req.From.Equal(req.AsOf) && req.Mode != "signal") {
		return nil, fmt.Errorf("invalid scope")
	}
	frames := map[string]bool{d.Timeframe: true}
	for _, ind := range d.Indicators {
		frames[ind.Timeframe] = true
	}
	window := HistoryWindow(d)
	for tf := range frames {
		cs := req.Series[tf]
		if len(cs) < 2 {
			return nil, fmt.Errorf("missing series %s", tf)
		}
		seconds := frame(tf)
		if d.SchemaVersion == 2 {
			before := sort.Search(len(cs), func(i int) bool { return cs[i].OpenTime.Add(time.Duration(seconds) * time.Second).After(req.From) })
			if before < window+1 {
				return nil, fmt.Errorf("insufficient bounded-window warmup %s", tf)
			}
		}
		for i, c := range cs {
			if !c.IsClosed || c.Exchange != req.Exchange || c.Symbol != req.Symbol || c.Category != "linear" || c.Interval != tf || c.OpenTime.Unix()%int64(seconds) != 0 || c.OpenTime.Add(time.Duration(seconds)*time.Second).After(req.AsOf) || !c.Low.IsPositive() || c.High.Cmp(c.Low) < 0 || c.Open.Cmp(c.Low) < 0 || c.Open.Cmp(c.High) > 0 || c.Close.Cmp(c.Low) < 0 || c.Close.Cmp(c.High) > 0 || c.Volume.IsNegative() {
				return nil, fmt.Errorf("invalid candle %s/%d", tf, i)
			}
			if i > 0 && !c.OpenTime.Equal(cs[i-1].OpenTime.Add(time.Duration(seconds)*time.Second)) {
				return nil, fmt.Errorf("incomplete coverage %s", tf)
			}
		}
		if cs[0].OpenTime.After(req.From.Add(-time.Duration(seconds)*time.Second)) || cs[len(cs)-1].OpenTime.Add(time.Duration(seconds*2)*time.Second).Before(req.AsOf) {
			return nil, fmt.Errorf("insufficient boundary coverage %s", tf)
		}
	}
	series := map[string][]*decimal.Decimal{}
	names := make([]string, 0, len(d.Indicators))
	for name, ind := range d.Indicators {
		if d.SchemaVersion == 1 {
			series[name] = values(req.Series[ind.Timeframe], ind)
		} else {
			series[name] = make([]*decimal.Decimal, len(req.Series[ind.Timeframe]))
		}
		names = append(names, name)
	}
	sort.Strings(names)
	candles := req.Series[d.Timeframe]
	atr := algorithms.CalculateATR(candles, 14)
	if d.SchemaVersion == 2 {
		atr = make([]*decimal.Decimal, len(candles))
		for i := range candles {
			if i%256 == 0 {
				if err := req.Context.Err(); err != nil {
					return nil, err
				}
			}
			if i >= window-1 {
				atr[i] = boundedValue(candles, Indicator{Type: "ATR", Period: 14, Timeframe: d.Timeframe}, i, window)
			}
		}
	}
	signals := []backtest.Signal{}
	last := -100000
	if len(req.Funding) > 0 && (d.Funding == nil || !d.Funding.Enabled) {
		return nil, fmt.Errorf("funding data requires enabled funding configuration")
	}
	policy := backtest.ReplayPolicy{
		ExitAtOpen:      map[int]map[string]bool{},
		PartialTargets:  map[int]backtest.PartialTarget{},
		TrailingTargets: map[int]backtest.TrailingTarget{},
		Funding:         req.Funding,
	}
	for bar, c := range candles {
		if bar%256 == 0 {
			if err := req.Context.Err(); err != nil {
				return nil, err
			}
		}
		decision := c.OpenTime.Add(time.Duration(frame(d.Timeframe)) * time.Second)
		if decision.Before(req.From) || (bar+1 >= len(candles) && req.Mode != "signal") || atr[bar] == nil || !atr[bar].IsPositive() {
			continue
		}
		vs := map[string]valuePair{}
		ready := true
		for _, name := range names {
			ind := d.Indicators[name]
			cs := req.Series[ind.Timeframe]
			idx := sort.Search(len(cs), func(i int) bool {
				return cs[i].OpenTime.Add(time.Duration(frame(ind.Timeframe)) * time.Second).After(decision)
			}) - 1
			if d.SchemaVersion == 2 && idx >= 1 {
				if series[name][idx] == nil {
					series[name][idx] = boundedValue(cs, ind, idx, window)
				}
				if series[name][idx-1] == nil {
					series[name][idx-1] = boundedValue(cs, ind, idx-1, window)
				}
			}
			if idx < 1 || series[name][idx] == nil || series[name][idx-1] == nil {
				ready = false
				break
			}
			vs[name] = valuePair{series[name][idx].Float64(), series[name][idx-1].Float64(), true}
		}
		if !ready {
			if !decision.Before(req.From) && len(signals) == 0 && decision.Equal(req.From) {
				return nil, fmt.Errorf("insufficient warmup")
			}
			continue
		}
		policy.ExitAtOpen[bar+1] = map[string]bool{}
		for side, node := range d.Exit {
			hit, ok := evaluate(node, vs)
			if ok && hit {
				policy.ExitAtOpen[bar+1][side] = true
			}
		}
		if bar-last <= d.CooldownBars {
			continue
		}
		if d.Regime != nil {
			hit, ok := evaluate(*d.Regime, vs)
			if !ok || !hit {
				continue
			}
		}
		buy, short := false, false
		if node, ok := d.Entry["BUY"]; ok {
			buy, _ = evaluate(node, vs)
		}
		if node, ok := d.Entry["SELL"]; ok {
			short, _ = evaluate(node, vs)
		}
		if buy == short {
			continue
		}
		side := "BUY"
		sign := decimal.FromInt64(1)
		if short {
			side = "SELL"
			sign = decimal.FromInt64(-1)
		}
		stop := c.Close.Sub(atr[bar].Mul(decimal.FromFloat64(d.StopATR)).Mul(sign))
		take := c.Close.Add(atr[bar].Mul(decimal.FromFloat64(d.TakeATR)).Mul(sign))
		if !stop.IsPositive() || !take.IsPositive() {
			continue
		}
		if req.IncludeFeatures && (len(signals)+1)*len(d.Indicators) > 500000 {
			return nil, fmt.Errorf("signal feature resource bound exceeded; choose a smaller explicit research window")
		}
		var features map[string]float64
		if req.IncludeFeatures {
			features = map[string]float64{}
			for name, value := range vs {
				if value.ready {
					features[name] = value.now
				}
			}
		}
		signals = append(signals, backtest.Signal{Features: features, Side: side, DecisionTime: decision, DecisionBar: bar, EntryBar: bar + 1, StopLoss: stop, TakeProfit: take, Reason: d.ID + "@" + strconv.Itoa(d.Version)})
		if d.PartialExit != nil {
			policy.PartialTargets[bar] = backtest.PartialTarget{Price: c.Close.Add(atr[bar].Mul(decimal.FromFloat64(d.PartialExit.ATR)).Mul(sign)), Fraction: decimal.FromFloat64(d.PartialExit.Fraction)}
		}
		ts := d.TrailingStop
		if ts == nil && d.TrailingATR > 0 {
			ts = &TrailingStop{DistanceATR: d.TrailingATR}
		}
		if ts != nil {
			var actPrice decimal.Decimal
			if ts.ActivationATR > 0 {
				actPrice = c.Close.Add(atr[bar].Mul(decimal.FromFloat64(ts.ActivationATR)).Mul(sign))
			}
			policy.TrailingTargets[bar] = backtest.TrailingTarget{
				ActivationPrice: actPrice,
				Distance:        atr[bar].Mul(decimal.FromFloat64(ts.DistanceATR)),
			}
		}
		last = bar
	}
	limitations := []string{}
	if d.Funding == nil || !d.Funding.Enabled {
		limitations = append(limitations, "FUNDING_NOT_MODELED")
	} else if req.PublishedFundingEvents && req.Mode != "signal" {
		for i, event := range req.Funding {
			if !event.At.After(req.From) || !event.At.Before(req.AsOf) || i > 0 && !event.At.After(req.Funding[i-1].At) || !event.Mark.IsPositive() {
				return nil, fmt.Errorf("invalid published funding event")
			}
		}
	} else if req.Mode != "signal" {
		seconds := int64(d.Funding.IntervalHours * 3600)
		expected := map[int64]bool{}
		for at := (req.From.Unix()/seconds + 1) * seconds; at < req.AsOf.Unix(); at += seconds {
			expected[at] = false
		}
		for _, event := range req.Funding {
			if _, ok := expected[event.At.Unix()]; !ok {
				return nil, fmt.Errorf("unexpected funding timestamp")
			}
			if expected[event.At.Unix()] {
				return nil, fmt.Errorf("duplicate funding")
			}
			expected[event.At.Unix()] = true
		}
		for _, present := range expected {
			if !present {
				return nil, fmt.Errorf("incomplete funding coverage")
			}
		}
	}
	eligible := PaperBlockers(d)
	if req.Mode == "signal" {
		return &Result{Signals: signals, PaperEligible: len(eligible) == 0, Limitations: eligible}, nil
	}
	if !req.Capital.IsPositive() || req.Fee.IsNegative() || req.Fee.Cmp(decimal.FromInt64(1)) >= 0 || req.Slippage.IsNegative() || req.Slippage.Cmp(decimal.FromInt64(1)) >= 0 {
		return nil, fmt.Errorf("invalid execution costs")
	}
	cfg := backtest.DefaultConfig()
	cfg.StartingBalance = req.Capital
	cfg.TakerFeeRate = req.Fee
	cfg.SlippageRate = req.Slippage
	cfg.RiskPerTradeRate = decimal.FromFloat64(d.RiskPercent / 100)
	cfg.MaxHoldingBars = d.TimeoutBars
	var report *backtest.BacktestReport
	var err error
	if d.SchemaVersion == 1 && len(d.Exit) == 0 && d.PartialExit == nil && d.TrailingStop == nil && d.TrailingATR == 0 && (d.Funding == nil || !d.Funding.Enabled) {
		report, err = backtest.NewEngine(cfg).RunSignals(candles, signals, d.ID)
	} else {
		report, err = backtest.NewEngine(cfg).RunSignalsWithPolicy(candles, signals, d.ID, policy)
	}
	if err != nil {
		return nil, err
	}
	report.Metadata["evaluation_semantics"] = map[int]string{1: "legacy_prefix_v1", 2: "bounded_window_v2"}[d.SchemaVersion]
	report.Metadata["return_percent"] = report.NetPnL.Float64() / req.Capital.Float64() * 100
	returns := []float64{}
	for i := 1; i < len(report.EquitySeries); i++ {
		before := report.EquitySeries[i-1].Equity.Float64()
		if before > 0 {
			returns = append(returns, report.EquitySeries[i].Equity.Float64()/before-1)
		}
	}
	var sharpe *float64
	if len(returns) > 1 {
		mean := 0.0
		for _, v := range returns {
			mean += v
		}
		mean /= float64(len(returns))
		variance := 0.0
		for _, v := range returns {
			variance += (v - mean) * (v - mean)
		}
		variance /= float64(len(returns) - 1)
		if variance > 0 {
			value := mean / math.Sqrt(variance)
			sharpe = &value
		}
	}
	report.Metadata["observed_period_sharpe"] = sharpe
	report.Metadata["monte_carlo"] = map[string]interface{}{"status": "INSUFFICIENT_TRADES", "required": 10, "observed": report.TotalTrades}
	if report.TotalTrades >= 10 {
		cfg := simulation.DefaultMonteCarloConfig()
		cfg.StartingBalance = req.Capital
		cfg.RandomSeed = req.Seed
		cfg.Iterations = 200
		cfg.RuinThreshold = 10
		mc, err := simulation.RunMonteCarlo(report.Trades, cfg)
		if err != nil {
			return nil, err
		}
		report.Metadata["monte_carlo"] = mc
	}
	return &Result{Signals: signals, Report: report, PaperEligible: len(eligible) == 0, Limitations: append(limitations, eligible...)}, nil
}

// PaperBlockers is the executable capability boundary shared with Laravel eligibility.
func PaperBlockers(d Definition) []string {
	out := []string{}
	if d.SchemaVersion != 2 {
		out = append(out, "PAPER_REQUIRES_SCHEMA_V2")
	}
	if d.Timeframe != "15" {
		out = append(out, "PAPER_REQUIRES_15M")
	}
	if len(d.Exit) > 0 {
		out = append(out, "CUSTOM_EXIT_PAPER_PARITY_UNVERIFIED")
	}
	if d.PartialExit != nil {
		out = append(out, "PARTIAL_EXIT_PAPER_PARITY_UNVERIFIED")
	}
	if d.CooldownBars != 0 {
		out = append(out, "COOLDOWN_PAPER_PARITY_UNVERIFIED")
	}
	if d.Funding != nil && d.Funding.Enabled && d.Funding.IntervalHours != 8 {
		out = append(out, "PAPER_FUNDING_SCHEDULE_UNVERIFIED")
	}
	if d.Funding == nil || !d.Funding.Enabled {
		out = append(out, "FUNDING_NOT_MODELED")
	}
	return out
}
