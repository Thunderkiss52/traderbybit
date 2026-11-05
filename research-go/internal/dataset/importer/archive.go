// Package importer validates official Binance USD-M archives without database or network authority.
package importer

import (
	"archive/zip"
	"bufio"
	"context"
	"encoding/csv"
	"encoding/json"
	"fmt"
	"io"
	"math/big"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const Version = "binance-um-ms-v1"

type Request struct {
	Path         string `json:"path"`
	Output       string `json:"output"`
	Filename     string `json:"filename"`
	Family       string `json:"family"`
	Interval     string `json:"interval"`
	SourceFrom   int64  `json:"source_from"`
	SourceTo     int64  `json:"source_to"`
	From         int64  `json:"from"`
	To           int64  `json:"to"`
	MaxBytes     int64  `json:"max_bytes"`
	MaxFileBytes int64  `json:"max_file_bytes"`
	MaxEntries   int    `json:"max_entries"`
}
type Result struct {
	Rows            int64  `json:"rows"`
	Duplicates      int64  `json:"duplicates"`
	Missing         int64  `json:"missing"`
	ExpandedBytes   uint64 `json:"expanded_bytes"`
	CompressedBytes uint64 `json:"compressed_bytes"`
	Parser          string `json:"parser"`
	Header          bool   `json:"header"`
}
type Row struct {
	Time     int64  `json:"time"`
	Open     string `json:"open,omitempty"`
	High     string `json:"high,omitempty"`
	Low      string `json:"low,omitempty"`
	Close    string `json:"close,omitempty"`
	Volume   string `json:"volume,omitempty"`
	Turnover string `json:"turnover,omitempty"`
	Rate     string `json:"rate,omitempty"`
	Hours    int64  `json:"hours,omitempty"`
}

var number = regexp.MustCompile(`^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?$`)
var ms = regexp.MustCompile(`^[0-9]{13}$`)

func numeric(s string, positive bool, signed bool) (*big.Rat, error) {
	if !number.MatchString(s) || len(s) > 48 {
		return nil, fmt.Errorf("invalid fixed decimal")
	}
	v, ok := new(big.Rat).SetString(s)
	if !ok || positive && v.Sign() <= 0 || !signed && v.Sign() < 0 {
		return nil, fmt.Errorf("invalid decimal sign")
	}
	return v, nil
}
func timestamp(s string) (int64, error) {
	if !ms.MatchString(s) {
		return 0, fmt.Errorf("USD-M timestamps must be 13-digit milliseconds")
	}
	return strconv.ParseInt(s, 10, 64)
}
func step(interval string) int64 {
	return map[string]int64{"1m": 60000, "5m": 300000, "15m": 900000, "30m": 1800000, "1h": 3600000}[interval]
}
func validate(fields []string, req Request) (Row, error) {
	var row Row
	expected := 12
	if req.Family == "fundingRate" {
		expected = 3
	}
	if len(fields) != expected {
		return row, fmt.Errorf("expected %d columns", expected)
	}
	at, err := timestamp(fields[0])
	if err != nil {
		return row, err
	}
	row.Time = at
	if at < req.SourceFrom || at >= req.SourceTo {
		return row, fmt.Errorf("row outside declared archive period")
	}
	if req.Family == "fundingRate" {
		hours, e := strconv.ParseInt(fields[1], 10, 64)
		if e != nil || hours < 1 || hours > 24 {
			return row, fmt.Errorf("invalid authoritative funding interval")
		}
		if _, e = numeric(fields[2], false, true); e != nil {
			return row, e
		}
		row.Rate = fields[2]
		row.Hours = hours
		return row, nil
	}
	unit := step(req.Interval)
	if unit == 0 || at%unit != 0 {
		return row, fmt.Errorf("unaligned interval")
	}
	closeAt, e := timestamp(fields[6])
	if e != nil || closeAt != at+unit-1 {
		return row, fmt.Errorf("invalid close time")
	}
	values := make([]*big.Rat, 6)
	for i, index := range []int{1, 2, 3, 4, 5, 7} {
		v, e := numeric(fields[index], i < 4, false)
		if e != nil {
			return row, e
		}
		values[i] = v
	}
	if values[1].Cmp(values[2]) < 0 || values[1].Cmp(values[0]) < 0 || values[1].Cmp(values[3]) < 0 || values[2].Cmp(values[0]) > 0 || values[2].Cmp(values[3]) > 0 {
		return row, fmt.Errorf("invalid OHLC bounds")
	}
	count, e := strconv.ParseUint(fields[8], 10, 64)
	_ = count
	if e != nil {
		return row, fmt.Errorf("invalid trade count")
	}
	for _, i := range []int{9, 10, 11} {
		if _, e = numeric(fields[i], false, false); e != nil {
			return row, e
		}
	}
	row.Open = fields[1]
	row.High = fields[2]
	row.Low = fields[3]
	row.Close = fields[4]
	row.Volume = fields[5]
	row.Turnover = fields[7]
	return row, nil
}
func header(fields []string, family string) bool {
	expected := []string{"open_time", "open", "high", "low", "close", "volume", "close_time", "quote_volume", "count", "taker_buy_volume", "taker_buy_quote_volume", "ignore"}
	if family == "fundingRate" {
		expected = []string{"calc_time", "funding_interval_hours", "last_funding_rate"}
	}
	if len(fields) != len(expected) {
		return false
	}
	for i, s := range fields {
		if strings.TrimPrefix(s, "\ufeff") != expected[i] {
			return false
		}
	}
	return true
}
func Parse(ctx context.Context, req Request, progress func(int64)) (*Result, error) {
	if req.MaxBytes <= 0 || req.MaxBytes > 2000000000 || req.MaxFileBytes <= 0 || req.MaxFileBytes > 1500000000 || req.MaxEntries < 1 || req.MaxEntries > 100 || req.From >= req.To || req.From < req.SourceFrom || req.To > req.SourceTo {
		return nil, fmt.Errorf("invalid parser resource or scope contract")
	}
	if req.Family != "klines" && req.Family != "markPriceKlines" && req.Family != "fundingRate" {
		return nil, fmt.Errorf("unsupported data family")
	}
	if filepath.Base(req.Filename) != req.Filename || !strings.HasSuffix(req.Filename, ".zip") {
		return nil, fmt.Errorf("invalid declared filename")
	}
	archive, e := zip.OpenReader(req.Path)
	if e != nil {
		return nil, fmt.Errorf("invalid ZIP: %w", e)
	}
	defer archive.Close()
	if len(archive.File) > req.MaxEntries || len(archive.File) != 1 {
		return nil, fmt.Errorf("unexpected archive entries")
	}
	result := &Result{Parser: Version}
	expectedName := strings.TrimSuffix(req.Filename, ".zip") + ".csv"
	for _, file := range archive.File {
		if file.Name != expectedName || strings.ContainsAny(file.Name, `/\`) || file.Flags&1 != 0 || file.FileInfo().IsDir() || file.Mode()&os.ModeType != 0 || file.Method != zip.Store && file.Method != zip.Deflate {
			return nil, fmt.Errorf("unsafe ZIP entry")
		}
		result.ExpandedBytes += file.UncompressedSize64
		result.CompressedBytes += file.CompressedSize64
		if file.UncompressedSize64 > uint64(req.MaxFileBytes) || result.ExpandedBytes > uint64(req.MaxBytes) {
			return nil, fmt.Errorf("archive expansion exceeds absolute limit")
		}
	}
	temporary := req.Output + ".partial"
	out, e := os.OpenFile(temporary, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if e != nil {
		return nil, e
	}
	success := false
	defer func() {
		out.Close()
		if !success {
			os.Remove(temporary)
		}
	}()
	writer := bufio.NewWriterSize(out, 65536)
	encoder := json.NewEncoder(writer)
	source, e := archive.File[0].Open()
	if e != nil {
		return nil, e
	}
	defer source.Close()
	reader := bufio.NewReaderSize(io.LimitReader(source, req.MaxFileBytes+1), 65536)
	var bytes int64
	var previous *Row
	line := 0
	for {
		if e = ctx.Err(); e != nil {
			return nil, e
		}
		raw, err := reader.ReadSlice('\n')
		if err == bufio.ErrBufferFull {
			return nil, fmt.Errorf("CSV row exceeds 64 KiB")
		}
		bytes += int64(len(raw))
		if bytes > req.MaxFileBytes {
			return nil, fmt.Errorf("expanded bytes exceeded")
		}
		if err != nil && err != io.EOF {
			return nil, fmt.Errorf("ZIP integrity read: %w", err)
		}
		if len(raw) == 0 && err == io.EOF {
			break
		}
		line++
		csvReader := csv.NewReader(strings.NewReader(strings.TrimSuffix(strings.TrimSuffix(string(raw), "\n"), "\r")))
		fields, parseErr := csvReader.Read()
		if parseErr != nil {
			return nil, fmt.Errorf("CSV line %d malformed", line)
		}
		if line == 1 && header(fields, req.Family) {
			result.Header = true
			if err == io.EOF {
				break
			}
			continue
		}
		row, parseErr := validate(fields, req)
		if parseErr != nil {
			return nil, fmt.Errorf("CSV line %d: %w", line, parseErr)
		}
		if previous != nil {
			if row.Time < previous.Time {
				return nil, fmt.Errorf("CSV line %d out of order", line)
			}
			if row.Time == previous.Time {
				if row != *previous {
					return nil, fmt.Errorf("contradictory duplicate timestamp at line %d", line)
				}
				result.Duplicates++
				if err == io.EOF {
					break
				}
				continue
			}
		}
		saved := row
		previous = &saved
		included := row.Time >= req.From && row.Time < req.To
		if req.Family != "fundingRate" {
			included = included && row.Time+step(req.Interval) <= req.To
		}
		if included {
			if e = encoder.Encode(row); e != nil {
				return nil, e
			}
			result.Rows++
			if result.Rows%5000 == 0 && progress != nil {
				progress(result.Rows)
			}
		}
		if err == io.EOF {
			break
		}
	}
	if uint64(bytes) != result.ExpandedBytes {
		return nil, fmt.Errorf("ZIP expanded size mismatch")
	}
	if req.Family != "fundingRate" {
		unit := step(req.Interval)
		first := (req.From + unit - 1) / unit * unit
		expected := (req.To - first) / unit
		if expected < 0 {
			expected = 0
		}
		result.Missing = expected - result.Rows
		if result.Missing < 0 {
			return nil, fmt.Errorf("unexpected row count")
		}
	}
	if result.Rows == 0 {
		return nil, fmt.Errorf("empty requested coverage")
	}
	if e = writer.Flush(); e != nil {
		return nil, e
	}
	if e = out.Sync(); e != nil {
		return nil, e
	}
	if e = out.Close(); e != nil {
		return nil, e
	}
	if e = os.Rename(temporary, req.Output); e != nil {
		return nil, e
	}
	success = true
	return result, nil
}
func Deadline(parent context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(parent, 120*time.Second)
}
