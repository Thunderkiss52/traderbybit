#!/usr/bin/env python3
"""Local shard benchmark: dedicated containers and a clean checkout per lane."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import threading
import time

ROOT = Path(__file__).resolve().parents[2]


def command(args, **kwargs):
    return subprocess.check_output(args, text=True, **kwargs).strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--workers", type=int, choices=(2, 4), required=True)
    parser.add_argument("--image", default="vector-agent-php85-test:local")
    parser.add_argument("--ref", default="HEAD")
    parser.add_argument("--binaries", type=Path, required=True)
    a = parser.parse_args()
    a.output = a.output.resolve()
    a.output.mkdir(parents=True, exist_ok=False)
    snapshot = subprocess.check_output(["git", "archive", a.ref], cwd=ROOT)
    lanes = json.loads((a.plan / "plan.json").read_text())["lanes"]
    task = f"vector-ci-bench-{int(time.time())}-{a.workers}"
    stop_sampling = threading.Event()

    def sample_resources():
        with (a.output / "resources.jsonl").open("w") as stream:
            while not stop_sampling.is_set():
                ids = command(["docker", "ps", "--filter", f"label=vector.task={task}", "-q"]).split()
                if ids:
                    result = subprocess.run(["docker", "stats", "--no-stream", "--format", "{{json .}}", *ids], capture_output=True, text=True)
                    stream.write(json.dumps(dict(timestamp=time.time(), exit_code=result.returncode,
                                                 containers=[json.loads(line) for line in result.stdout.splitlines() if line.startswith("{")])) + "\n")
                    stream.flush()
                stop_sampling.wait(3)

    def run(lane):
        start = time.monotonic()
        work = a.output / lane
        work.mkdir()
        with tarfile.open(fileobj=io.BytesIO(snapshot)) as archive:
            archive.extractall(work)
        shutil.copytree(ROOT / "scripts/ci", work / "scripts/ci", dirs_exist_ok=True)
        shutil.copytree(a.plan, work / "ci-plan")
        changed = command(["git", "diff", "--name-only", a.ref, "--", "app", "tests", "docker/scripts", "composer.lock"], cwd=ROOT).splitlines()
        for path in set(changed + ["docker/scripts/release-images.py"]):
            shutil.copy2(ROOT / path, work / path)
        for path in ("storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs", "bootstrap/cache"):
            (work / path).mkdir(parents=True, exist_ok=True)
        names = [f"{task}-{lane}-pg", f"{task}-{lane}-redis"]
        log = open(a.output / f"{lane}.log", "w")
        result = {"lane": lane}
        try:
            for name, image, port, extra in ((names[0], "postgres:17-alpine", "5432", ["-e", "POSTGRES_DB=bybit_test", "-e", "POSTGRES_PASSWORD=testpass"]),
                                             (names[1], "redis:7.4-alpine", "6379", [])):
                before = time.monotonic()
                command(["docker", "run", "-d", "--name", name, "--label", f"vector.task={task}", "-p", f"127.0.0.1::{port}", *extra, image], stderr=log)
                readiness = ["pg_isready", "-U", "postgres", "-d", "bybit_test"] if port == "5432" else ["redis-cli", "ping"]
                for _ in range(150):
                    if subprocess.run(["docker", "exec", name, *readiness], stdout=log, stderr=log).returncode == 0:
                        break
                    time.sleep(0.2)
                else:
                    raise RuntimeError(f"Unready owned service: {name}")
                result["postgres_init_seconds" if port == "5432" else "redis_init_seconds"] = time.monotonic() - before
            pg_port = command(["docker", "port", names[0], "5432"]).split(":")[-1]
            redis_port = command(["docker", "port", names[1], "6379"]).split(":")[-1]
            env = {"APP_ENV": "testing", "DB_CONNECTION": "pgsql", "DB_HOST": "127.0.0.1", "DB_PORT": pg_port,
                   "DB_DATABASE": "bybit_test", "DB_USERNAME": "postgres", "DB_PASSWORD": "testpass", "DB_URL": "",
                   "RUN_DB": "bybit_test", "REDIS_HOST": "127.0.0.1", "REDIS_PORT": redis_port,
                   "REDIS_PREFIX": f"vector-ci-{lane}-", "REDIS_CLIENT": "predis", "XDEBUG_MODE": "off"}
            for key in ("HOST", "PORT", "DATABASE", "USERNAME", "PASSWORD"):
                env["TEST_DB_" + key] = env["DB_" + key]
            env_file = a.output / f"{lane}.env"
            env_file.write_text("".join(f"{k}={v}\n" for k, v in env.items()))
            before = time.monotonic()
            process = subprocess.run(["docker", "run", "--rm", "--cpus=2", "--name", f"{task}-{lane}-php", "--label", f"vector.task={task}",
                                      "--network", "host", "--env-file", str(env_file),
                                      "-v", f"{work}:/var/www/html", "-v", f"{ROOT}/vendor:/var/www/html/vendor:ro",
                                      "-v", f"{a.binaries.resolve()}:/var/www/html/research-go/bin:ro",
                                      "-v", "/usr/libexec/docker/cli-plugins/docker-compose:/usr/local/bin/docker-compose:ro",
                                      "--entrypoint", "bash", a.image, "-c",
                                      'set -e; printf \'#!/bin/sh\\nif [ "$1" = compose ]; then shift; exec docker-compose "$@"; fi; exit 1\\n\' > /usr/local/bin/docker; chmod +x /usr/local/bin/docker; mkdir -p bin; cp research-go/bin/research-worker bin/research-worker; bash scripts/ci/prepare_test_environment.sh; php scripts/ci/record-isolation.php; mv ci-results/isolation.json ci-results/' + lane + '-isolation.json; python3 scripts/ci/php_shards.py run ' + lane],
                                     stdout=log, stderr=log)
            result.update(exit_code=process.returncode, php_and_migrations_seconds=time.monotonic() - before)
            # Evidence remains outside the container even after test failure.
        finally:
            for name in names:
                subprocess.run(["docker", "rm", "-f", "-v", name], stdout=log, stderr=log)
            log.close()
            result["elapsed_seconds"] = time.monotonic() - start
            print(json.dumps(result), flush=True)
        return result

    start = time.monotonic()
    sampler = threading.Thread(target=sample_resources, daemon=True)
    sampler.start()
    try:
        with ThreadPoolExecutor(max_workers=a.workers) as executor:
            results = list(executor.map(run, lanes))
    finally:
        stop_sampling.set()
        sampler.join()
    (a.output / "benchmark.json").write_text(json.dumps(dict(workers=a.workers, host_cpus=os.cpu_count(), php_cpus_per_lane=2,
                                                           elapsed_seconds=time.monotonic() - start, lanes=results), indent=2) + "\n")
    raise SystemExit(1 if any(r["exit_code"] for r in results) else 0)


if __name__ == "__main__":
    main()
