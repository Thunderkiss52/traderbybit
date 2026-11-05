package importer

import (
	"archive/zip"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const candle = "1704067200000,100.000000000001,102,99,101,10,1704067259999,1000,4,5,500,0\n"

func fixture(t *testing.T, name, body string, flags uint16) (Request, string) {
	t.Helper()
	dir := t.TempDir()
	path := filepath.Join(dir, "source.zip")
	f, e := os.Create(path)
	if e != nil {
		t.Fatal(e)
	}
	w := zip.NewWriter(f)
	entry, e := w.CreateHeader(&zip.FileHeader{Name: name, Method: zip.Deflate, Flags: flags})
	if e != nil {
		t.Fatal(e)
	}
	entry.Write([]byte(body))
	w.Close()
	f.Close()
	return Request{Path: path, Output: filepath.Join(dir, "out"), Filename: "BTCUSDT-1m-2024-01.zip", Family: "klines", Interval: "1m", SourceFrom: 1704067200000, SourceTo: 1706745600000, From: 1704067200000, To: 1704067260000, MaxBytes: 2000000000, MaxFileBytes: 1500000000, MaxEntries: 100}, dir
}
func TestOfficialHeaderHeaderlessPrecisionAndDuplicates(t *testing.T) {
	for _, prefix := range []string{"", "open_time,open,high,low,close,volume,close_time,quote_volume,count,taker_buy_volume,taker_buy_quote_volume,ignore\n"} {
		req, _ := fixture(t, "BTCUSDT-1m-2024-01.csv", prefix+candle+candle, 0)
		r, e := Parse(context.Background(), req, nil)
		if e != nil {
			t.Fatal(e)
		}
		if r.Rows != 1 || r.Duplicates != 1 || r.Missing != 0 {
			t.Fatal(r)
		}
		b, _ := os.ReadFile(req.Output)
		if !strings.Contains(string(b), "100.000000000001") {
			t.Fatal(string(b))
		}
	}
}
func TestUnsafeArchivesAndMalformedRowsNeverPublish(t *testing.T) {
	cases := []struct {
		name, entry, body string
		flags             uint16
	}{
		{"zip-slip", "../BTCUSDT-1m-2024-01.csv", candle, 0}, {"absolute", "/BTCUSDT.csv", candle, 0}, {"nested", "payload.zip", candle, 0}, {"encrypted", "BTCUSDT-1m-2024-01.csv", candle, 1},
		{"micros", "BTCUSDT-1m-2024-01.csv", strings.ReplaceAll(candle, "1704067200000", "1704067200000000"), 0},
		{"close", "BTCUSDT-1m-2024-01.csv", strings.ReplaceAll(candle, "1704067259999", "1704067260000"), 0},
		{"nan", "BTCUSDT-1m-2024-01.csv", strings.ReplaceAll(candle, "102", "NaN"), 0},
		{"bounds", "BTCUSDT-1m-2024-01.csv", strings.ReplaceAll(candle, "102", "98"), 0},
		{"contradiction", "BTCUSDT-1m-2024-01.csv", candle + strings.ReplaceAll(candle, "100.000000000001", "100.000000000002"), 0},
		{"long-line", "BTCUSDT-1m-2024-01.csv", strings.Repeat("1", 70000), 0},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			req, _ := fixture(t, c.entry, c.body, c.flags)
			if _, e := Parse(context.Background(), req, nil); e == nil {
				t.Fatal("accepted malformed input")
			}
			if _, e := os.Stat(req.Output); e == nil {
				t.Fatal("published partial output")
			}
		})
	}
	req, _ := fixture(t, "BTCUSDT-1m-2024-01.csv", candle, 0)
	os.WriteFile(req.Path, []byte("truncated"), 0600)
	if _, e := Parse(context.Background(), req, nil); e == nil {
		t.Fatal("accepted truncated zip")
	}
}
func TestAbsoluteExpansionAndCancellation(t *testing.T) {
	req, _ := fixture(t, "BTCUSDT-1m-2024-01.csv", candle, 0)
	req.MaxFileBytes = 10
	if _, e := Parse(context.Background(), req, nil); e == nil {
		t.Fatal("expansion bound ignored")
	}
	req.MaxFileBytes = 1500000000
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, e := Parse(ctx, req, nil); e == nil {
		t.Fatal("cancel ignored")
	}
}
func TestFundingArchivePreservesSignedRatesAndEventTimes(t *testing.T) {
	req, _ := fixture(t, "BTCUSDT-fundingRate-2024-01.csv", "calc_time,funding_interval_hours,last_funding_rate\n1704067200000,4,-0.000123456789\n", 0)
	req.Filename = "BTCUSDT-fundingRate-2024-01.zip"
	req.Family = "fundingRate"
	req.Interval = ""
	r, e := Parse(context.Background(), req, nil)
	if e != nil {
		t.Fatal(e)
	}
	if r.Rows != 1 {
		t.Fatal(r)
	}
	b, _ := os.ReadFile(req.Output)
	if !strings.Contains(string(b), "-0.000123456789") {
		t.Fatal(string(b))
	}
}
