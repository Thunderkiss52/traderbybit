package datasets

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"fmt"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

type Candle struct {
	Exchange string          `json:"exchange"`
	Category string          `json:"category"`
	Symbol   string          `json:"symbol"`
	Interval string          `json:"interval"`
	OpenTime time.Time       `json:"open_time"`
	Open     decimal.Decimal `json:"open"`
	High     decimal.Decimal `json:"high"`
	Low      decimal.Decimal `json:"low"`
	Close    decimal.Decimal `json:"close"`
	Volume   decimal.Decimal `json:"volume"`
	Turnover decimal.Decimal `json:"turnover"`
	IsClosed bool            `json:"is_closed"`
}

// ComputeDatasetHash calculates deterministic SHA256 of candles sequence
func ComputeDatasetHash(candles []Candle) string {
	h := sha256.New()
	for _, c := range candles {
		record := fmt.Sprintf("%s:%s:%s:%d:%s:%s:%s:%s:%s\n",
			c.Category, c.Symbol, c.Interval, c.OpenTime.Unix(),
			c.Open.String(), c.High.String(), c.Low.String(), c.Close.String(), c.Volume.String())
		h.Write([]byte(record))
	}
	return hex.EncodeToString(h.Sum(nil))
}

// LoadCandlesFromDB streams closed candles with strict ordering and keyset bounds
func LoadCandlesFromDB(db *sql.DB, exchange, category, symbol, interval string, from, to time.Time, limit int) ([]Candle, error) {
	return LoadCandlesFromDBForUse(db, exchange, category, symbol, interval, from, to, limit, "UNDECLARED")
}

// LoadCandlesFromDBForUse enforces recorded source permissions before reading restricted history.
func LoadCandlesFromDBForUse(db *sql.DB, exchange, category, symbol, interval string, from, to time.Time, limit int, intendedUse string) ([]Candle, error) {
	if exchange == "binance" {
		var installed bool
		if err := db.QueryRow("SELECT to_regclass('historical_download_entries') IS NOT NULL").Scan(&installed); err != nil {
			return nil, fmt.Errorf("source policy lookup unavailable")
		}
		if installed {
			var restricted, prohibited bool
			query := `SELECT EXISTS(SELECT 1 FROM historical_download_entries e WHERE e.family='klines' AND e.symbol=$1 AND e.checkpoint>0 AND e.requested_from<$3 AND e.requested_to>$2), EXISTS(SELECT 1 FROM historical_download_entries e JOIN historical_download_batches b ON b.id=e.batch_id WHERE e.family='klines' AND e.symbol=$1 AND e.checkpoint>0 AND e.requested_from<$3 AND e.requested_to>$2 AND (b.acknowledged_at IS NULL OR NOT (b.license->'permitted_uses' ? $4)))`
			if err := db.QueryRow(query, symbol, from, to, intendedUse).Scan(&restricted, &prohibited); err != nil {
				return nil, fmt.Errorf("source policy evidence unavailable")
			}
			if restricted && (prohibited || (intendedUse != "NONCOMMERCIAL_RESEARCH" && intendedUse != "NONCOMMERCIAL_LEARNING")) {
				return nil, fmt.Errorf("source license use blocked")
			}
		}
	}
	if limit <= 0 || limit > 1000000 {
		limit = 100000
	}

	query := `
		SELECT exchange, category, symbol, interval, open_time, open, high, low, close, volume, turnover, is_closed
		FROM market_candles
		WHERE exchange = $1
		  AND category = $2
		  AND symbol = $3
		  AND interval = $4
		  AND is_closed = true
		  AND open_time >= $5
		  AND open_time < $6
		ORDER BY open_time ASC
		LIMIT $7
	`

	rows, err := db.Query(query, exchange, category, symbol, interval, from, to, limit)
	if err != nil {
		return nil, fmt.Errorf("failed to query candles: %w", err)
	}
	defer rows.Close()

	candles := make([]Candle, 0, 500)
	for rows.Next() {
		var c Candle
		var openStr, highStr, lowStr, closeStr, volStr, turnStr string

		if err := rows.Scan(
			&c.Exchange, &c.Category, &c.Symbol, &c.Interval, &c.OpenTime,
			&openStr, &highStr, &lowStr, &closeStr, &volStr, &turnStr, &c.IsClosed,
		); err != nil {
			return nil, fmt.Errorf("failed to scan candle: %w", err)
		}

		c.Open, _ = decimal.FromString(openStr)
		c.High, _ = decimal.FromString(highStr)
		c.Low, _ = decimal.FromString(lowStr)
		c.Close, _ = decimal.FromString(closeStr)
		c.Volume, _ = decimal.FromString(volStr)
		c.Turnover, _ = decimal.FromString(turnStr)

		candles = append(candles, c)
	}

	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("error during candle iteration: %w", err)
	}

	return candles, nil
}
