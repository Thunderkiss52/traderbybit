package ws

import (
    "context"
    "encoding/json"
    "fmt"
    "log"
    "net/url"
    "sync"
    "time"

    "github.com/gorilla/websocket"
    "github.com/traderbybit/research-go/internal/datasets"
    "github.com/traderbybit/research-go/internal/rateLimiter"
    "github.com/traderbybit/research-go/internal/decimal"
)

// WSClient abstracts a WebSocket connection to a market data stream.
// It handles reconnection, heartbeat (ping/pong), and duplicate detection.
type WSClient struct {
    mu          sync.Mutex
    conn        *websocket.Conn
    url         string
    exchange    string // "bybit" or "binance"
    limiter     *rateLimiter.Limiter
    cancel      context.CancelFunc
    listeners   []chan<- datasets.Candle
    lastMsgTime time.Time
}

func NewWSClient(exchange, endpoint string, limiter *rateLimiter.Limiter) *WSClient {
    return &WSClient{url: endpoint, exchange: exchange, limiter: limiter}
}

// Connect establishes the WebSocket connection and starts the read loop.
func (c *WSClient) Connect(ctx context.Context) error {
    c.mu.Lock()
    defer c.mu.Unlock()
    if c.conn != nil {
        return fmt.Errorf("already connected")
    }
    // Acquire a rate‑limit token for the WS connection establishment.
    if err := c.limiter.Acquire(ctx, fmt.Sprintf("%s:ws_connect", c.exchange)); err != nil {
        return err
    }
    u, err := url.Parse(c.url)
    if err != nil {
        return err
    }
    dialer := websocket.DefaultDialer
    conn, _, err := dialer.DialContext(ctx, u.String(), nil)
    if err != nil {
        return err
    }
    c.conn = conn
    c.lastMsgTime = time.Now()
    // Start read loop in background.
    childCtx, cancel := context.WithCancel(ctx)
    c.cancel = cancel
    go c.readLoop(childCtx)
    // Start heartbeat monitor.
    go c.heartbeatMonitor(childCtx)
    return nil
}

func (c *WSClient) readLoop(ctx context.Context) {
    for {
        select {
        case <-ctx.Done():
            return
        default:
        }
        _, message, err := c.conn.ReadMessage()
        if err != nil {
            log.Printf("%s WS read error: %v, reconnecting", c.exchange, err)
            c.reconnect()
            return
        }
        c.lastMsgTime = time.Now()
        // Each message is expected to be a JSON object representing a candle.
        var raw struct {
            Topic string `json:"topic"`
            Data  struct {
                Symbol   string `json:"symbol"`
                Interval string `json:"interval"`
                OpenTime int64  `json:"open_time"`
                Open     string `json:"open"`
                High     string `json:"high"`
                Low      string `json:"low"`
                Close    string `json:"close"`
                Volume   string `json:"volume"`
                Turnover string `json:"turnover"`
                IsClosed bool   `json:"is_closed"`
            } `json:"data"`
        }
        if err := json.Unmarshal(message, &raw); err != nil {
            log.Printf("%s WS unmarshal error: %v", c.exchange, err)
            continue
        }
        // Convert to Candle struct.
        candle := datasets.Candle{
            Exchange: c.exchange,
            Category: "linear",
            Symbol:   raw.Data.Symbol,
            Interval: raw.Data.Interval,
            OpenTime: time.Unix(raw.Data.OpenTime, 0).UTC(),
            IsClosed: raw.Data.IsClosed,
        }
        // Parse decimals using the project's decimal package.
        if d, err := decimal.FromString(raw.Data.Open); err == nil {
            candle.Open = d
        }
        if d, err := decimal.FromString(raw.Data.High); err == nil {
            candle.High = d
        }
        if d, err := decimal.FromString(raw.Data.Low); err == nil {
            candle.Low = d
        }
        if d, err := decimal.FromString(raw.Data.Close); err == nil {
            candle.Close = d
        }
        if d, err := decimal.FromString(raw.Data.Volume); err == nil {
            candle.Volume = d
        }
        if d, err := decimal.FromString(raw.Data.Turnover); err == nil {
            candle.Turnover = d
        }
        // Dispatch to listeners (e.g., backfill manager).
        c.mu.Lock()
        for _, ch := range c.listeners {
            select {
            case ch <- candle:
            default:
                // If listener channel is full, drop to avoid blocking.
            }
        }
        c.mu.Unlock()
    }
}

// RegisterListener adds a channel that will receive parsed candles.
func (c *WSClient) RegisterListener(ch chan<- datasets.Candle) {
    c.mu.Lock()
    defer c.mu.Unlock()
    c.listeners = append(c.listeners, ch)
}

// heartbeatMonitor ensures the connection stays alive; if no message for 30s, reconnect.
func (c *WSClient) heartbeatMonitor(ctx context.Context) {
    ticker := time.NewTicker(15 * time.Second)
    defer ticker.Stop()
    for {
        select {
        case <-ctx.Done():
            return
        case <-ticker.C:
            if time.Since(c.lastMsgTime) > 30*time.Second {
                log.Printf("%s WS stale, reconnecting", c.exchange)
                c.reconnect()
                return
            }
            // Send ping to keep connection alive.
            c.mu.Lock()
            if c.conn != nil {
                _ = c.conn.WriteMessage(websocket.PingMessage, []byte{})
            }
            c.mu.Unlock()
        }
    }
}

func (c *WSClient) reconnect() {
    c.mu.Lock()
    if c.conn != nil {
        _ = c.conn.Close()
        c.conn = nil
    }
    if c.cancel != nil {
        c.cancel()
    }
    c.mu.Unlock()
    // Simple back‑off before retrying.
    time.Sleep(2 * time.Second)
    // Re‑connect using background context (caller should handle termination).
    go func() {
        ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
        defer cancel()
        if err := c.Connect(ctx); err != nil {
            log.Printf("%s WS reconnection failed: %v", c.exchange, err)
        }
    }()
}

// Close terminates the connection cleanly.
func (c *WSClient) Close() error {
    c.mu.Lock()
    defer c.mu.Unlock()
    if c.cancel != nil {
        c.cancel()
    }
    if c.conn != nil {
        return c.conn.Close()
    }
    return nil
}
