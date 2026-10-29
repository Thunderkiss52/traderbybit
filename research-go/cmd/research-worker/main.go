package main

import (
	"context"
	"database/sql"
	"flag"
	"fmt"
	"log"
	"math/rand"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/lib/pq"
	"github.com/traderbybit/research-go/internal/backtest"
	"github.com/traderbybit/research-go/internal/datasets"
	"github.com/traderbybit/research-go/internal/decimal"
	"github.com/traderbybit/research-go/internal/jobrunner"
	"github.com/traderbybit/research-go/internal/patterns"
	"github.com/traderbybit/research-go/internal/simulation"
	"github.com/traderbybit/research-go/internal/telemetry"
	"github.com/traderbybit/research-go/internal/walkforward"
)

func generateSyntheticCandles(count int) []datasets.Candle {
	candles := make([]datasets.Candle, count)
	baseTime := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	price := 50000.0
	rng := rand.New(rand.NewSource(42))

	for i := 0; i < count; i++ {
		change := (rng.Float64() - 0.495) * 200.0
		open := price
		close := price + change
		high := open
		if close > high {
			high = close
		}
		high += rng.Float64() * 50.0
		low := open
		if close < low {
			low = close
		}
		low -= rng.Float64() * 50.0
		vol := 10.0 + rng.Float64()*50.0

		price = close
		candles[i] = datasets.Candle{
			Exchange: "bybit",
			Category: "linear",
			Symbol:   "BTCUSDT",
			Interval: "5",
			OpenTime: baseTime.Add(time.Duration(i*5) * time.Minute),
			Open:     decimal.FromFloat64(open),
			High:     decimal.FromFloat64(high),
			Low:      decimal.FromFloat64(low),
			Close:    decimal.FromFloat64(close),
			Volume:   decimal.FromFloat64(vol),
			Turnover: decimal.FromFloat64(vol * close),
			IsClosed: true,
		}
	}
	return candles
}

func runBenchmark(count int) {
	fmt.Printf("=== Running Go Research Engine Benchmark (N=%d candles) ===\n", count)
	candles := generateSyntheticCandles(count)

	// 1. Backtest Benchmark
	btTracker := telemetry.Start()
	engine := backtest.NewEngine(backtest.DefaultConfig())
	report, err := engine.Run(candles, "trend_pullback", nil)
	btStats := btTracker.Stop(count)
	if err != nil {
		log.Fatalf("Backtest error: %v", err)
	}
	fmt.Printf("[BACKTEST] Trades=%d WinRate=%.2f%% NetPnL=%s Duration=%dms PeakRSS=%.2fMB Throughput=%.0f candles/s\n",
		report.TotalTrades, report.WinRate*100, report.NetPnL.String(),
		btStats.DurationMS, float64(btStats.PeakRSSBytes)/(1024*1024),
		float64(count)/(float64(btStats.DurationMS)/1000.0))

	// 2. Pattern Mining Benchmark
	patTracker := telemetry.Start()
	mined, err := patterns.MinePatterns(candles, patterns.DefaultPatternConfig())
	patStats := patTracker.Stop(count)
	if err != nil {
		log.Fatalf("Pattern error: %v", err)
	}
	fmt.Printf("[PATTERNS] Found=%d Duration=%dms PeakRSS=%.2fMB\n",
		len(mined), patStats.DurationMS, float64(patStats.PeakRSSBytes)/(1024*1024))

	// 3. Walk-Forward Benchmark
	wfoCfg := walkforward.DefaultWFOConfig()
	if count < 600 {
		wfoCfg.Folds = 2
	}
	if count >= 400 {
		wfoTracker := telemetry.Start()
		wfoRes, err := walkforward.Run(candles, wfoCfg, backtest.DefaultConfig())
		wfoStats := wfoTracker.Stop(count)
		if err != nil {
			log.Fatalf("WFO error: %v", err)
		}
		fmt.Printf("[WFO] Folds=%d WFE=%.3f Degradation=%.1f%% Duration=%dms PeakRSS=%.2fMB\n",
			wfoRes.FoldsCount, wfoRes.WalkForwardEfficiency, wfoRes.DegradationPercent,
			wfoStats.DurationMS, float64(wfoStats.PeakRSSBytes)/(1024*1024))
	} else {
		fmt.Println("[WFO] Skipped (insufficient candles for multi-fold walk-forward analysis)")
	}

	// 4. Monte Carlo Benchmark
	if len(report.Trades) >= 10 {
		mcTracker := telemetry.Start()
		mcRes, err := simulation.RunMonteCarlo(report.Trades, simulation.DefaultMonteCarloConfig())
		mcStats := mcTracker.Stop(len(report.Trades))
		if err != nil {
			log.Fatalf("Monte Carlo error: %v", err)
		}
		fmt.Printf("[MONTE_CARLO] Iterations=%d MedianFinal=%s RuinRisk=%.1f%% Duration=%dms\n",
			mcRes.Iterations, mcRes.FinalEquityP50.String(), mcRes.ProbabilityOfRuin*100, mcStats.DurationMS)
	}
	fmt.Println("=== Benchmark Completed Successfully ===")
}

