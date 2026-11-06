#!/usr/bin/env python3
"""Measure observed worker shutdown using one host's monotonic clock."""
import json
import signal
import subprocess
import sys
import time

project, output = sys.argv[1:]
workers = {'queue', 'queue-bybit', 'queue-binance', 'scheduler', 'market-ws',
           'audit-worker', 'backtest-worker', 'research-worker'}
started = {}
durations = {}
stream = subprocess.Popen(['docker', 'events', '--filter', 'type=container', '--filter',
                           f'label=com.docker.compose.project={project}', '--format', '{{json .}}'],
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)


def stop(_signal, _frame):
    stream.terminate()


signal.signal(signal.SIGTERM, stop)
signal.signal(signal.SIGINT, stop)
try:
    for line in stream.stdout:
        event = json.loads(line)
        service = event.get('Actor', {}).get('Attributes', {}).get('com.docker.compose.service')
        action = event.get('Action')
        if service not in workers:
            continue
        now = time.monotonic_ns() // 1000000
        if action == 'kill':
            started.setdefault(service, now)
        elif action == 'die' and service in started:
            durations[service] = now - started[service]
finally:
    stream.wait()
    with open(output, 'w') as out:
        json.dump({'stage': 'worker_shutdown_grace', 'elapsed_ms': max(durations.values(), default=0),
                   'services_ms': durations, 'observation': 'monotonic receipt of Docker kill/die events',
                   'status': 'OBSERVED' if durations else 'NO_SHUTDOWN_OBSERVED'}, out)
