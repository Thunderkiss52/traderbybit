# VECTOR v1.1 launcher preflight
- Run ID: vector-20261021T143106Z-373450
- UTC start: 2026-10-21T14:31:06Z
- Repository: /home/thunder/work/traderbybit
- User: thunder (uid=1000)
- Branch: main
- Git HEAD: f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55
- Spec: VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md
- Spec SHA-256: 6ece3d671ff7f63ca1bb4092cf47701886dc1f89e3a7b6e947b853fda57fbd96
- OpenCode: 1.18.33
- Go: go version go1.18.1 linux/amd64
- PHP: PHP 8.4.24 (cli) (built: Jul 30 2026 15:22:53) (NTS)
- Node: v20.20.0
- npm: 10.8.2
- PostgreSQL client: psql (PostgreSQL) 14.24 (Ubuntu 14.24-0ubuntu0.22.04.1)
- MAX_HOURS: 3
- Soft shutdown UTC: 2026-10-21T16:31:06Z
- Hard deadline UTC: 2026-10-21T17:31:06Z
- Disk: /dev/sdd        1007G         122G  835G           13% /

## Git status before launch
 M app/Backtest/Application/BacktestSimulationEngine.php
 M app/Http/Middleware/RequireResearchToken.php
 M app/MarketData/Application/CandleSynchronizer.php
 M app/MarketData/Application/ClosedCandleIngestor.php
 M app/MarketData/Application/MarketStreamSupervisor.php
 M app/MarketData/Infrastructure/Http/LaravelHttpTransport.php
 M app/MarketData/Infrastructure/Http/TransportResponse.php
 M app/Providers/AppServiceProvider.php
 M bootstrap/app.php
 M config/paper.php
 M config/research.php
 M config/services.php
 M docker/scripts/common.sh
A  docs/QUOTE_INTEGRITY_SPEC_FINAL.md
 M frontend/src/layouts/MainLayout.vue
 M frontend/src/router/index.ts
 M research-go/cmd/research-worker/main.go
 M research-go/internal/algorithms/indicators.go
 M research-go/internal/algorithms/indicators_test.go
 M research-go/internal/backtest/engine.go
 M research-go/internal/backtest/engine_test.go
 M research-go/internal/contracts/job.go
 M research-go/internal/datasets/candle.go
 M research-go/internal/decimal/decimal.go
 M research-go/internal/decimal/decimal_test.go
 M research-go/internal/jobrunner/runner.go
 M research-go/internal/jobrunner/runner_test.go
 M research-go/internal/patterns/miner_test.go
 M research-go/internal/simulation/montecarlo.go
 M research-go/internal/walkforward/wfo.go
 M research-go/internal/walkforward/wfo_test.go
 M routes/api.php
 M tests/Feature/Backtest/BacktestSimulationTest.php
 M tests/Integration/LedgerReconciliationPostgresIntegrationTest.php
