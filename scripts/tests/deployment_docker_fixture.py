#!/usr/bin/env python3
"""Docker CLI boundary fixture for failure injection into the real deploy scripts."""
import hashlib
import json
import os
from pathlib import Path
import signal
import sys

args = sys.argv[1:]
root = Path(os.environ['DOCKER_FIXTURE'])
state_file = root / 'docker-state.json'
state = json.loads(state_file.read_text())
with (root / 'calls.jsonl').open('a') as out:
    out.write(json.dumps({'args': args, 'tag': os.environ.get('IMAGE_TAG')}) + '\n')
mode = state.get('mode', '')
tag = os.environ.get('IMAGE_TAG', 'sha-' + '1' * 40)
new = 'sha-' + '2' * 40
services = ['app', 'web', 'queue', 'queue-bybit', 'queue-binance', 'scheduler', 'market-ws', 'audit-worker', 'backtest-worker', 'research-worker']


def component(service):
    return service if service in ('web', 'research-worker') else 'app'


def reference(service, release):
    target = component(service)
    digest = hashlib.sha256((target + release).encode()).hexdigest()
    return f'ghcr.io/vector/fixture/{target}:{release}@sha256:{digest}'


def image_id(ref):
    return 'sha256:' + hashlib.sha256(ref.encode()).hexdigest()


def save():
    state_file.write_text(json.dumps(state))


if args[0] == 'compose':
    # Skip Compose's global arguments, keeping actual service operation arguments.
    index = next(i for i, a in enumerate(args) if a in ('config', 'ps', 'pull', 'up', 'run', 'exec', 'logs', 'restart', 'stop'))
    op, rest = args[index], args[index + 1:]
    if op == 'config':
        print(json.dumps({'services': {s: {'image': reference(s, tag)} for s in services}}))
    elif op == 'ps':
        if '-q' in rest:
            service = rest[-1]
            print('cid-' + service if service != '-q' else '\n'.join('cid-' + s for s in services))
    elif op == 'pull':
        if mode == 'pull-failure':
            sys.exit(1)
    elif op == 'up':
        if 'postgres' in rest or 'redis' in rest:
            raise RuntimeError('Infrastructure recreation forbidden in update')
        state['running'] = tag
        save()
        if tag == new and mode in ('partial-transition', 'interrupted'):
            if mode == 'interrupted':
                os.kill(os.getppid(), signal.SIGTERM)
            sys.exit(1)
    elif op == 'run':
        if 'migrate' in rest:
            if mode == 'migration-failure':
                sys.exit(1)
            if mode == 'forward-migration':
                state['migrations'] += '\n2026_11_06_additive_change'
                save()
    elif op == 'exec':
        if 'pg_restore' in rest and mode == 'backup-verification-failure':
            sys.exit(1)
        if 'pg_dump' in rest:
            if mode == 'backup-failure' and '--schema-only' not in rest:
                sys.exit(1)
            print(state['migrations'] if '--schema-only' in rest else 'FIXTURE_BACKUP')
        elif 'psql' in rest:
            sql = rest[-1]
            if 'ORDER BY migration' in sql:
                print(state['migrations'])
            elif 'SELECT 1;' in sql:
                print('1')
            elif 'COUNT(*) FROM migrations' in sql:
                print('1')
            elif 'pg_index' in sql:
                print('t|t|(superseded_by IS NULL)|exchange,category,symbol,strategy_id,strategy_version,decision_time')
        elif 'redis-cli' in rest:
            print('PONG')
    elif op == 'logs':
        print('Worker loop started')
elif args[:2] == ['image', 'inspect']:
    ref = args[-1]
    if mode == 'missing-image' and '/research-worker:' in ref and new in ref:
        sys.exit(1)
    if '--format' in args:
        fmt = args[args.index('--format') + 1]
        if 'revision' in fmt:
            print('f' * 40 if mode == 'wrong-revision' and new in ref else ref.split(':sha-')[1].split('@')[0])
        elif '.Id' in fmt:
            print(image_id(ref))
elif args[0] == 'inspect':
    service = args[-1].removeprefix('cid-')
    fmt = args[args.index('--format') + 1]
    release = state['running']
    ref = reference(service, release)
    if mode == 'wrong-image' and release == new and service == 'queue-binance':
        ref = reference(service, 'sha-' + '3' * 40)
    if 'Config.Image' in fmt:
        print(ref)
    elif fmt == '{{.Image}}':
        print(image_id(ref))
    elif 'Status' in fmt:
        print('unhealthy' if release == new and mode in ('health-failure', 'forward-migration') else 'running' if service == 'research-worker' or '.Health' not in fmt else 'healthy')
    elif '.LogPath' in fmt:
        print(root / 'log')
elif args[:2] == ['image', 'ls']:
    if mode == 'cleanup-failure':
        sys.exit(1)
elif args[:2] == ['image', 'rm']:
    raise RuntimeError('No deletable images in update fixture')
elif args[:2] == ['volume', 'inspect']:
    print(root)
elif args[0] in ('exec', 'logs'):
    print('Worker loop started')
elif args[0] == 'events':
    signal.pause()
elif args[0] == 'tag':
    pass
elif args[:2] == ['ps', '-aq']:
    pass
else:
    raise RuntimeError('Unhandled Docker fixture command: ' + repr(args))
