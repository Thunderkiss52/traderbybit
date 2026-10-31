package jobscope

import (
	"errors"
	"fmt"
	"strings"
)

// resolveJobExchange enforces a single, explicit source identity before any
// read or computation. Older source-less jobs fail closed and can be safely
// resubmitted with provenance; no historical result is rewritten in place.
func ResolveExchange(payload map[string]interface{}) (string, error) {
	var exchange string
	for _, key := range []string{"exchange", "exchange_id"} {
		v, exists := payload[key]
		if !exists {
			continue
		}
		raw, ok := v.(string)
		if !ok || strings.TrimSpace(raw) == "" {
			return "", fmt.Errorf("research job %s must be a nonempty exchange identity", key)
		}
		next := strings.ToLower(strings.TrimSpace(raw))
		if exchange != "" && exchange != next {
			return "", errors.New("research job exchange and exchange_id disagree")
		}
		exchange = next
	}
	if exchange != "bybit" && exchange != "binance" {
		return "", fmt.Errorf("research job exchange is missing or unsupported: %q", exchange)
	}
	return exchange, nil
}