func main() {
	benchmarkFlag := flag.Bool("benchmark", false, "Run high-throughput research benchmark")
	benchmarkCount := flag.Int("benchmark-count", 10000, "Number of candles for benchmark")
	standaloneFlag := flag.Bool("standalone", false, "Run standalone test and exit")
	onceFlag := flag.Bool("once", false, "Poll and execute at most one job then exit")
	pollSeconds := flag.Int("poll", 3, "Poll interval in seconds")
	flag.Parse()

	if *benchmarkFlag {
		runBenchmark(*benchmarkCount)
		return
	}

	if *standaloneFlag {
		fmt.Println("Running Go research worker in standalone mode...")
		runBenchmark(1000)
		return
	}

	// Read Database configuration from environment
	dbHost := os.Getenv("DB_HOST")
	if dbHost == "" {
		dbHost = "127.0.0.1"
	}
	dbPort := os.Getenv("DB_PORT")
	if dbPort == "" {
		dbPort = "5432"
	}
	dbUser := os.Getenv("DB_USERNAME")
	if dbUser == "" {
		dbUser = "bybit_signals"
	}
	dbPass := os.Getenv("DB_PASSWORD")
	dbName := os.Getenv("DB_DATABASE")
	if dbName == "" {
		dbName = "bybit_signals"
	}
	dbSSL := os.Getenv("DB_SSLMODE")
	if dbSSL == "" {
		dbSSL = "disable"
	}

	var connStr string
	if dbPass != "" {
		connStr = fmt.Sprintf("host=%s port=%s user=%s password='%s' dbname=%s sslmode=%s",
			dbHost, dbPort, dbUser, dbPass, dbName, dbSSL)
	} else {
		connStr = fmt.Sprintf("host=%s port=%s user=%s dbname=%s sslmode=%s",
			dbHost, dbPort, dbUser, dbName, dbSSL)
	}

	hostname, _ := os.Hostname()
	workerID := fmt.Sprintf("go-research-worker-%s-%d", hostname, os.Getpid())
	log.Printf("[worker] Initializing %s connecting to %s:%s db=%s", workerID, dbHost, dbPort, dbName)

	db, err := sql.Open("postgres", connStr)
	if err != nil {
		log.Fatalf("[worker] Failed to connect to database: %v", err)
	}
	defer db.Close()

	// Bound database connection pool
	db.SetMaxOpenConns(2)
	db.SetMaxIdleConns(1)
	db.SetConnMaxLifetime(10 * time.Minute)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Handle graceful termination on SIGTERM / SIGINT
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		sig := <-sigChan
		log.Printf("[worker] Received signal %v, terminating gracefully...", sig)
		cancel()
	}()

	runner := jobrunner.NewRunner(db, workerID)

	workerVersion := "1.0.0"
	supportedAlgorithms := []string{
		"backtest",
		"walk_forward",
		"pattern_mine",
		"replay",
		"monte_carlo",
		"feature_build",
		"skip_outcomes",
	}

	// Register worker in PostgreSQL research_workers table
	regCtx, regCancel := context.WithTimeout(context.Background(), 5*time.Second)
	if err := runner.RegisterWorker(regCtx, workerVersion, supportedAlgorithms, hostname, os.Getpid()); err != nil {
		log.Printf("[worker] Warning: Failed to register worker: %v", err)
	} else {
		log.Printf("[worker] Successfully registered %s (version=%s) in research registry", workerID, workerVersion)
	}
	regCancel()

	// Periodic background heartbeat goroutine (every 10 seconds)
	heartbeatTicker := time.NewTicker(10 * time.Second)
	defer heartbeatTicker.Stop()
	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case <-heartbeatTicker.C:
				hbCtx, hbCancel := context.WithTimeout(context.Background(), 3*time.Second)
				if err := runner.SendHeartbeat(hbCtx, "ready"); err != nil {
					log.Printf("[worker] Heartbeat warning: %v", err)
				}
				hbCancel()
			}
		}
	}()

	ticker := time.NewTicker(time.Duration(*pollSeconds) * time.Second)
	defer ticker.Stop()

	log.Printf("[worker] Worker loop started. Polling every %ds...", *pollSeconds)

	for {
		select {
		case <-ctx.Done():
			shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 5*time.Second)
			if err := runner.UnregisterWorker(shutdownCtx); err != nil {
				log.Printf("[worker] Unregister error: %v", err)
			}
			shutdownCancel()
			log.Println("[worker] Clean shutdown complete.")
			return
		case <-ticker.C:
			req, token, err := runner.ClaimNextJob(ctx, 60*time.Second)
			if err != nil {
				log.Printf("[worker] Claim error: %v", err)
				if *onceFlag {
					log.Printf("[worker] Exiting on claim error in -once mode.")
					return
				}
				continue
			}
			if req == nil {
				if *onceFlag {
					log.Println("[worker] No queued jobs found in -once mode. Exiting.")
					return
				}
				continue
			}

			_ = runner.SendHeartbeat(ctx, "busy")
			execErr := runner.ExecuteJob(ctx, req, token)
			_ = runner.RecordJobCompletion(ctx, req.JobID, execErr == nil)

			if execErr != nil {
				log.Printf("[worker] Execution error for job %s: %v", req.JobID, execErr)
			}

			if *onceFlag {
				log.Println("[worker] Completed single job in -once mode. Exiting.")
				return
			}
		}
	}
}
