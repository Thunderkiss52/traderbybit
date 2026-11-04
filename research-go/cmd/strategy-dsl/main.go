package main

import (
	"encoding/json"
	"fmt"
	"io"
	"os"

	"github.com/traderbybit/research-go/internal/strategydsl"
)

func main() {
	decoder := json.NewDecoder(io.LimitReader(os.Stdin, 64<<20))
	decoder.DisallowUnknownFields()
	var req strategydsl.Request
	if err := decoder.Decode(&req); err != nil {
		fail(err)
	}
	var extra interface{}
	if err := decoder.Decode(&extra); err != io.EOF {
		fail(fmt.Errorf("trailing input"))
	}
	result, err := strategydsl.Run(req)
	if err != nil {
		fail(err)
	}
	if err := json.NewEncoder(os.Stdout).Encode(result); err != nil {
		fail(err)
	}
}
func fail(err error) { fmt.Fprintln(os.Stderr, err); os.Exit(1) }
