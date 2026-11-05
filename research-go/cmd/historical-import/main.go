package main

import (
	"context"
	"encoding/json"
	"fmt"
	"github.com/traderbybit/research-go/internal/dataset/importer"
	"io"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	var req importer.Request
	decoder := json.NewDecoder(io.LimitReader(os.Stdin, 65537))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&req); err != nil {
		fmt.Fprintln(os.Stderr, "invalid import request")
		os.Exit(2)
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()
	ctx, cancel := importer.Deadline(ctx)
	defer cancel()
	result, err := importer.Parse(ctx, req, func(rows int64) { json.NewEncoder(os.Stdout).Encode(map[string]interface{}{"progress": rows}) })
	if err != nil {
		json.NewEncoder(os.Stdout).Encode(map[string]string{"error": err.Error()})
		os.Exit(1)
	}
	json.NewEncoder(os.Stdout).Encode(map[string]interface{}{"result": result})
}
