package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/traderbybit/research-go/internal/signalintel"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	go func() { <-ctx.Done(); _ = os.Stdin.Close() }()
	data, err := io.ReadAll(io.LimitReader(os.Stdin, (4<<20)+1))
	if err != nil {
		fail(err)
	}
	if len(data) > 4<<20 {
		fail(fmt.Errorf("OVERSIZED_INPUT"))
	}
	request, err := signalintel.DecodeRequest(data)
	if err != nil {
		fail(err)
	}
	result, err := signalintel.Run(ctx, request)
	if err != nil {
		fail(err)
	}
	if err = json.NewEncoder(os.Stdout).Encode(result); err != nil {
		fail(err)
	}
}

func fail(err error) { fmt.Fprintln(os.Stderr, err); os.Exit(1) }
