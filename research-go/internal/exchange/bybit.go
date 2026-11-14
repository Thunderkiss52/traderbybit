package exchange

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/rateLimiter"
)

// BybitClient implements ExchangeClient for Bybit USDT linear perpetual futures.
// It uses the public REST endpoint v5/market/kline. No authentication is required.
// The client respects per-endpoint rate limits via the RateLimiter.
type BybitClient struct {
	httpClient   *http.Client
	limiter      *rateLimiter.Limiter
	baseURL      string
	maxRetries   int
	retryBackoff time.Duration
}

func NewBybitClient(limiter *rateLimiter.Limiter) *BybitClient {
	return &BybitClient{
		httpClient:   &http.Client{Timeout: 15 * time.Second},
		limiter:      limiter,
		baseURL:      "https://api.bybit.com",
		maxRetries:   5,
		retryBackoff: 500 * time.Millisecond,
	}
}

// intervalMap maps canonical interval strings to Bybit API interval values.
var intervalMap = map[string]string{
	"1m":  "1",
	"5m":  "5",
	"15m": "15",
	"30m": "30",
	"1h":  "60",
	"4h":  "240",
}

// intervalDuration maps canonical interval strings to their time.Duration.
var intervalDuration = map[string]time.Duration{
	"1m":  1 * time.Minute,
	"5m":  5 * time.Minute,
	"15m": 15 * time.Minute,
	"30m": 30 * time.Minute,
	"1h":  60 * time.Minute,
	"4h":  240 * time.Minute,
}

func (c *BybitClient) mapInterval(i string) (string, error) {
	if v, ok := intervalMap[i]; ok {
		return v, nil
	}
	return "", fmt.Errorf("unsupported interval %s for Bybit", i)
}

func (c *BybitClient) buildURL(symbol, interval string, start, end time.Time) (string, error) {
	iv, err := c.mapInterval(interval)
	if err != nil {
		return "", err
	}
	u, err := url.Parse(c.baseURL + "/v5/market/kline")
	if err != nil {
		return "", err
	}
	q := u.Query()
	q.Set("symbol", symbol)
	q.Set("category", "linear")
	q.Set("interval", iv)
	// Bybit v5 expects timestamps in milliseconds.
	q.Set("start", fmt.Sprintf("%d", start.UnixMilli()))
	q.Set("end", fmt.Sprintf("%d", end.UnixMilli()))
	q.Set("limit", "200") // maximum per request per Bybit docs.
	u.RawQuery = q.Encode()
	return u.String(), nil
}

// fetchBatch performs a single HTTP request with rate-limit handling.
// symbol and interval are needed to populate the returned Candle structs.
func (c *BybitClient) fetchBatch(ctx context.Context, rawURL, symbol, interval string) ([]datasets.Candle, error) {
	if err := c.limiter.Acquire(ctx, "bybit:kline"); err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, "GET", rawURL, nil)
	if err != nil {
		return nil, err
	}
	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusTooManyRequests {
		return nil, fmt.Errorf("rate limited: %s", resp.Status)
	}
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("unexpected status %d: %s", resp.StatusCode, string(body))
	}

	// Bybit v5 kline response:
	// {"retCode":0,"result":{"symbol":"BTCUSDT","category":"linear","list":[["ts","open","high","low","close","vol","turnover"],...]}}
	var wrapper struct {
		RetCode int    `json:"retCode"`
		RetMsg  string `json:"retMsg"`
		Result  struct {
			Symbol   string     `json:"symbol"`
			Category string     `json:"category"`
			List     [][]string `json:"list"`
		} `json:"result"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&wrapper); err != nil {
		return nil, err
	}
	if wrapper.RetCode != 0 {
		return nil, fmt.Errorf("bybit API error %d: %s", wrapper.RetCode, wrapper.RetMsg)
	}

	candles := make([]datasets.Candle, 0, len(wrapper.Result.List))
	for _, row := range wrapper.Result.List {
		// Each row: [startTime(ms), open, high, low, close, volume, turnover]
		if len(row) < 7 {
			continue
		}
		var tsMs int64
		if _, err := fmt.Sscanf(row[0], "%d", &tsMs); err != nil {
			continue
		}
		openTime := time.UnixMilli(tsMs).UTC()

		parse := func(s string) decimal.Decimal {
			d, err := decimal.FromString(s)
			if err != nil {
				return decimal.Zero()
			}
			return d
		}

		cdl := datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   symbol,
			Interval: interval,
			OpenTime: openTime,
			Open:     parse(row[1]),
			High:     parse(row[2]),
			Low:      parse(row[3]),
			Close:    parse(row[4]),
			Volume:   parse(row[5]),
			Turnover: parse(row[6]),
			// Bybit REST returns closed candles only (open candle excluded by default).
			IsClosed: true,
		}
		candles = append(candles, cdl)
	}
	return candles, nil
}

// FetchKlines implements ExchangeClient. It paginates until end is reached.
func (c *BybitClient) FetchKlines(ctx context.Context, symbol, interval string, start, end time.Time) ([]datasets.Candle, error) {
	if start.After(end) {
		return nil, fmt.Errorf("start must be before end")
	}
	dur, ok := intervalDuration[interval]
	if !ok {
		return nil, fmt.Errorf("unsupported interval: %s", interval)
	}

	var all []datasets.Candle
	cursor := start
	for cursor.Before(end) {
		rawURL, err := c.buildURL(symbol, interval, cursor, end)
		if err != nil {
			return nil, err
		}

		var batch []datasets.Candle
		for attempt := 0; attempt <= c.maxRetries; attempt++ {
			batch, err = c.fetchBatch(ctx, rawURL, symbol, interval)
			if err == nil {
				break
			}
			if attempt == c.maxRetries {
				return nil, err
			}
			backoff := c.retryBackoff * time.Duration(1<<attempt)
			select {
			case <-time.After(backoff):
			case <-ctx.Done():
				return nil, ctx.Err()
			}
		}

		if len(batch) == 0 {
			break
		}
		all = append(all, batch...)
		last := batch[len(batch)-1]
		cursor = last.OpenTime.Add(dur)
	}

	// Filter to requested window.
	filtered := make([]datasets.Candle, 0, len(all))
	for _, cdl := range all {
		if !cdl.OpenTime.After(end) {
			filtered = append(filtered, cdl)
		}
	}
	return filtered, nil
}
