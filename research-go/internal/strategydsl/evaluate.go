// Package strategydsl evaluates data-only strategy definitions. It cannot access accounts or exchanges.
package strategydsl

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"time"

	"github.com/traderbybit/research-go/internal/algorithms"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
)

type Indicator struct {
	Type      string `json:"type"`
	Period    int    `json:"period"`
	Timeframe string `json:"timeframe"`
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
	SchemaVersion int                  `json:"schema_version"`
	ID            string               `json:"id"`
	Version       int                  `json:"version"`
	Symbols       []string             `json:"symbols"`
	Exchanges     []string             `json:"exchanges"`
	Timeframe     string               `json:"timeframe"`
	Indicators    map[string]Indicator `json:"indicators"`
	Entry         map[string]Condition `json:"entry"`
	StopATR       float64              `json:"stop_atr"`
	TakeATR       float64              `json:"take_atr"`
	RiskPercent   float64              `json:"risk_percent"`
	TimeoutBars   int                  `json:"timeout_bars"`
	CooldownBars  int                  `json:"cooldown_bars"`
}
type Request struct {
	Definition Definition                   `json:"definition"`
	Exchange   string                       `json:"exchange"`
	Symbol     string                       `json:"symbol"`
	Series     map[string][]datasets.Candle `json:"series"`
	AsOf       time.Time                    `json:"as_of"`
	From       time.Time                    `json:"from"`
	Capital    decimal.Decimal              `json:"capital"`
	Fee        decimal.Decimal              `json:"fee"`
	Slippage   decimal.Decimal              `json:"slippage"`
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
	if d.SchemaVersion != 1 || d.Version < 1 || len(d.ID) < 6 || d.ID[:5] != "user_" || frame(d.Timeframe) == 0 || len(d.Indicators) == 0 || len(d.Indicators) > 32 || len(d.Entry) == 0 || len(d.Entry) > 2 || !(d.StopATR > 0 && d.StopATR <= 20) || !(d.TakeATR > 0 && d.TakeATR <= 100) || !(d.RiskPercent > 0 && d.RiskPercent <= 0.5) || d.TimeoutBars < 1 || d.TimeoutBars > 1000 || d.CooldownBars < 0 || d.CooldownBars > 1000 {
		return fmt.Errorf("invalid definition or risk limits")
	}
	for name, i := range d.Indicators {
		if name == "" || i.Period < 2 || i.Period > 500 || frame(i.Timeframe) < frame(d.Timeframe) || !contains([]string{"SMA", "EMA", "RSI", "ATR", "VOLUME_SMA", "PRICE", "MACD", "BB_UPPER", "BB_LOWER", "DONCHIAN_HIGH", "DONCHIAN_LOW"}, i.Type) {
			return fmt.Errorf("unsupported indicator %s", name)
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
		return algorithms.CalculateBollingerBands(closes, ind.Period, 2).Upper
	case "BB_LOWER":
		return algorithms.CalculateBollingerBands(closes, ind.Period, 2).Lower
	case "DONCHIAN_HIGH":
		return algorithms.CalculateDonchianChannels(cs, ind.Period).Upper
	case "DONCHIAN_LOW":
		return algorithms.CalculateDonchianChannels(cs, ind.Period).Lower
	case "MACD":
		fast := algorithms.CalculateEMA(closes, ind.Period)
		slow := algorithms.CalculateEMA(closes, ind.Period*2)
		out := make([]*decimal.Decimal, len(cs))
		for i := range out {
			if fast[i] != nil && slow[i] != nil {
				v := fast[i].Sub(*slow[i])
				out[i] = &v
			}
		}
		return out
	default:
		out := make([]*decimal.Decimal, len(cs))
		for i := range closes {
			v := closes[i]
			out[i] = &v
		}
		return out
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

func Run(req Request) (*Result, error) {
	d := req.Definition
	if err := d.Validate(); err != nil {
		return nil, err
	}
	if !contains(d.Exchanges, req.Exchange) || !contains(d.Symbols, req.Symbol) || req.AsOf.IsZero() || req.From.IsZero() || !req.From.Before(req.AsOf) {
		return nil, fmt.Errorf("invalid scope")
	}
	frames := map[string]bool{d.Timeframe: true}
	for _, ind := range d.Indicators {
		frames[ind.Timeframe] = true
	}
	for tf := range frames {
		cs := req.Series[tf]
		if len(cs) < 2 {
			return nil, fmt.Errorf("missing series %s", tf)
		}
		seconds := frame(tf)
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
		series[name] = values(req.Series[ind.Timeframe], ind)
		names = append(names, name)
	}
	sort.Strings(names)
	candles := req.Series[d.Timeframe]
	atr := algorithms.CalculateATR(candles, 14)
	signals := []backtest.Signal{}
	last := -100000
	for bar, c := range candles {
		decision := c.OpenTime.Add(time.Duration(frame(d.Timeframe)) * time.Second)
		if decision.Before(req.From) || bar+1 >= len(candles) || bar-last <= d.CooldownBars || atr[bar] == nil || !atr[bar].IsPositive() {
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
		signals = append(signals, backtest.Signal{Side: side, DecisionTime: decision, DecisionBar: bar, EntryBar: bar + 1, StopLoss: stop, TakeProfit: take, Reason: d.ID + "@" + strconv.Itoa(d.Version)})
		last = bar
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
	report, err := backtest.NewEngine(cfg).RunSignals(candles, signals, d.ID)
	if err != nil {
		return nil, err
	}
	return &Result{Signals: signals, Report: report, PaperEligible: false, Limitations: []string{"PAPER_PARITY_UNVERIFIED", "FUNDING_NOT_MODELED", "PARTIAL_EXITS_UNSUPPORTED", "CUSTOM_EXIT_RULES_UNSUPPORTED"}}, nil
}
