package jobscope

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
)

// ResolveExchange enforces a single, explicit source identity before any
// read or computation. Older source-less jobs fail closed and can be safely
// resubmitted with provenance; no historical result is rewritten in place.
// Supports direct exchange/exchange_id as well as dataset-based exchange keys.
func ResolveExchange(payload map[string]interface{}) (string, error) {
	if payload == nil {
		return "", errors.New("research job payload is nil")
	}

	type candidate struct {
		source string
		value  interface{}
	}
	var candidates []candidate

	for _, key := range []string{"exchange", "exchange_id", "dataset_exchange"} {
		if v, exists := payload[key]; exists {
			candidates = append(candidates, candidate{source: key, value: v})
		}
	}

	if ds, exists := payload["dataset"]; exists && ds != nil {
		if dsMap, ok := ds.(map[string]interface{}); ok {
			for _, key := range []string{"exchange", "exchange_id"} {
				if v, exists := dsMap[key]; exists {
					candidates = append(candidates, candidate{source: "dataset." + key, value: v})
				}
			}
		}
	}

	if len(candidates) == 0 {
		return "", errors.New("research job exchange is missing or unsupported: \"\"")
	}

	var exchange string
	for _, c := range candidates {
		raw, ok := c.value.(string)
		if !ok || strings.TrimSpace(raw) == "" {
			return "", fmt.Errorf("research job %s must be a nonempty exchange identity", c.source)
		}
		next := strings.ToLower(strings.TrimSpace(raw))
		if exchange != "" && exchange != next {
			return "", fmt.Errorf("research job exchange identities disagree (%s=%q vs %q)", c.source, next, exchange)
		}
		exchange = next
	}

	if !regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{1,31}$`).MatchString(exchange) {
		return "", fmt.Errorf("research job exchange identity is invalid: %q", exchange)
	}

	return exchange, nil
}
