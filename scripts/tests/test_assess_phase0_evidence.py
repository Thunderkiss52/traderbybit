import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('assessor', Path(__file__).resolve().parents[1] / 'diagnostics/assess_phase0_evidence.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class EvidenceAssessmentTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.evidence_dir = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_assessment_with_partial_and_stale_records(self):
        # Create mock artifacts
        (self.evidence_dir / 'funding_positions_clean.json').write_text(json.dumps([
            {'id': 'pos-1', 'funding_complete': False, 'net_identity_difference': '0'}
        ]))
        (self.evidence_dir / 'pipeline_anomalies_clean.json').write_text(json.dumps([
            {'id': 1, 'status': 'PARTIAL', 'stage': 'PAPER_PROCESSED'},
            {'id': 2, 'status': 'STALE', 'stage': 'SIGNALS_EVALUATED'}
        ]))
        (self.evidence_dir / 'evidence_closure.log').write_text('IDENTITY_PRESENT\nBOOTSTRAP_SEQUENCE_PROVEN\nfunding_mark_source_column_present')
        (self.evidence_dir / 'production_diagnostic.log').write_text('entry_block_required: true\ninvalid_executions_count: 0\nmismatch_count: 0')
        (self.evidence_dir / 'api_status.log').write_text('HTTP_STATUS=200 ENDPOINT=research/profitability/summary\nHTTP_STATUS=200 ENDPOINT=research/health/data-freshness')

        assessor = m.EvidenceAssessment(self.evidence_dir, '9ba6970b4514c32149866e77500757a5784ddcf8')
        res = assessor.assess()

        self.assertEqual('NOT READY', res['acceptance_verdict'])
        self.assertEqual(1, res['findings']['partial_cycles_count'])
        self.assertEqual(1, res['findings']['stale_events_count'])
        self.assertEqual(45, len(res['obligations']))
        self.assertIn(res['obligations']['VEC-P0-001-V2']['status'], ['PASS'])
        self.assertIn(res['obligations']['VEC-P0-003-V2']['status'], ['BLOCKED'])
        self.assertIn(res['obligations']['VEC-P0-004-V2']['status'], ['PASS'])
        self.assertIn(res['obligations']['VEC-P0-005-V2']['status'], ['PASS'])


if __name__ == '__main__':
    unittest.main()
