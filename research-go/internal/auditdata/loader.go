// Package auditdata loads the locally stored forward-paper research archive into the research
// candidate pipeline.
//
// The archive is a bounded, single export of the paper research database. Every row carries its
// own timestamps, so the loader derives the knowledge timestamp of a row instead of assuming it:
//
//   - a candle is known at its CLOSE time (open_time + interval); the archive contains closed
//     candles only and the loader rejects open candles,
//   - a funding event is known at its funding timestamp,
//   - an open interest snapshot is known at its observation timestamp.
//
// The loader never repairs, interpolates or forward fills a gap. Gaps are reported so the caller
// can decide whether a dataset is usable.
package auditdata

import (
	"encoding/csv"
	"fmt"
	"io"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/traderbybit/research-go/internal/candidates"
	"github.com/traderbybit/research-go/internal/decimal"
)

// Candle is one closed market candle of the archive.
type Candle struct {
	Symbol   string
	Interval string
	OpenTime time.Time
	Open     decimal.Decimal
	High     decimal.Decimal
	Low      decimal.Decimal
	Close    decimal.Decimal
	Volume   decimal.Decimal
	IsClosed bool
}

// CloseTime returns the moment the candle finished, which is also its knowledge timestamp.
func (c Candle) CloseTime() time.Time {
	return c.OpenTime.Add(IntervalDuration(c.Interval))
}

// Funding is one published funding rate event.
type Funding struct {
	Symbol    string
	FundingAt time.Time
	Rate      decimal.Decimal
	MarkPrice decimal.Decimal
}

// OpenInterest is one published open interest snapshot.
type OpenInterest struct {
	Symbol       string
	Interval     string
	ObservedAt   time.Time
	OpenInterest decimal.Decimal
}

// IntervalDuration converts a Bybit interval label into a duration.
func IntervalDuration(interval string) time.Duration {
	switch strings.TrimSpace(interval) {
	case "1":
		return time.Minute
	case "3":
		return 3 * time.Minute
	case "5":
		return 5 * time.Minute
	case "15":
		return 15 * time.Minute
	case "30":
		return 30 * time.Minute
	case "60":
		return time.Hour
	case "120":
		return 2 * time.Hour
	case "240":
		return 4 * time.Hour
	case "D":
		return 24 * time.Hour
	}

	if strings.HasSuffix(interval, "min") {
		if v, err := strconv.Atoi(strings.TrimSuffix(interval, "min")); err == nil {
			return time.Duration(v) * time.Minute
		}
	}
	if strings.HasSuffix(interval, "h") {
		if v, err := strconv.Atoi(strings.TrimSuffix(interval, "h")); err == nil {
			return time.Duration(v) * time.Hour
		}
	}

	return 0
}

// ParseTime parses the timestamp format of the archive.
func ParseTime(value string) (time.Time, error) {
	value = strings.TrimSpace(value)
	for _, layout := range []string{time.RFC3339, "2006-01-02 15:04:05-07", "2006-01-02 15:04:05Z07:00", "2006-01-02 15:04:05"} {
		if ts, err := time.Parse(layout, value); err == nil {
			return ts.UTC(), nil
		}
	}
	return time.Time{}, fmt.Errorf("cannot parse timestamp %q", value)
}

func readCSV(path string) ([][]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()

	r := csv.NewReader(f)
	r.FieldsPerRecord = -1
	rows, err := r.ReadAll()
	if err != nil && err != io.EOF {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, fmt.Errorf("%s is empty", path)
	}

	return rows, nil
}

func indexHeader(header []string) map[string]int {
	idx := make(map[string]int, len(header))
	for i, h := range header {
		idx[strings.TrimSpace(strings.ToLower(h))] = i
	}
	return idx
}

func field(row []string, idx map[string]int, name string) (string, error) {
	i, ok := idx[name]
	if !ok {
		return "", fmt.Errorf("column %q is missing", name)
	}
	if i >= len(row) {
		return "", fmt.Errorf("column %q is out of range", name)
	}
	return strings.TrimSpace(row[i]), nil
}

// LoadCandles loads the closed candles of the archive.
func LoadCandles(path string) ([]Candle, error) {
	rows, err := readCSV(path)
	if err != nil {
		return nil, err
	}

	idx := indexHeader(rows[0])
	out := make([]Candle, 0, len(rows)-1)

	for i, row := range rows[1:] {
		symbol, err := field(row, idx, "symbol")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		interval, err := field(row, idx, "interval")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		openTimeRaw, err := field(row, idx, "open_time")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		openTime, err := ParseTime(openTimeRaw)
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}

		values := make(map[string]decimal.Decimal, 6)
		for _, name := range []string{"open", "high", "low", "close", "volume", "turnover"} {
			raw, err := field(row, idx, name)
			if err != nil {
				return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
			}
			if raw == "" {
				values[name] = decimal.Zero()
				continue
			}
			d, err := decimal.FromString(raw)
			if err != nil {
				return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
			}
			values[name] = d
		}

		closedRaw, err := field(row, idx, "is_closed")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		isClosed := closedRaw == "1" || strings.EqualFold(closedRaw, "true")
		if !isClosed {
			// The archive must only contain closed candles: an open candle has no known close.
			return nil, fmt.Errorf("%s row %d: candle %s is not closed", path, i+2, symbol)
		}

		out = append(out, Candle{
			Symbol:   symbol,
			Interval: interval,
			OpenTime: openTime,
			Open:     values["open"],
			High:     values["high"],
			Low:      values["low"],
			Close:    values["close"],
			Volume:   values["volume"],
			IsClosed: isClosed,
		})
	}

	return out, nil
}

