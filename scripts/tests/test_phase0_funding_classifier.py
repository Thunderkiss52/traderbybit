import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('classifier', Path(__file__).resolve().parents[1] / 'diagnostics/classify_phase0_funding.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class FundingClassificationTest(unittest.TestCase):
    def setUp(self):
        self.row = dict(id='fixture', exchange_id='bybit', symbol='BTCUSDT', side='BUY',
                        opened_at='2026-10-25T07:00:00Z', closed_at='2026-10-25T09:00:00Z',
                        initial_quantity='1', gross_pnl='0', fees='0', funding='-1', net_pnl='-1', funding_complete=False,
                        executions=[dict(at='2026-10-25T07:00:00Z', type='ENTRY', quantity='1')],
                        market_events=[dict(at='2026-10-25T08:00:00Z', rate='0.01', mark='100')],
                        settlements=[dict(funding_at='2026-10-25T08:00:00Z', amount='-1', quantity='1', mark_price='100', rate='0.01')])
        self.evidence = dict(independently_validated=True, reference='SYNTHETIC-TEST-NOT-PRODUCTION', exchange='bybit', symbol='BTCUSDT',
                             covered_from=self.row['opened_at'], covered_through=self.row['closed_at'], expected_events=self.row['market_events'])

    def test_missing_source_is_not_assumed_complete(self):
        self.assertEqual('INSUFFICIENT_SOURCE_DATA', m.classify(self.row))

    def test_correct_incomplete_legacy_needs_migration_decision(self):
        self.assertEqual('LEGACY_STATE_REQUIRES_MIGRATION_DECISION', m.classify(self.row, self.evidence))
        self.assertFalse(self.row['funding_complete'])

    def test_independently_reconciled_complete(self):
        self.row['funding_complete'] = True
        self.assertEqual('PROVEN_FULLY_RECONCILED', m.classify(self.row, self.evidence))

    def test_missing_interval(self):
        self.row['market_events'] = []
        self.assertEqual('MISSING_EXPECTED_FUNDING_INTERVALS', m.classify(self.row, self.evidence))

    def test_financial_discrepancy(self):
        self.row['net_pnl'] = '0'
        self.assertEqual('CONFIRMED_FINANCIAL_DISCREPANCY', m.classify(self.row))

    def test_wrong_exchange_cannot_certify(self):
        self.evidence['exchange'] = 'binance'
        self.assertEqual('INSUFFICIENT_SOURCE_DATA', m.classify(self.row, self.evidence))

    def test_zero_payment_does_not_mean_missing_source(self):
        self.row.update(funding='0', net_pnl='0', settlements=[], market_events=[], closed_at='2026-10-25T07:30:00Z')
        self.evidence['expected_events'] = []
        self.assertEqual('LEGACY_STATE_REQUIRES_MIGRATION_DECISION', m.classify(self.row, self.evidence))


if __name__ == '__main__':
    unittest.main()
