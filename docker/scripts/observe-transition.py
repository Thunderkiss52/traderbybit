#!/usr/bin/env python3
"""Measure observed worker shutdown using one host's monotonic clock."""
import ctypes
import json
import os
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
# Linux: terminate the read-only event stream if the deployment shell is killed.
parent = os.getppid()
if ctypes.CDLL(None).prctl(1, signal.SIGTERM, 0, 0, 0) != 0:
    stream.terminate()
if os.getppid() != parent:
    stream.terminate()
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
    status = stream.wait()
    observation_status = 'OBSERVED' if durations else 'NO_SHUTDOWN_OBSERVED' if status in (0, -signal.SIGTERM, -signal.SIGINT) else 'UNAVAILABLE'
    with open(output, 'w') as out:
        json.dump({'stage': 'worker_shutdown_grace', 'elapsed_ms': max(durations.values(), default=None),
                   'services_ms': durations, 'observation': 'monotonic receipt of Docker kill/die events',
                   'status': observation_status}, out)