// LoadFunding loads the funding events of the archive.
func LoadFunding(path string) ([]Funding, error) {
	rows, err := readCSV(path)
	if err != nil {
		return nil, err
	}

	idx := indexHeader(rows[0])
	out := make([]Funding, 0, len(rows)-1)

	for i, row := range rows[1:] {
		symbol, err := field(row, idx, "symbol")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		atRaw, err := field(row, idx, "funding_at")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		at, err := ParseTime(atRaw)
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		rateRaw, err := field(row, idx, "rate")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		rate, err := decimal.FromString(rateRaw)
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		markRaw, _ := field(row, idx, "mark_price")
		mark := decimal.Zero()
		if markRaw != "" {
			mark, err = decimal.FromString(markRaw)
			if err != nil {
				return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
			}
		}

		out = append(out, Funding{Symbol: symbol, FundingAt: at, Rate: rate, MarkPrice: mark})
	}

	return out, nil
}

// LoadOpenInterest loads the open interest snapshots of the archive.
func LoadOpenInterest(path string) ([]OpenInterest, error) {
	rows, err := readCSV(path)
	if err != nil {
		return nil, err
	}

	idx := indexHeader(rows[0])
	out := make([]OpenInterest, 0, len(rows)-1)

	for i, row := range rows[1:] {
		symbol, err := field(row, idx, "symbol")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		interval, _ := field(row, idx, "interval")
		atRaw, err := field(row, idx, "observed_at")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		at, err := ParseTime(atRaw)
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		oiRaw, err := field(row, idx, "open_interest")
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}
		oi, err := decimal.FromString(oiRaw)
		if err != nil {
			return nil, fmt.Errorf("%s row %d: %w", path, i+2, err)
		}

		out = append(out, OpenInterest{Symbol: symbol, Interval: interval, ObservedAt: at, OpenInterest: oi})
	}

	return out, nil
}

// Gap describes a missing interval in a candle series.
type Gap struct {
	Symbol   string
	Interval string
	From     time.Time
	To       time.Time
	Missing  int
}

// Quality is the data quality report of a loaded dataset.
type Quality struct {
	Rows            int            `json:"rows"`
	Symbols         []string       `json:"symbols"`
	Intervals       map[string]int `json:"intervals"`
	Gaps            []Gap          `json:"gaps"`
	FirstOpen       time.Time      `json:"first_open_utc"`
	LastClose       time.Time      `json:"last_close_utc"`
	FundingEvents   int            `json:"funding_events"`
	FundingFirst    time.Time      `json:"funding_first_utc"`
	FundingLast     time.Time      `json:"funding_last_utc"`
	OpenInterest    int            `json:"open_interest_events"`
	OpenInterestF   time.Time      `json:"open_interest_first_utc"`
	OpenInterestL   time.Time      `json:"open_interest_last_utc"`
	StaleAfterHours float64        `json:"stale_after_hours"`
}

// Options describes the dataset that must be built.
type Options struct {
	Symbol        string
	PeerSymbol    string
	EntryInterval string
	HTFInterval   string
	Now           time.Time
}