?? VECTOR_AUTONOMOUS_PROFIT_RESEARCH_MASTER_SPEC_V1_EN.md
?? VECTOR_NET_PROFIT_GROWTH_SPEC_V1_1.md
?? VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md
?? app/Http/Controllers/Api/V1/ProfitabilityResearchController.php
?? app/Http/Controllers/Api/V1/QuoteIntegrityController.php
?? app/Http/Middleware/EnsureQuoteIntegrityEnabled.php
?? app/QuoteIntegrity/Application/QimAlertService.php
?? app/QuoteIntegrity/Application/QimComparisonEngine.php
?? app/QuoteIntegrity/Application/QimEvidenceImportService.php
?? app/QuoteIntegrity/Application/QimFeatureFlag.php
?? app/QuoteIntegrity/Application/QimGapRecorder.php
?? app/QuoteIntegrity/Application/QimIncidentService.php
?? app/QuoteIntegrity/Application/QimMeasurementGuard.php
?? app/QuoteIntegrity/Application/QimObservationService.php
?? app/QuoteIntegrity/Application/QimRestObserver.php
?? app/QuoteIntegrity/Application/QimWsObserver.php
?? app/QuoteIntegrity/Console/QimMeasurementReportCommand.php
?? app/QuoteIntegrity/Console/QimPurgeExpiredCommand.php
?? app/QuoteIntegrity/Console/QimRunChecksCommand.php
?? app/QuoteIntegrity/Console/QimSampleBarsCommand.php
?? app/QuoteIntegrity/Domain/QimChannel.php
?? app/QuoteIntegrity/Domain/QimClassification.php
?? app/QuoteIntegrity/Domain/QimClockSyncState.php
?? app/QuoteIntegrity/Domain/QimEvidenceProvenance.php
?? app/QuoteIntegrity/Domain/QimGapEventType.php
?? app/QuoteIntegrity/Domain/QimIncidentStatus.php
?? app/QuoteIntegrity/Domain/QimInvestigationStatus.php
?? app/QuoteIntegrity/Http/Requests/EvidenceUploadRequest.php
?? app/QuoteIntegrity/Http/Resources/QimAlertResource.php
?? app/QuoteIntegrity/Http/Resources/QimComparisonResource.php
?? app/QuoteIntegrity/Http/Resources/QimEvidenceResource.php
?? app/QuoteIntegrity/Http/Resources/QimIncidentDetailResource.php
?? app/QuoteIntegrity/Http/Resources/QimIncidentResource.php
?? app/QuoteIntegrity/Http/Resources/QimStatusResource.php
?? app/QuoteIntegrity/Http/Resources/QimSummaryResource.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimAlertRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimComparisonRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimEvidenceFileRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimGapEventRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimGapEventRepository.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimIncidentEventRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimIncidentRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimObservationRecord.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimObservationRepository.php
?? app/QuoteIntegrity/Infrastructure/Persistence/QimPurgeAuditRecord.php
?? app/Research/Application/AutonomyResearchService.php
?? app/Research/Application/CostMfeMaeService.php
?? app/Research/Application/DecisionEvidenceService.php
?? app/Research/Application/DependenceConcentrationService.php
?? app/Research/Application/MetricRegistryService.php
?? app/Research/Application/RiskCapitalMetricsService.php
?? app/Research/Application/SlippageAttributionService.php
?? app/Research/Domain/Autonomy/AllocationProposal.php
?? app/Research/Domain/Autonomy/CandidateHypothesis.php
?? app/Research/Domain/Autonomy/CandidateRegistry.php
?? app/Research/Domain/Autonomy/ChallengerExperiment.php
?? app/Research/Domain/Autonomy/DataSufficiencyPolicy.php
?? app/Research/Domain/Autonomy/PaperPromotionPolicy.php
?? app/Research/Domain/Autonomy/PromotionControlPolicy.php
?? app/Research/Domain/Concentration/ConcentrationAnalyzer.php
?? app/Research/Domain/Concentration/CrossSymbolExposure.php
?? app/Research/Domain/Concentration/EquityAlignment.php
?? app/Research/Domain/Concentration/OverlapCluster.php
?? app/Research/Domain/Concentration/OverlapClusterBuilder.php
?? app/Research/Domain/Concentration/PositionInterval.php
?? app/Research/Domain/Concentration/PositionIntervalFactory.php
?? app/Research/Domain/CostMfe/CostAttribution.php
?? app/Research/Domain/CostMfe/MfeMaeCalculator.php
?? app/Research/Domain/CostMfe/MfeMaeResult.php
?? app/Research/Domain/CostMfe/PreEntryMfeEstimator.php
?? app/Research/Domain/CostMfe/PriceCandle.php
?? app/Research/Domain/Decision/DecisionGate.php
?? app/Research/Domain/Decision/DecisionInputPackage.php
?? app/Research/Domain/Decision/DecisionOutcome.php
?? app/Research/Domain/Decision/DecisionState.php
?? app/Research/Domain/Decision/HypothesisPriority.php
?? app/Research/Domain/MetricRegistry.php
?? app/Research/Domain/ResearchCandidateHypothesisRecord.php
?? app/Research/Domain/ResearchChallengerExperimentRecord.php
?? app/Research/Domain/ResearchConcentrationClusterMemberRecord.php
?? app/Research/Domain/ResearchConcentrationClusterRecord.php
?? app/Research/Domain/ResearchCostAttributionRecord.php
?? app/Research/Domain/ResearchDecisionRecord.php
?? app/Research/Domain/ResearchGenerationRecord.php
?? app/Research/Domain/ResearchMfeMaeRecord.php
?? app/Research/Domain/ResearchPromotionControlRecord.php
?? app/Research/Domain/ResearchPromotionDecisionRecord.php
?? app/Research/Domain/SlippageAttributionFillRecord.php
?? app/Research/Domain/SlippageAttributionRecord.php
?? app/Research/Domain/StrategyRiskMetricRecord.php
?? app/Research/Exceptions/AppendOnlyRecordException.php
?? app/Research/Exceptions/IdempotencyConflictException.php
?? bybit_test
?? config/quote_integrity.php
?? database/migrations/2026_10_20_100000_create_qim_observations_table.php
?? database/migrations/2026_10_20_100100_create_qim_gap_events_table.php
?? database/migrations/2026_10_20_100200_create_qim_purge_audit_table.php
?? database/migrations/2026_10_20_100300_create_qim_analysis_tables.php
?? database/migrations/2026_10_20_100400_create_qim_evidence_files_table.php
?? database/migrations/2026_10_20_200000_create_slippage_attribution_tables.php
?? database/migrations/2026_10_20_300000_create_strategy_risk_metrics_tables.php
?? database/migrations/2026_10_21_100000_add_p2_generation_provenance_and_metric_reasons.php
?? database/migrations/2026_10_21_104500_create_research_decision_records_table.php
?? database/migrations/2026_10_21_105500_create_research_autonomy_tables.php
?? database/migrations/2026_10_21_110000_create_research_concentration_tables.php
?? database/migrations/2026_10_21_111500_add_promotion_controls_to_research_autonomy.php
?? database/migrations/2026_10_21_113000_create_research_cost_mfe_tables.php
?? docs/QUOTE_INTEGRITY_SPEC_REV2.md
?? docs/source-capability-matrix.md
?? frontend/src/components/qim/QimAlertsPanel.vue
?? frontend/src/components/qim/QimComparisonsTable.vue
?? frontend/src/components/qim/QimEvidenceImport.vue
?? frontend/src/components/qim/QimIncidentDetail.vue
?? frontend/src/components/qim/QimIncidentsTable.vue
?? frontend/src/components/qim/QimStatusBanner.vue
?? frontend/src/components/qim/QimSummaryCards.vue
?? frontend/src/pages/ProfitabilityResearchPage.vue
?? frontend/src/pages/QuoteIntegrityPage.vue
?? frontend/src/services/quoteIntegrityApi.ts
?? frontend/tests/profitability_research.test.js
?? frontend/tests/quote_integrity.test.js
?? reports/evidence/qim_l1_evidence.json
?? reports/evidence/qim_q0_evidence.json
?? reports/overnight/BLOCKERS.md
?? reports/overnight/CHANGES.md
?? reports/overnight/GO_PROFILE_BASELINE.md
?? reports/overnight/NEXT_SESSION_PROMPT.md
?? reports/overnight/PREFLIGHT.md
?? reports/overnight/RUN_STATUS.md
?? reports/overnight/checkpoints/phase_1a_expired_position/diff.patch
?? reports/overnight/checkpoints/phase_1a_expired_position/sha256.txt
?? reports/overnight/checkpoints/phase_1a_expired_position/status.txt
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/diff.err
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/diff.patch
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/diffstat.txt
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/integrity.txt
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/sha256.txt
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/snapshot.tar.gz
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/status.txt
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/tar.err
?? reports/overnight/checkpoints/phase_1a_residual_20261021T084202Z/untracked.txt
?? reports/overnight/checkpoints/phase_1b_primary_invariant/diff.patch
?? reports/overnight/checkpoints/phase_1b_primary_invariant/status.txt
?? reports/overnight/checkpoints/phase_go_rsi_diff_20261020T222944Z.patch
?? reports/overnight/checkpoints/phase_go_rsi_diff_20261020T222944Z.sha256
?? reports/overnight/checkpoints/phase_p2_diff_20261020T215707Z.patch
?? reports/overnight/checkpoints/phase_p2_diff_20261020T215707Z.sha256
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/diff.err
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/diff.patch
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/diffstat.txt
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/integrity.txt
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/sha256.txt
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/snapshot.tar.gz
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/status.txt
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/tar.err
?? reports/overnight/checkpoints/phase_p2_risk_capital_20261021T090444Z/untracked.txt
?? reports/overnight/checkpoints/phase_p2_status_20261020T215707Z.txt
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/diff.err
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/diff.patch
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/diffstat.txt
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/integrity.txt
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/sha256.txt
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/snapshot.tar.gz
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/status.txt
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/tar.err
?? reports/overnight/checkpoints/phase_p4_cost_mfe_20261021T102225Z/untracked.txt
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/diff.err
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/diff.patch
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/diffstat.txt
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/integrity.txt
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/sha256.txt
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/snapshot.tar.gz
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/status.txt
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/tar.err
?? reports/overnight/checkpoints/phase_p5_concentration_20261021T095939Z/untracked.txt
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/diff.err
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/diff.patch
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/diffstat.txt
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/integrity.txt
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/sha256.txt
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/snapshot.tar.gz
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/status.txt
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/tar.err
?? reports/overnight/checkpoints/phase_p6_decision_gate_20261021T103102Z/untracked.txt
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/diff.err
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/diff.patch
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/diffstat.txt
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/integrity.txt
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/sha256.txt
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/snapshot.tar.gz
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/status.txt
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/tar.err
?? reports/overnight/checkpoints/phase_s7_candidates_20261021T093314Z/untracked.txt
?? reports/overnight/opencode-session.log
?? reports/overnight/opencode-session.log.20261020T210459Z
?? reports/overnight/opencode-session.log.20261020T213949Z
?? reports/overnight/opencode-session.log.20261021T083043Z
?? reports/overnight/runs/vector-20261021T143106Z-373450/PREFLIGHT.md
?? reports/overnight/worktree-snapshot-20261020T205807Z.tar.gz
?? reports/overnight/worktree-snapshot-20261020T210459Z.tar.gz
?? reports/overnight/worktree-snapshot-20261020T211200Z.tar.gz
?? reports/overnight/worktree-snapshot-20261020T213949Z.tar.gz
?? reports/overnight/worktree-snapshot-20261021T083043Z.tar.gz
?? reports/profit-growth/00-capability-and-data-inventory.md
?? reports/profit-growth/01-g1-read-only-economics-foundation.md
?? reports/profit-growth/02-p1-slippage-attribution.md
?? reports/profit-growth/03-p2-risk-and-capital-efficiency.md
?? reports/profit-growth/04-s7-research-candidates.md
?? reports/profit-growth/05-p5-concentration-dependence.md
?? reports/profit-growth/06-p4-cost-mfe-mae.md
?? reports/profit-growth/07-p6-decision-gate.md
?? reports/profit-growth/08-a0-a3-autonomy.md
?? reports/profit-growth/A0_A3_evidence.json
?? reports/profit-growth/G1_evidence.json
?? reports/profit-growth/P1_evidence.json
?? reports/profit-growth/P2_evidence.json
?? reports/profit-growth/P4_evidence.json
?? reports/profit-growth/P5_evidence.json
?? reports/profit-growth/P6_evidence.json
?? reports/profit-growth/S7_evidence.json
?? research-go/backtest.test
?? research-go/cmd/research-candidates/main.go
?? research-go/internal/auditdata/loader.go
?? research-go/internal/auditdata/loader_test.go
?? research-go/internal/backtest/signal_runner.go
?? research-go/internal/backtest/signal_runner_test.go
?? research-go/internal/candidates/btc_eth_lead_lag.go
?? research-go/internal/candidates/candidates_test.go
?? research-go/internal/candidates/funding_open_interest_squeeze.go
?? research-go/internal/candidates/liquidity_sweep_reversal.go
?? research-go/internal/candidates/lookahead.go
?? research-go/internal/candidates/lookahead_test.go
?? research-go/internal/candidates/multi_timeframe_trend_following.go
?? research-go/internal/candidates/pipeline.go
?? research-go/internal/candidates/pipeline_test.go
?? research-go/internal/candidates/registry.go
?? research-go/internal/candidates/types.go
?? research-go/internal/candidates/volatility_compression_breakout.go
?? run-vector-opencode-overnight.sh
?? run-vector-v11-completion.sh
?? scripts/a0_a3_archive_evaluation.php
?? scripts/p4_archive_evaluation.php
?? scripts/p5_archive_evaluation.php
?? scripts/p6_archive_evaluation.php
?? tests/Feature/QuoteIntegrity/QimApiAuthTest.php
?? tests/Feature/QuoteIntegrity/QimApiStatusTest.php
?? tests/Feature/QuoteIntegrity/QimEvidenceImportTest.php
?? tests/Feature/QuoteIntegrity/QimFeatureFlagOffTest.php
?? tests/Feature/Research/AutonomyResearchTest.php
?? tests/Feature/Research/P2RiskCapitalMetricsTest.php
?? tests/Feature/Research/P4CostMfeMaeApiTest.php
?? tests/Feature/Research/P4CostMfeMaeTest.php
?? tests/Feature/Research/P5ConcentrationApiTest.php
?? tests/Feature/Research/P5DependenceConcentrationTest.php
?? tests/Feature/Research/P6DecisionGateApiTest.php
?? tests/Feature/Research/P6DecisionGateTest.php
?? tests/Feature/Research/ProfitabilityResearchApiTest.php
?? tests/Feature/Research/SlippageAttributionApiTest.php
?? tests/Integration/QuoteIntegrity/QimImmutabilityTest.php
?? tests/Integration/SlippageAttributionPostgresIntegrationTest.php
?? tests/Unit/Middleware/RequireResearchTokenTest.php
?? tests/Unit/QuoteIntegrity/QimComparisonEngineTest.php
?? tests/Unit/QuoteIntegrity/QimGapRecorderTest.php
?? tests/Unit/QuoteIntegrity/QimIncidentLifecycleTest.php
?? tests/Unit/QuoteIntegrity/QimMeasurementGuardTest.php
?? tests/Unit/QuoteIntegrity/QimNonBlockingHooksTest.php
?? tests/Unit/QuoteIntegrity/QimObservationServiceTest.php
?? tests/Unit/Research/Autonomy/AutonomyControlTest.php
?? tests/Unit/Research/Autonomy/AutonomyDomainTest.php
?? tests/Unit/Research/Autonomy/AutonomyPromotionTest.php
?? tests/Unit/Research/Concentration/ConcentrationAnalyzerTest.php
?? tests/Unit/Research/Concentration/EquityAlignmentTest.php
?? tests/Unit/Research/Concentration/OverlapClusterBuilderTest.php
?? tests/Unit/Research/CostMfe/CostAttributionTest.php
?? tests/Unit/Research/CostMfe/MfeMaeCalculatorTest.php
?? tests/Unit/Research/CostMfe/PreEntryMfeEstimatorTest.php
?? tests/Unit/Research/Decision/DecisionGateTest.php
?? tests/Unit/Research/Decision/HypothesisPriorityTest.php
?? tests/Unit/Research/MetricRegistryTest.php
?? tests/Unit/Research/RiskCapitalMetricsServiceTest.php
?? tests/Unit/Research/SlippageAttributionServiceTest.php

