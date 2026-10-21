// Command research-bench measures the Go research engine on the locally stored realistic dataset.
//
// The synthetic benchmark of cmd/research-worker proves the engine scales linearly on generated
// candles. This command closes the remaining VECTOR section 2 requirement: a representative
// realistic data set measured with the same wall time / CPU time / peak RSS / allocation
// accounting, plus optional CPU and heap pprof profiles.
//
// The command is read only. It never opens an exchange order, never reads an exchange credential
// and never touches the eight primary paper accounts. It only reads the local archive and writes
// a measurement report and pprof profiles to disk.
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"runtime/pprof"
	"sort"
	"syscall"
	"time"

	"github.com/traderbybit/research-go/internal/auditdata"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/patterns"
)

type trial struct {
	Index             int     `json:"index"`
	WallTimeMS        int64   `json:"wall_time_ms"`
	CPUTimeMS         int64   `json:"cpu_time_ms"`
	BacktestWallMS    int64   `json:"backtest_wall_time_ms"`
	BacktestCPUMillis int64   `json:"backtest_cpu_time_ms"`
	PatternWallMS     int64   `json:"pattern_wall_time_ms"`
	Trades            int     `json:"trades"`
	WinRate           float64 `json:"win_rate"`
	NetPnL            string  `json:"net_pnl"`
	PatternsFound     int     `json:"patterns_found"`
	AllocatedBytes    uint64  `json:"allocated_bytes_total"`
	AllocCount        uint64  `json:"allocation_count_total"`
	PeakRSSBytes      uint64  `json:"peak_rss_bytes"`
}

type stageStat struct {
	Trials   []int64 `json:"trials_ms"`
	MinMS    int64   `json:"min_ms"`
	MaxMS    int64   `json:"max_ms"`
	MeanMS   float64 `json:"mean_ms"`
	StdDevMS float64 `json:"std_dev_ms"`
}

type report struct {
	GeneratedAtUTC string    `json:"generated_at_utc"`
	GoVersion      string    `json:"go_version"`
	GOOS           string    `json:"goos"`
	NumCPU         int       `json:"num_cpu"`
	DataDir        string    `json:"data_dir"`
	Symbol         string    `json:"symbol"`
	Interval       string    `json:"interval"`
	CandleCount    int       `json:"candle_count"`
	DatasetHash    string    `json:"dataset_hash"`
	FirstOpenUTC   string    `json:"first_open_utc"`
	LastOpenUTC    string    `json:"last_open_utc"`
	StrategyID     string    `json:"strategy_id"`
	Trials         []trial   `json:"trials"`
	BacktestStats  stageStat `json:"backtest_stats"`
	PatternStats   stageStat `json:"pattern_stats"`
	PeakRSSBytes   uint64    `json:"peak_rss_bytes_process"`
	CPUProfile     string    `json:"cpu_profile,omitempty"`
	HeapProfile    string    `json:"heap_profile,omitempty"`
	Limitations    []string  `json:"limitations"`
}

