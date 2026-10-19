#!/usr/bin/env python3
"""
Baseline Performance Measurement Script for P0
Measures cold and warm response times for key API routes and database queries.
Saves measured results to reports/evidence/p0_performance_baseline.json.
"""

import json
import os
import subprocess
import time

def run_php(code):
    cmd = [
        "php", "-d", "xdebug.mode=off", "artisan", "tinker", "--execute", code
    ]
    t0 = time.perf_counter()
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    elapsed_ms = (time.perf_counter() - t0) * 1000
    return elapsed_ms, res.stdout, res.returncode

def measure_route(uri):
    # Benchmark through Laravel application kernel via artisan command
    code = f"""
$kernel = app()->make(Illuminate\\Contracts\\Http\\Kernel::class);
$request = Illuminate\\Http\\Request::create('{uri}', 'GET');
$t0 = microtime(true);
$response = $kernel->handle($request);
$elapsed = (microtime(true) - $t0) * 1000;
echo json_encode(['status' => $response->getStatusCode(), 'ms' => round($elapsed, 2)]);
"""
    cmd = ["php", "-d", "xdebug.mode=off", "artisan", "tinker", "--execute", code]
    env = os.environ.copy()
    env.update({
        "DB_CONNECTION": "pgsql",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "25432",
        "DB_DATABASE": "bybit_test",
        "DB_USERNAME": "postgres",
        "DB_PASSWORD": "testpass",
        "REDIS_CLIENT": "predis",
        "REDIS_PORT": "26379"
    })
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env)
    try:
        # Extract json output from stdout
        for line in res.stdout.splitlines():
            line = line.strip()
            if line.startswith('{"status"'):
                return json.loads(line)
    except Exception as e:
        pass
    return {"status": 0, "ms": 0, "error": res.stderr}

def main():
    print("Measuring P0 Performance Baselines...")
    routes = [
        "/api/v1/system/version",
        "/api/v1/research/capabilities",
        "/api/v1/research/health/data-freshness",
        "/api/v1/research/learning/overview",
        "/api/v1/dashboard"
    ]

    results = {}
    for r in routes:
        # Cold run
        cold = measure_route(r)
        # Warm runs (3 runs)
        warm_times = []
        for _ in range(3):
            w = measure_route(r)
            if w.get('ms'):
                warm_times.append(w['ms'])
        
        warm_times.sort()
        p50 = warm_times[len(warm_times)//2] if warm_times else 0
        p95 = warm_times[-1] if warm_times else 0

        results[r] = {
            "cold_ms": cold.get('ms', 0),
            "warm_samples": warm_times,
            "warm_p50_ms": p50,
            "warm_p95_ms": p95,
            "status": cold.get('status', 0)
        }
        print(f"  {r}: cold={cold.get('ms', 0)}ms, warm_p50={p50}ms, warm_p95={p95}ms (status: {cold.get('status', 0)})")

    os.makedirs("reports/evidence", exist_ok=True)
    with open("reports/evidence/p0_performance_baseline.json", "w") as f:
        json.dump(results, f, indent=2)

    print("\n[OK] Baseline recorded in reports/evidence/p0_performance_baseline.json")

if __name__ == '__main__':
    main()
