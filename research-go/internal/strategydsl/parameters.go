package strategydsl

import (
	"encoding/json"
	"fmt"
	"math"
	"regexp"
	"strings"
)

func validateParameters(d Definition) error {
	if len(d.Parameters) > 12 {
		return fmt.Errorf("too many parameter ranges")
	}
	raw, _ := json.Marshal(d)
	var root map[string]interface{}
	_ = json.Unmarshal(raw, &root)
	for path, p := range d.Parameters {
		if !regexp.MustCompile(`^(stop_atr|take_atr|risk_percent|timeout_bars|cooldown_bars|trailing_atr|trailing_stop\.(activation_atr|distance_atr)|indicators\.[a-z][a-z0-9_]*\.(period|slow_period|signal_period|deviation))$`).MatchString(path) {
			return fmt.Errorf("unsupported optimization path")
		}
		var current interface{} = root
		for _, part := range strings.Split(path, ".") {
			m, ok := current.(map[string]interface{})
			if !ok {
				return fmt.Errorf("unknown optimization path")
			}
			current = m[part]
		}
		value, ok := current.(float64)
		if !ok || value != p.Default || p.Min > p.Default || p.Default > p.Max || p.Step <= 0 || math.IsNaN(p.Step) {
			return fmt.Errorf("invalid parameter bounds/default")
		}
		integer := strings.HasSuffix(path, "period") || strings.HasSuffix(path, "bars")
		if integer && (math.Trunc(p.Min) != p.Min || math.Trunc(p.Max) != p.Max || math.Trunc(p.Step) != p.Step) {
			return fmt.Errorf("integer optimization path requires integer steps")
		}
		if path == "risk_percent" && (p.Min <= 0 || p.Max > 0.5) {
			return fmt.Errorf("optimization cannot relax risk ceiling")
		}
	}
	return nil
}