func main() {
	dataDir := flag.String("data-dir", "../audit_data", "Directory holding market_candles.csv")
	symbol := flag.String("symbol", "BTCUSDT", "Symbol to measure")
	interval := flag.String("interval", "5", "Candle interval to measure")
	trials := flag.Int("trials", 3, "Number of comparable trials")
	strategyID := flag.String("strategy", "trend_pullback", "Strategy id used for the backtest")
	cpuProfile := flag.String("cpuprofile", "", "Write a CPU pprof profile to this path")
	heapProfile := flag.String("heapprofile", "", "Write a heap pprof profile to this path")
	out := flag.String("out", "", "Write the measurement report as JSON to this path")
	flag.Parse()

	if *trials < 1 {
		fmt.Fprintln(os.Stderr, "trials must be >= 1")
		os.Exit(2)
	}

	candles, err := loadSeries(filepath.Join(*dataDir, "market_candles.csv"), *symbol, *interval)
	if err != nil {
		fmt.Fprintf(os.Stderr, "load candles: %v\n", err)
		os.Exit(1)
	}
	if len(candles) < 60 {
		fmt.Fprintf(os.Stderr, "not enough candles for a realistic measurement: got %d\n", len(candles))
		os.Exit(1)
	}

	if *cpuProfile != "" {
		f, err := os.Create(*cpuProfile)
		if err != nil {
			fmt.Fprintf(os.Stderr, "create cpu profile: %v\n", err)
			os.Exit(1)
		}
		if err := pprof.StartCPUProfile(f); err != nil {
			fmt.Fprintf(os.Stderr, "start cpu profile: %v\n", err)
			os.Exit(1)
		}
		defer func() {
			pprof.StopCPUProfile()
			_ = f.Close()
		}()
	}

	rep := report{
		GeneratedAtUTC: time.Now().UTC().Format(time.RFC3339),
		GoVersion:      runtime.Version(),
		GOOS:           runtime.GOOS,
		NumCPU:         runtime.NumCPU(),
		DataDir:        *dataDir,
		Symbol:         *symbol,
		Interval:       *interval,
		CandleCount:    len(candles),
		DatasetHash:    datasets.ComputeDatasetHash(candles),
		FirstOpenUTC:   candles[0].OpenTime.UTC().Format(time.RFC3339),
		LastOpenUTC:    candles[len(candles)-1].OpenTime.UTC().Format(time.RFC3339),
		StrategyID:     *strategyID,
		Limitations: []string{
			"The archive is a bounded single export, not a prospective observation stream; the measurement describes engine cost on this dataset only.",
			"CPUTimeMS is the sum of user and system CPU time of the whole process for the measured stage, therefore it includes the runtime bookkeeping of the process and is an upper bound for the stage.",
			"PeakRSSBytes is the process high water mark and therefore includes the loader, the JSON report writer and the Go runtime, not only the measured stage.",
		},
	}

	for i := 0; i < *trials; i++ {
		t, err := runTrial(candles, *strategyID, i+1)
		if err != nil {
			fmt.Fprintf(os.Stderr, "trial %d: %v\n", i+1, err)
			os.Exit(1)
		}
		rep.Trials = append(rep.Trials, t)
	}

	rep.BacktestStats = summarise(rep.Trials, func(t trial) int64 { return t.BacktestWallMS })
	rep.PatternStats = summarise(rep.Trials, func(t trial) int64 { return t.PatternWallMS })
	rep.PeakRSSBytes = peakRSS()

	if *heapProfile != "" {
		f, err := os.Create(*heapProfile)
		if err != nil {
			fmt.Fprintf(os.Stderr, "create heap profile: %v\n", err)
			os.Exit(1)
		}
		runtime.GC()
		if err := pprof.WriteHeapProfile(f); err != nil {
			fmt.Fprintf(os.Stderr, "write heap profile: %v\n", err)
			os.Exit(1)
		}
		_ = f.Close()
		rep.HeapProfile = *heapProfile
	}
	if *cpuProfile != "" {
		rep.CPUProfile = *cpuProfile
	}

	encoded, err := json.MarshalIndent(rep, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "encode report: %v\n", err)
		os.Exit(1)
	}
	if *out != "" {
		if err := os.WriteFile(*out, encoded, 0o644); err != nil {
			fmt.Fprintf(os.Stderr, "write report: %v\n", err)
			os.Exit(1)
		}
	}
	fmt.Println(string(encoded))
}

// loadSeries reads the archive candles and keeps the closed candles of one symbol and interval.
func loadSeries(path string, symbol string, interval string) ([]datasets.Candle, error) {
	loaded, err := auditdata.LoadCandles(path)
	if err != nil {
		return nil, err
	}
	out := make([]datasets.Candle, 0, len(loaded))
	for _, c := range loaded {
		if c.Symbol != symbol || c.Interval != interval || !c.IsClosed {
			continue
		}
		out = append(out, datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   c.Symbol,
			Interval: c.Interval,
			OpenTime: c.OpenTime.UTC(),
			Open:     c.Open,
			High:     c.High,
			Low:      c.Low,
			Close:    c.Close,
			Volume:   c.Volume,
			IsClosed: true,
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].OpenTime.Before(out[j].OpenTime) })
	return out, nil
}

