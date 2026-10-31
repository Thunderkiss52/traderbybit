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
		{"missing", map[string]interface{}{"symbol": "BTCUSDT"}, "", true},
		{"nil payload", nil, "", true},
		{"conflicting", map[string]interface{}{"exchange": "bybit", "exchange_id": "binance"}, "", true},
		{"unknown exchange", map[string]interface{}{"exchange": "okx"}, "", true},
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
