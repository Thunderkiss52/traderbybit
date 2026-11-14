// Package rateLimiter provides a simple in‑memory token‑bucket limiter used by the Bybit client.
// It satisfies the interface expected in internal/exchange/bybit.go.
package rateLimiter

import (
	"context"
	"errors"
	"sync"
	"time"
)

// Limiter controls request rate per key.
type Limiter struct {
	mu       sync.Mutex
	buckets  map[string]*bucket
	interval time.Duration // refill interval
	limit    int           // tokens per interval
}

type bucket struct {
	tokens int
	last   time.Time
}

// NewLimiter creates a Limiter with the given tokens per interval.
func NewLimiter(limit int, interval time.Duration) *Limiter {
	if limit <= 0 {
		limit = 1
	}
	return &Limiter{
		buckets:  make(map[string]*bucket),
		interval: interval,
		limit:    limit,
	}
}

// Acquire reserves a token for the given key, waiting until it becomes available or the context is done.
func (l *Limiter) Acquire(ctx context.Context, key string) error {
	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		default:
		}
		l.mu.Lock()
		b, ok := l.buckets[key]
		now := time.Now()
		if !ok {
			b = &bucket{tokens: l.limit, last: now}
			l.buckets[key] = b
		}
		// Refill tokens based on elapsed intervals.
		elapsed := now.Sub(b.last)
		if elapsed >= l.interval {
			// calculate how many intervals passed
			periods := int(elapsed / l.interval)
			b.tokens += periods * l.limit
			if b.tokens > l.limit {
				b.tokens = l.limit
			}
			b.last = b.last.Add(time.Duration(periods) * l.interval)
		}
		if b.tokens > 0 {
			b.tokens--
			l.mu.Unlock()
			return nil
		}
		// No tokens, wait until next refill.
		wait := b.last.Add(l.interval).Sub(now)
		l.mu.Unlock()
		timer := time.NewTimer(wait)
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case <-timer.C:
		}
		// loop again to acquire.
		if err := ctx.Err(); err != nil {
			return err
		}
	}
}

// ErrNoLimiter is returned when a nil Limiter is used.
var ErrNoLimiter = errors.New("rateLimiter: nil limiter")
