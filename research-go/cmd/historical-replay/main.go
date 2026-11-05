package main

import (
	"context"
	"encoding/json"
	"github.com/traderbybit/research-go/internal/historical"
	"io"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()
	ctx, cancel := context.WithTimeout(ctx, 25*time.Minute)
	defer cancel()
	var req historical.Request
	decoder := json.NewDecoder(io.LimitReader(os.Stdin, 1048576))
	decoder.DisallowUnknownFields()
	if e := decoder.Decode(&req); e != nil {
		os.Exit(2)
	}
	_, e := historical.Run(ctx, req)
	if e != nil {
		json.NewEncoder(os.Stdout).Encode(map[string]string{"error": e.Error()})
		os.Exit(1)
	}
	json.NewEncoder(os.Stdout).Encode(map[string]bool{"completed": true})
}
