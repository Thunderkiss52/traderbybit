package exchange

import (
	"context"
	"github.com/traderbybit/research-go/internal/datasets"
	"time"
)

// ExchangeClient abstracts a market data provider for a given exchange.
// Only read‑only endpoints are required (historical K‑line fetch).
// Implementations must be safe for concurrent use.
type ExchangeClient interface {
	// FetchKlines returns closed candles for the given symbol and interval.
	// start and end are inclusive UTC timestamps; the implementation must
	// honour the exchange's rate limits and return candles sorted by OpenTime.
	FetchKlines(ctx context.Context, symbol string, interval string, start time.Time, end time.Time) ([]datasets.Candle, error)
}
