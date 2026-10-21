package auditdata

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

func TestLoadCandlesDerivesTheKnowledgeTimestampFromTheClose(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "market_candles.csv")

	content := "id,exchange,category,symbol,interval,open_time,open,high,low,close,volume,turnover,is_closed\n" +
		"1,bybit,linear,BTCUSDT,5,\"2026-10-17 08:00:00+00\",100,101,99,100.5,10,1000,1\n" +
		"2,bybit,linear,BTCUSDT,5,\"2026-10-17 08:05:00+00\",100.5,102,100,101,11,1100,1\n"
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	candles, err := LoadCandles(path)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(candles) != 2 {
		t.Fatalf("expected 2 candles, got %d", len(candles))
	}

	first := candles[0]
	if !first.CloseTime().Equal(time.Date(2026, 10, 17, 8, 5, 0, 0, time.UTC)) {
		t.Fatalf("unexpected close time %s", first.CloseTime())
	}
	if first.Close.Cmp(decimal.MustFromString("100.5")) != 0 {
		t.Fatalf("unexpected close %s", first.Close.String())
	}
}

func TestLoadCandlesRejectsAnOpenCandle(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "market_candles.csv")

	content := "id,exchange,category,symbol,interval,open_time,open,high,low,close,volume,turnover,is_closed\n" +
		"1,bybit,linear,BTCUSDT,5,\"2026-10-17 08:00:00+00\",100,101,99,100.5,10,1000,0\n"
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if _, err := LoadCandles(path); err == nil {
		t.Fatal("an open candle must be rejected: it has no known close")
	}
}

func TestBuildDatasetReportsGapsWithoutFillingThem(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "market_candles.csv")

	// A missing 5m bar between 08:05 and 08:15.
	content := "id,exchange,category,symbol,interval,open_time,open,high,low,close,volume,turnover,is_closed\n" +
		"1,bybit,linear,BTCUSDT,5,\"2026-10-17 08:00:00+00\",100,101,99,100.5,10,1000,1\n" +
		"2,bybit,linear,BTCUSDT,5,\"2026-10-17 08:05:00+00\",100.5,102,100,101,11,1100,1\n" +
		"3,bybit,linear,BTCUSDT,5,\"2026-10-17 08:15:00+00\",101,102,100,101.5,12,1200,1\n" +
		"4,bybit,linear,BTCUSDT,60,\"2026-10-17 08:00:00+00\",100,101,99,100.5,10,1000,1\n"
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	candles, err := LoadCandles(path)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	dataset, quality, err := BuildDataset(candles, nil, nil, Options{
		Symbol:        "BTCUSDT",
		EntryInterval: "5",
		HTFInterval:   "60",
		Now:           time.Date(2026, 10, 17, 9, 0, 0, 0, time.UTC),
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if len(dataset.Bars) != 3 {
		t.Fatalf("the gap must not be filled, got %d entry bars", len(dataset.Bars))
	}
	if len(quality.Gaps) != 1 {
		t.Fatalf("expected exactly 1 reported gap, got %d", len(quality.Gaps))
	}
	if quality.Gaps[0].Missing != 1 {
		t.Fatalf("expected 1 missing bar, got %d", quality.Gaps[0].Missing)
	}
	if !quality.Gaps[0].From.Equal(time.Date(2026, 10, 17, 8, 5, 0, 0, time.UTC)) {
		t.Fatalf("unexpected gap start %s", quality.Gaps[0].From)
	}

	// The knowledge timestamp of a candle is its close time.
	for _, b := range dataset.Bars {
		if !b.KnownAt.Equal(b.CloseTime) {
			t.Fatalf("known_at %s must equal the close time %s", b.KnownAt, b.CloseTime)
		}
	}
}

func TestBuildDatasetRejectsAMissingInterval(t *testing.T) {
	candles := []Candle{{
		Symbol:   "BTCUSDT",
		Interval: "5",
		OpenTime: time.Date(2026, 10, 17, 8, 0, 0, 0, time.UTC),
		Open:     decimal.MustFromString("100"),
		High:     decimal.MustFromString("101"),
		Low:      decimal.MustFromString("99"),
		Close:    decimal.MustFromString("100.5"),
		Volume:   decimal.MustFromString("10"),
		IsClosed: true,
	}}

	if _, _, err := BuildDataset(candles, nil, nil, Options{Symbol: "BTCUSDT", EntryInterval: "5", HTFInterval: "60"}); err == nil {
		t.Fatal("a missing higher timeframe series must be reported")
	}
}

func TestIntervalDuration(t *testing.T) {
	cases := map[string]time.Duration{
		"5":     5 * time.Minute,
		"15":    15 * time.Minute,
		"30":    30 * time.Minute,
		"60":    time.Hour,
		"15min": 15 * time.Minute,
		"4h":    4 * time.Hour,
	}

	for interval, expected := range cases {
		if got := IntervalDuration(interval); got != expected {
			t.Fatalf("interval %q: expected %s, got %s", interval, expected, got)
		}
	}

	if got := IntervalDuration("unknown"); got != 0 {
		t.Fatalf("an unknown interval must not produce a duration, got %s", got)
	}
}
