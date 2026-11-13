package jobscope

import "testing"

func TestResolveJobExchange(t *testing.T) {
	for _, tt := range []struct {
		name    string
		payload map[string]interface{}
		want    string
		wantErr bool
	}{
		{"bybit", map[string]interface{}{"exchange": "BYBIT"}, "bybit", false},
		{"binance", map[string]interface{}{"exchange_id": "binance"}, "binance", false},
		{"consistent aliases", map[string]interface{}{"exchange": "binance", "exchange_id": "BINANCE"}, "binance", false},
		{"dataset_exchange", map[string]interface{}{"dataset_exchange": "binance"}, "binance", false},
		{"nested dataset exchange bybit", map[string]interface{}{"dataset": map[string]interface{}{"exchange": "bybit"}}, "bybit", false},
		{"nested dataset exchange_id binance", map[string]interface{}{"dataset": map[string]interface{}{"exchange_id": "binance"}}, "binance", false},
		{"consistent dataset and top-level", map[string]interface{}{"exchange": "bybit", "dataset": map[string]interface{}{"exchange": "bybit"}}, "bybit", false},
		{"missing", map[string]interface{}{"symbol": "BTCUSDT"}, "", true},
		{"nil payload", nil, "", true},
		{"conflicting", map[string]interface{}{"exchange": "bybit", "exchange_id": "binance"}, "", true},
		{"conflicting dataset exchange", map[string]interface{}{"exchange": "bybit", "dataset": map[string]interface{}{"exchange": "binance"}}, "", true},
		{"registered future exchange", map[string]interface{}{"exchange": "okx"}, "okx", false},
		{"offline dataset provenance", map[string]interface{}{"dataset": map[string]interface{}{"exchange": "kraken"}}, "kraken", false},
		{"invalid type", map[string]interface{}{"exchange": 100}, "", true},
		{"empty", map[string]interface{}{"exchange": " "}, "", true},
	} {
		t.Run(tt.name, func(t *testing.T) {
			got, err := ResolveExchange(tt.payload)
			if (err != nil) != tt.wantErr || got != tt.want {
				t.Fatalf("got exchange %q, err %v; want %q, wantErr %t", got, err, tt.want, tt.wantErr)
			}
		})
	}
}
