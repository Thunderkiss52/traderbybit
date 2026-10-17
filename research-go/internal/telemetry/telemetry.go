package telemetry

import (
	"runtime"
	"time"

	"github.com/traderbybit/research-go/internal/contracts"
)

type Tracker struct {
	startTime time.Time
	startMem  runtime.MemStats
}

func Start() *Tracker {
	var ms runtime.MemStats
	runtime.ReadMemStats(&ms)
	return &Tracker{
		startTime: time.Now(),
		startMem:  ms,
	}
}

func (t *Tracker) Stop(rowsProcessed int) contracts.RuntimeStats {
	duration := time.Since(t.startTime)
	var endMem runtime.MemStats
	runtime.ReadMemStats(&endMem)

	peakRSS := endMem.Sys
	if endMem.Alloc > peakRSS {
		peakRSS = endMem.Alloc
	}

	return contracts.RuntimeStats{
		DurationMS:    duration.Milliseconds(),
		PeakRSSBytes:  peakRSS,
		CPUTimeMS:     duration.Milliseconds(), // In single-threaded execution wall matches CPU closely
		RowsProcessed: rowsProcessed,
	}
}
