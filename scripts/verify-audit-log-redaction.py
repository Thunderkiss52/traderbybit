#!/usr/bin/env python3
"""Source-level regression against accidental raw exception/payload logging.

Does not substitute for runtime exception-injection integration tests.
"""
from pathlib import Path
import re
root = Path(__file__).resolve().parents[1]
files = [root / 'app/Http/Middleware/ApiRequestDiagnostics.php',
         *(root / 'app/Http/Controllers/Api/V1' / f for f in (
             'AutonomousLearningController.php', 'ResearchHealthController.php',
             'ResearchIntelligenceController.php', 'ResearchJobController.php'))]
for path in files:
    source = path.read_text()
    assert not re.search(r'Log::error\([^;]*getMessage\(\)', source), path
    assert not re.search(r"['\"]exception['\"]\s*=>\s*\$e\b", source), path
    assert not re.search(r"['\"]payload['\"]\s*=>\s*\$request->all\(", source), path
assert "'error' => $error->getMessage()" not in (root / 'app/Research/Application/AutonomyResearchService.php').read_text()
for name in ('ProfitabilityResearchController.php', 'QuoteIntegrityController.php', 'StrategyResearchController.php'):
    source = (root / 'app/Http/Controllers/Api/V1' / name).read_text()
    assert "'message' => $e->getMessage()" not in source, name
    assert 'abort(409, $error->getMessage())' not in source, name
front = (root / 'frontend/src/pages/ResearchHealthPage.vue').read_text()
assert "queues.status === 'UNAVAILABLE'" in front
assert not re.search(r'queues\?\.[A-Za-z]+\?\.pending_jobs\s*\|\|\s*0', front)
print('PASS: hardened audit-log surfaces, promotion error and unavailable queue display')