## Unstaged diff summary
 .../Application/BacktestSimulationEngine.php       |   8 +-
 app/Http/Middleware/RequireResearchToken.php       |  34 ++++
 app/MarketData/Application/CandleSynchronizer.php  |  54 +++++-
 .../Application/ClosedCandleIngestor.php           |  21 +++
 .../Application/MarketStreamSupervisor.php         |  82 ++++++++-
 .../Infrastructure/Http/LaravelHttpTransport.php   |   1 +
 .../Infrastructure/Http/TransportResponse.php      |   1 +
 app/Providers/AppServiceProvider.php               |  38 ++++
 bootstrap/app.php                                  |  14 ++
 config/paper.php                                   |  13 ++
 config/research.php                                |  30 ++++
 config/services.php                                |   2 +
 docker/scripts/common.sh                           |   3 +-
 frontend/src/layouts/MainLayout.vue                |   2 +-
 frontend/src/router/index.ts                       |   2 +-
 research-go/cmd/research-worker/main.go            |  24 +--
 research-go/internal/algorithms/indicators.go      |  12 +-
 research-go/internal/algorithms/indicators_test.go |  14 ++
 research-go/internal/backtest/engine.go            | 191 ++++++++++++---------
 research-go/internal/backtest/engine_test.go       |  51 ++++--
 research-go/internal/contracts/job.go              |   6 +-
 research-go/internal/datasets/candle.go            |  24 +--
 research-go/internal/decimal/decimal.go            |  41 +++++
 research-go/internal/decimal/decimal_test.go       |  25 +++
 research-go/internal/jobrunner/runner.go           |  44 ++---
 research-go/internal/jobrunner/runner_test.go      |   8 +-
 research-go/internal/patterns/miner_test.go        |  22 +--
 research-go/internal/simulation/montecarlo.go      |  34 ++--
 research-go/internal/walkforward/wfo.go            |  20 +--
 research-go/internal/walkforward/wfo_test.go       |  22 +--
 routes/api.php                                     |  39 +++++
 tests/Feature/Backtest/BacktestSimulationTest.php  | 163 ++++++++++++++++++
 ...LedgerReconciliationPostgresIntegrationTest.php |  11 +-
 33 files changed, 842 insertions(+), 214 deletions(-)

## Staged diff summary
 docs/QUOTE_INTEGRITY_SPEC_FINAL.md | 391 +++++++++++++++++++++++++++++++++++++
 1 file changed, 391 insertions(+)

## Safety
- This launcher is not an OS sandbox.
- No production access, deploy, live orders, production migrations, SSH/VPS operations, P-3 replay, or ECONOM/econom-group access is authorized.
- Only a positively verified isolated test database may be used for test migrations.
- The newer VECTOR v2.3 specification is out of scope.

- Snapshot: /home/thunder/work/traderbybit/reports/overnight/runs/vector-20261021T143106Z-373450/worktree-snapshot-vector-20261021T143106Z-373450.tar.gz
- Snapshot SHA-256: b3942f707998a816b4e5c66c5f49320e22a5afa8bc31cf1540b405fb422a9a60
- Snapshot bytes: 22367477