// BuildDataset builds a research dataset from the archive content.
//
// The returned quality report contains the coverage, the gaps and the staleness of the source. A
// gap is never filled: the bars that are missing simply do not exist in the dataset, and the
// candidate must not assume a continuous series.
func BuildDataset(candles []Candle, funding []Funding, oi []OpenInterest, opts Options) (candidates.Dataset, Quality, error) {
	quality := Quality{Intervals: map[string]int{}}

	entry := filterCandles(candles, opts.Symbol, opts.EntryInterval)
	htf := filterCandles(candles, opts.Symbol, opts.HTFInterval)
	peer := filterCandles(candles, opts.PeerSymbol, opts.EntryInterval)

	if len(entry) == 0 {
		return candidates.Dataset{}, quality, fmt.Errorf("no %s candles for %s", opts.EntryInterval, opts.Symbol)
	}
	if len(htf) == 0 {
		return candidates.Dataset{}, quality, fmt.Errorf("no %s candles for %s", opts.HTFInterval, opts.Symbol)
	}
	if opts.PeerSymbol != "" && len(peer) == 0 {
		return candidates.Dataset{}, quality, fmt.Errorf("no %s candles for peer %s", opts.EntryInterval, opts.PeerSymbol)
	}

	quality.Rows = len(candles)
	quality.Gaps = append(quality.Gaps, detectGaps(entry, opts.Symbol, opts.EntryInterval)...)
	quality.Gaps = append(quality.Gaps, detectGaps(htf, opts.Symbol, opts.HTFInterval)...)
	quality.FirstOpen = entry[0].OpenTime
	quality.LastClose = entry[len(entry)-1].CloseTime()

	symbols := map[string]bool{}
	intervals := map[string]bool{}
	for _, c := range candles {
		symbols[c.Symbol] = true
		intervals[c.Interval] = true
		quality.Intervals[c.Interval]++
	}
	for s := range symbols {
		quality.Symbols = append(quality.Symbols, s)
	}
	sort.Strings(quality.Symbols)

	if opts.Now.IsZero() {
		opts.Now = entry[len(entry)-1].CloseTime()
	}
	quality.StaleAfterHours = opts.Now.Sub(quality.LastClose).Hours()

	dataset := candidates.Dataset{
		Symbol:           opts.Symbol,
		EntryInterval:    opts.EntryInterval,
		DecisionInterval: opts.HTFInterval,
		PeerSymbol:       opts.PeerSymbol,
		Bars:             toCandidateBars(entry),
		HTFBars:          toCandidateBars(htf),
		PeerBars:         toCandidateBars(peer),
	}

	for _, f := range funding {
		if f.Symbol != opts.Symbol {
			continue
		}
		dataset.Funding = append(dataset.Funding, candidates.FundingEvent{
			Symbol:    f.Symbol,
			EventTime: f.FundingAt,
			KnownAt:   f.FundingAt,
			Rate:      f.Rate,
		})
	}
	sort.Slice(dataset.Funding, func(i, j int) bool {
		return dataset.Funding[i].EventTime.Before(dataset.Funding[j].EventTime)
	})

	for _, o := range oi {
		if o.Symbol != opts.Symbol {
			continue
		}
		dataset.OpenInterest = append(dataset.OpenInterest, candidates.OpenInterestEvent{
			Symbol:       o.Symbol,
			EventTime:    o.ObservedAt,
			KnownAt:      o.ObservedAt,
			OpenInterest: o.OpenInterest,
		})
	}
	sort.Slice(dataset.OpenInterest, func(i, j int) bool {
		return dataset.OpenInterest[i].EventTime.Before(dataset.OpenInterest[j].EventTime)
	})

	quality.FundingEvents = len(dataset.Funding)
	if quality.FundingEvents > 0 {
		quality.FundingFirst = dataset.Funding[0].EventTime
		quality.FundingLast = dataset.Funding[len(dataset.Funding)-1].EventTime
	}
	quality.OpenInterest = len(dataset.OpenInterest)
	if quality.OpenInterest > 0 {
		quality.OpenInterestF = dataset.OpenInterest[0].EventTime
		quality.OpenInterestL = dataset.OpenInterest[len(dataset.OpenInterest)-1].EventTime
	}

	return dataset, quality, nil
}

func filterCandles(candles []Candle, symbol, interval string) []Candle {
	out := make([]Candle, 0, len(candles))
	for _, c := range candles {
		if c.Symbol != symbol || c.Interval != interval {
			continue
		}
		out = append(out, c)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].OpenTime.Before(out[j].OpenTime) })
	return out
}

func toCandidateBars(candles []Candle) []candidates.Bar {
	out := make([]candidates.Bar, 0, len(candles))
	for _, c := range candles {
		out = append(out, candidates.Bar{
			Symbol:    c.Symbol,
			Interval:  c.Interval,
			OpenTime:  c.OpenTime,
			CloseTime: c.CloseTime(),
			// A closed candle becomes observable when it closes.
			KnownAt: c.CloseTime(),
			Open:    c.Open,
			High:    c.High,
			Low:     c.Low,
			Close:   c.Close,
			Volume:  c.Volume,
		})
	}
	return out
}

func detectGaps(candles []Candle, symbol, interval string) []Gap {
	gaps := make([]Gap, 0, 4)
	if len(candles) < 2 {
		return gaps
	}

	step := IntervalDuration(interval)
	if step <= 0 {
		return gaps
	}

	for i := 1; i < len(candles); i++ {
		delta := candles[i].OpenTime.Sub(candles[i-1].OpenTime)
		if delta <= step {
			continue
		}
		gaps = append(gaps, Gap{
			Symbol:   symbol,
			Interval: interval,
			From:     candles[i-1].OpenTime,
			To:       candles[i].OpenTime,
			Missing:  int(delta/step) - 1,
		})
	}

	return gaps
}
