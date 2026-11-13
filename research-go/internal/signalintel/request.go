package signalintel

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
)

func DecodeRequest(data []byte) (Request, error) {
	var request Request
	if len(data) > 4<<20 {
		return request, fmt.Errorf("OVERSIZED_INPUT")
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&request); err != nil {
		return request, err
	}
	var extra interface{}
	if err := decoder.Decode(&extra); err != io.EOF {
		return request, fmt.Errorf("TRAILING_INPUT")
	}
	return request, nil
}
