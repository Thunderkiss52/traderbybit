package exchange

import (
    "context"
    "encoding/json"
    "fmt"
    "io"
    "net/http"
    "net/url"
    "time"

    "github.com/traderbybit/research-go/internal/datasets"
    "github.com/traderbybit/research-go/internal/rateLimiter"
)

// BybitClient implements ExchangeClient for Bybit USDT linear perpetual futures.
// It uses the public REST endpoint v5/market/kline. No authentication is required.
// The client respects per‑endpoint rate limits via the Redis‑backed RateLimiter.
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

// intervalMap maps the canonical interval identifiers used in the system to Bybit API strings.
var intervalMap = map[string]string{
    "1m":  "1",
    "5m":  "5",
    "15m": "15",
    "30m": "30",
    "1h":  "60",
    "4h":  "240",
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
    q.Set("interval", iv)
    // Bybit expects timestamps in seconds.
    q.Set("start", fmt.Sprintf("%d", start.Unix()))
    q.Set("end", fmt.Sprintf("%d", end.Unix()))
    q.Set("limit", "200") // maximum per request per Bybit docs.
    u.RawQuery = q.Encode()
    return u.String(), nil
}

// fetchBatch performs a single HTTP request with rate‑limit handling.
func (c *BybitClient) fetchBatch(ctx context.Context, url string) ([]datasets.Candle, error) {
    // Acquire rate‑limit token for this endpoint.
    if err := c.limiter.Acquire(ctx, "bybit:kline"); err != nil {
        return nil, err
    }
    req, err := http.NewRequestWithContext(ctx, "GET", url, nil)
    if err != nil {
        return nil, err
    }
    resp, err := c.httpClient.Do(req)
    if err != nil {
        return nil, err
    }
    defer resp.Body.Close()

    // Respect Retry‑After on 429.
    if resp.StatusCode == http.StatusTooManyRequests {
        // Let caller handle retry based on limiter back‑off.
        return nil, fmt.Errorf("rate limited: %s", resp.Status)
    }
    if resp.StatusCode != http.StatusOK {
        body, _ := io.ReadAll(resp.Body)
        return nil, fmt.Errorf("unexpected status %d: %s", resp.StatusCode, string(body))
    }
    var wrapper struct {
        RetCode int `json:"ret_code"`
        Result  []struct {
            OpenTime int64  `json:"open_time"`
            Open     string `json:"open"`
            High     string `json:"high"`
            Low      string `json:"low"`
            Close    string `json:"close"`
            Volume   string `json:"volume"`
            Turnover string `json:"turnover"`
            IsClosed bool   `json:"is_closed"`
        } `json:"result"`
        RetMsg string `json:"ret_msg"`
    }
    if err := json.NewDecoder(resp.Body).Decode(&wrapper); err != nil {
        return nil, err
    }
    if wrapper.RetCode != 0 {
        return nil, fmt.Errorf("bybit API error %d: %s", wrapper.RetCode, wrapper.RetMsg)
    }
    candles := make([]datasets.Candle, 0, len(wrapper.Result))
    for _, r := range wrapper.Result {
        // Bybit timestamps are seconds since epoch.
        openTime := time.Unix(r.OpenTime, 0).UTC()
        cdl := datasets.Candle{
            Exchange: "bybit",
            Category: "linear",
            Symbol:   symbol,
            Interval: interval,
            OpenTime: openTime,
            IsClosed: r.IsClosed,
        }
        // Use the decimal package for high‑precision values.
        if d, err := decimal.FromString(r.Open); err == nil {
            cdl.Open = d
        }
        if d, err := decimal.FromString(r.High); err == nil {
            cdl.High = d
        }
        if d, err := decimal.FromString(r.Low); err == nil {
            cdl.Low = d
        }
        if d, err := decimal.FromString(r.Close); err == nil {
            cdl.Close = d
        }
        if d, err := decimal.FromString(r.Volume); err == nil {
            cdl.Volume = d
        }
        if d, err := decimal.FromString(r.Turnover); err == nil {
            cdl.Turnover = d
        }
        candles = append(candles, cdl)
    }
    return candles, nil
}

// FetchKlines implements ExchangeClient. It automatically paginates until the end time is reached.
func (c *BybitClient) FetchKlines(ctx context.Context, symbol string, interval string, start time.Time, end time.Time) ([]datasets.Candle, error) {
    if start.After(end) {
        return nil, fmt.Errorf("start must be before end")
    }
    var all []datasets.Candle
    cursor := start
    for cursor.Before(end) {
        url, err := c.buildURL(symbol, interval, cursor, end)
        if err != nil {
            return nil, err
        }
        batch, err := c.fetchBatch(ctx, url)
        if err != nil {
            // Simple exponential back‑off on retryable errors.
            for i := 0; i < c.maxRetries; i++ {
                backoff := c.retryBackoff * time.Duration(1<<i)
                select {
                case <-time.After(backoff):
                case <-ctx.Done():
                    return nil, ctx.Err()
                }
                batch, err = c.fetchBatch(ctx, url)
                if err == nil {
                    break
                }
            }
            if err != nil {
                return nil, err
            }
        }
        if len(batch) == 0 {
            break // no more data.
        }
        all = append(all, batch...)
        // Bybit returns candles ordered ascending; advance cursor to last candle time + interval.
        last := batch[len(batch)-1]
        // Interval duration mapping (same as intervalMap values).
        dur, _ := time.ParseDuration(interval + "m") // fallback; exact mapping handled later.
        // Use a safe step: add 1 minute to ensure progress.
        cursor = last.OpenTime.Add(dur)
    }
    // Ensure we only return candles up to the requested end.
    filtered := make([]datasets.Candle, 0, len(all))
    for _, cdl := range all {
        if !cdl.OpenTime.After(end) {
            filtered = append(filtered, cdl)
        }
    }
    return filtered, nil
}
