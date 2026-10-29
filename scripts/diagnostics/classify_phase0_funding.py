#!/usr/bin/env python3
"""Offline, read-only classification. Never connects to VECTOR or changes a ledger.

Inputs: Query 16 JSON rows and independently validated exchange evidence keyed by
position UUID. Evidence must cover the entire lifetime and include every expected
boundary as {at, rate, mark}, even when rate is zero. It must identify exchange,
symbol, covered_from, covered_through, reference and independently_validated=true.
An operator assertion of validation is an input, not something this script proves.
"""
import argparse
from collections import Counter
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP, getcontext
import hashlib
import json
from pathlib import Path


getcontext().prec = 80

def instant(value):
    result = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if result.tzinfo is None:
        raise ValueError('Timezone-aware timestamps required')
    return result


def classify(position, evidence=None):
    dec = lambda key: Decimal(str(position[key]))
    zero = Decimal(0)
    settlements = position.get('settlements') or []
    funding_sum = sum((Decimal(str(s['amount'])) for s in settlements), zero)
    if dec('net_pnl') != dec('gross_pnl') - dec('fees') + dec('funding') or dec('funding') != funding_sum:
        return 'CONFIRMED_FINANCIAL_DISCREPANCY'
    if not evidence or evidence.get('independently_validated') is not True or not evidence.get('reference'):
        return 'INSUFFICIENT_SOURCE_DATA'
    opened, closed = instant(position['opened_at']), instant(position['closed_at'])
    if (evidence.get('exchange') != position['exchange_id'] or evidence.get('symbol') != position['symbol']
            or instant(evidence['covered_from']) > opened or instant(evidence['covered_through']) < closed):
        return 'INSUFFICIENT_SOURCE_DATA'
    expected = evidence['expected_events']
    if len({instant(e['at']) for e in expected}) != len(expected):
        raise ValueError('Duplicate expected funding boundary')
    if any(not opened < instant(e['at']) <= closed for e in expected):
        raise ValueError('Expected event outside position lifetime')
    actual = {instant(e['at']): e for e in (position.get('market_events') or [])}
    if any(instant(e['at']) not in actual for e in expected):
        return 'MISSING_EXPECTED_FUNDING_INTERVALS'
    if set(actual) != {instant(e['at']) for e in expected}:
        return 'INSUFFICIENT_SOURCE_DATA'
    executions = position.get('executions') or []
    if not any(e['type'] == 'ENTRY' and instant(e['at']) == opened for e in executions):
        return 'INSUFFICIENT_SOURCE_DATA'
    expected_sum = zero
    for event in expected:
        at = instant(event['at'])
        remaining = dec('initial_quantity')
        for execution in sorted(executions, key=lambda e: instant(e['at'])):
            if instant(execution['at']) > at or execution['type'] == 'ENTRY':
                continue
            remaining = max(zero, remaining - Decimal(str(execution['quantity'])))
        mark, rate = Decimal(str(event['mark'])), Decimal(str(event['rate']))
        if mark <= zero:
            raise ValueError('Positive independently validated mark required')
        if remaining > zero:
            amount = remaining * mark * rate * (-1 if position['side'] == 'BUY' else 1)
            # PostgreSQL NUMERIC(24,12) stores ties away from zero.
            amount = amount.quantize(Decimal('0.000000000001'), rounding=ROUND_HALF_UP)
            matching = [s for s in settlements if instant(s['funding_at']) == at]
            if (len(matching) != 1 or Decimal(str(matching[0]['amount'])) != amount
                    or Decimal(str(matching[0]['quantity'])) != remaining
                    or Decimal(str(matching[0]['mark_price'])) != mark
                    or Decimal(str(matching[0]['rate'])) != rate):
                return 'CONFIRMED_FINANCIAL_DISCREPANCY'
            expected_sum += amount
        stored = actual[at]
        if Decimal(str(stored['rate'])) != rate or Decimal(str(stored['mark'])) != mark:
            return 'INSUFFICIENT_SOURCE_DATA'
    if expected_sum != dec('funding'):
        return 'CONFIRMED_FINANCIAL_DISCREPANCY'
    return 'PROVEN_FULLY_RECONCILED' if position['funding_complete'] else 'LEGACY_STATE_REQUIRES_MIGRATION_DECISION'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('positions', type=Path)
    parser.add_argument('--exchange-evidence', type=Path)
    args = parser.parse_args()
    rows = json.loads(args.positions.read_text(), parse_float=Decimal)
    evidence = json.loads(args.exchange_evidence.read_text(), parse_float=Decimal) if args.exchange_evidence else {}
    results = [{'id': row['id'], 'classification': classify(row, evidence.get(row['id']))} for row in rows]
    print(json.dumps({'positions_sha256': hashlib.sha256(args.positions.read_bytes()).hexdigest(),
                      'exchange_evidence_sha256': hashlib.sha256(args.exchange_evidence.read_bytes()).hexdigest() if args.exchange_evidence else None,
                      'counts': dict(Counter(r['classification'] for r in results)), 'positions': results}, indent=2))


if __name__ == '__main__':
    main()
