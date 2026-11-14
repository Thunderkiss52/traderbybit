package ws

import (
    "context"
    "database/sql"
    "fmt"
    "log"

    "github.com/traderbybit/research-go/internal/datasets"
    _ "github.com/lib/pq"
)

// Processor consumes candles from a channel and persists them to PostgreSQL.
// It ensures idempotent inserts using the unique constraint on (exchange,symbol,interval,open_time).
// In case of a conflict where data differs, it records a conflict entry.

type Processor struct {
    db        *sql.DB
    ingestSrc string // "ws"
}

func NewProcessor(db *sql.DB) *Processor {
    return &Processor{db: db, ingestSrc: "ws"}
}

func (p *Processor) Run(ctx context.Context, candles <-chan datasets.Candle) {
    for {
        select {
        case <-ctx.Done():
            return
        case c := <-candles:
            if err := p.persistCandle(c); err != nil {
                log.Printf("failed to persist candle: %v", err)
            }
        }
    }
}

func (p *Processor) persistCandle(c datasets.Candle) error {
    // Insert with ON CONFLICT DO NOTHING; then check if row exists with different data.
    query := `INSERT INTO market_candles (exchange, category, symbol, interval, open_time, open, high, low, close, volume, turnover, is_closed, ingest_source, ingest_ts)
        VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13, now())
        ON CONFLICT (exchange, symbol, interval, open_time) DO UPDATE SET
            open = EXCLUDED.open,
            high = EXCLUDED.high,
            low = EXCLUDED.low,
            close = EXCLUDED.close,
            volume = EXCLUDED.volume,
            turnover = EXCLUDED.turnover,
            is_closed = EXCLUDED.is_closed,
            ingest_source = EXCLUDED.ingest_source,
            ingest_ts = now()
        RETURNING open, high, low, close, volume, turnover, is_closed;`
    var (
        existingOpen, existingHigh, existingLow, existingClose, existingVol, existingTurn, existingClosed string
    )
    err := p.db.QueryRow(query, c.Exchange, c.Category, c.Symbol, c.Interval, c.OpenTime, c.Open.String(), c.High.String(), c.Low.String(), c.Close.String(), c.Volume.String(), c.Turnover.String(), c.IsClosed, p.ingestSrc).
        Scan(&existingOpen, &existingHigh, &existingLow, &existingClose, &existingVol, &existingTurn, &existingClosed)
    if err != nil {
        return fmt.Errorf("insert failed: %w", err)
    }
    // Compare stored values with incoming; if they differ, log conflict.
    if existingOpen != c.Open.String() || existingHigh != c.High.String() || existingLow != c.Low.String() || existingClose != c.Close.String() || existingVol != c.Volume.String() || existingTurn != c.Turnover.String() || (existingClosed == "true") != c.IsClosed {
        // Record conflict.
        conflictQuery := `INSERT INTO candle_conflict_journal (exchange, symbol, interval, open_time, reason, created_at)
            VALUES ($1,$2,$3,$4,$5, now())`
        reason := "data mismatch on ws update"
        _, cerr := p.db.Exec(conflictQuery, c.Exchange, c.Symbol, c.Interval, c.OpenTime, reason)
        if cerr != nil {
            log.Printf("failed to log conflict: %v", cerr)
        }
    }
    return nil
}