// runTrial measures one backtest and one pattern mining pass over the same candle series.
func runTrial(candles []datasets.Candle, strategyID string, index int) (trial, error) {
	var before runtime.MemStats
	runtime.ReadMemStats(&before)

	cpuBefore := cpuTimeMS()
	wallStart := time.Now()
	engine := backtest.NewEngine(backtest.DefaultConfig())
	rep, err := engine.Run(candles, strategyID, nil)
	if err != nil {
		return trial{}, err
	}
	backtestWall := time.Since(wallStart)
	backtestCPU := cpuTimeMS() - cpuBefore

	patternCPUBefore := cpuTimeMS()
	patternStart := time.Now()
	mined, err := patterns.MinePatterns(candles, patterns.DefaultPatternConfig())
	if err != nil {
		return trial{}, err
	}
	patternWall := time.Since(patternStart)
	patternCPU := cpuTimeMS() - patternCPUBefore

	var after runtime.MemStats
	runtime.ReadMemStats(&after)

	return trial{
		Index:             index,
		WallTimeMS:        (backtestWall + patternWall).Milliseconds(),
		CPUTimeMS:         backtestCPU + patternCPU,
		BacktestWallMS:    backtestWall.Milliseconds(),
		BacktestCPUMillis: backtestCPU,
		PatternWallMS:     patternWall.Milliseconds(),
		Trades:            rep.TotalTrades,
		WinRate:           rep.WinRate,
		NetPnL:            rep.NetPnL.String(),
		PatternsFound:     len(mined),
		AllocatedBytes:    after.TotalAlloc - before.TotalAlloc,
		AllocCount:        after.Mallocs - before.Mallocs,
		PeakRSSBytes:      peakRSS(),
	}, nil
}

// summarise computes min, max, mean and standard deviation over comparable trials.
func summarise(trials []trial, pick func(trial) int64) stageStat {
	values := make([]int64, 0, len(trials))
	for _, t := range trials {
		values = append(values, pick(t))
	}
	if len(values) == 0 {
		return stageStat{}
	}
	stat := stageStat{Trials: values, MinMS: values[0], MaxMS: values[0]}
	var sum float64
	for _, v := range values {
		sum += float64(v)
		if v < stat.MinMS {
			stat.MinMS = v
		}
		if v > stat.MaxMS {
			stat.MaxMS = v
		}
	}
	stat.MeanMS = sum / float64(len(values))
	var variance float64
	for _, v := range values {
		diff := float64(v) - stat.MeanMS
		variance += diff * diff
	}
	variance /= float64(len(values))
	stat.StdDevMS = variance
	return stat
}

// cpuTimeMS returns the consumed user plus system CPU time of this process in milliseconds.
func cpuTimeMS() int64 {
	var usage syscall.Rusage
	if err := syscall.Getrusage(syscall.RUSAGE_SELF, &usage); err != nil {
		return 0
	}
	user := usage.Utime.Sec*1000 + int64(usage.Utime.Usec)/1000
	sys := usage.Stime.Sec*1000 + int64(usage.Stime.Usec)/1000
	return user + sys
}

// peakRSS returns the process high water mark of the resident set in bytes.
func peakRSS() uint64 {
	data, err := os.ReadFile("/proc/self/status")
	if err != nil {
		return 0
	}
	for _, line := range splitLines(string(data)) {
		if len(line) > 6 && line[:6] == "VmHWM:" {
			var value uint64
			if _, err := fmt.Sscanf(line[6:], "%d", &value); err == nil {
				return value * 1024
			}
		}
	}
	return 0
}

func splitLines(value string) []string {
	out := []string{}
	current := ""
	for _, r := range value {
		if r == '\n' {
			out = append(out, current)
			current = ""
			continue
		}
		current += string(r)
	}
	if current != "" {
		out = append(out, current)
	}
	return out
}
