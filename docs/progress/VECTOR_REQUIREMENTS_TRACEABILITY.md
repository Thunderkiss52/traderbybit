# VECTOR Requirements Traceability

Scope `VECTOR-SCOPE-20261027-v1`; source `d9a0aeb5d14233fc95e9e5cc55d8748814362f59`.

F/I entries are assurance obligations, not duplicate runtime delivery credit. JSON contains atomic criteria and evidence records.

| Requirement | Phase / capability | Criterion IDs | Source | Tests | Dependencies |
|---|---|---|---|---|---|
| VEC-P0-001 | 0 / CAP-P0 | VEC-P0-001-I1, VEC-P0-001-I2 | config/paper.php, app/PaperTrading/Application/PaperAccountService.php, app/Exchanges/Binance/BinanceDeploymentInitializer.php | tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php, tests/Feature/Exchanges/BinancePaperIsolationTest.php, tests/Feature/PaperTrading/AllThirteenStrategiesExecutionTest.php, tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php | None |
| VEC-P0-002 | 0 / CAP-P0 | VEC-P0-002-I1, VEC-P0-002-I2 | app/Trading/Application/TradingPipelineEventService.php | tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php, tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php, tests/Feature/Trading/TradingPipelineEventContractTest.php, tests/Feature/Trading/TradingPipelineRecoveryAndRollbackTest.php, tests/Feature/Trading/TradingPipelineStageATest.php | None |
| VEC-P0-003 | 0 / CAP-P0 | VEC-P0-003-I1, VEC-P0-003-I2 | app/PaperTrading/Application/PositionLifecycleService.php | tests/Feature/PaperTrading/FundingScheduleSafetyTest.php, tests/Feature/PaperTrading/PositionLifecycleStageBTest.php, tests/Integration/FinancialAccountingAndReconciliationPostgresIntegrationTest.php, tests/Integration/LedgerReconciliationPostgresIntegrationTest.php | None |
| VEC-P0-004 | 0 / CAP-P0 | VEC-P0-004-I1, VEC-P0-004-I2 | app/PaperTrading/Application/RiskEngine.php | tests/Feature/PaperTrading/AccountSafetyRegressionTest.php, tests/Feature/PaperTrading/CentralExecutionEligibilityTest.php, tests/Feature/PaperTrading/PaperLifecycleTest.php, tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php, tests/Unit/PaperTrading/RiskAndSizingTest.php | None |
| VEC-P0-005 | 0 / CAP-P0 | VEC-P0-005-I1 | app/Exchanges/Binance/BinancePaperSafety.php | tests/Feature/Exchanges/BinanceDeploymentInitializationTest.php, tests/Feature/Exchanges/BinancePaperIsolationTest.php, tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php, tests/Unit/Exchanges/BinancePaperSafetyTest.php | None |
| VEC-P0-006 | 0 / CAP-P0 | VEC-P0-006-I1, VEC-P0-006-I2 | app/Strategy/Application/LevelProbabilityService.php | tests/Feature/Strategy/LevelProbabilityBootstrapLifecycleTest.php | None |
| VEC-P0-007 | 0 / CAP-P0 | VEC-P0-007-I1 | .github/workflows/deploy.yml | tests/Integration/BinanceCandleInsertionRaceTest.php, tests/Integration/CounterfactualSlotIdempotencyPostgresIntegrationTest.php, tests/Integration/GoResearchWorkerIntegrationTest.php, tests/Integration/LedgerReconciliationPostgresIntegrationTest.php, tests/Integration/SlippageAttributionPostgresIntegrationTest.php, tests/Integration/StrategyObservationSlotMigrationPostgresIntegrationTest.php | None |
| VEC-P0-008 | 0 / CAP-P0 | VEC-P0-008-I1, VEC-P0-008-I2 | Planned | Not mapped | VEC-P0-001, VEC-P0-002, VEC-P0-003, VEC-P0-004, VEC-P0-005, VEC-P0-006, VEC-P0-007, VEC-P0-009, VEC-P0-101, VEC-P0-102, VEC-P0-103, VEC-P0-104, VEC-P0-105, VEC-P0-106, VEC-P0-107, VEC-P0-108, VEC-P0-109, VEC-P0-110, VEC-P0-111, VEC-P0-112, VEC-P0-113, VEC-P0-114, VEC-P0-115, VEC-P0-116, VEC-P0-117, VEC-P0-118, VEC-P0-119, VEC-P0-120, VEC-P0-121, VEC-P0-122, VEC-P0-123, VEC-P0-124, VEC-P0-125, VEC-P0-126, VEC-P0-127, VEC-P0-201, VEC-P0-202, VEC-P0-203, VEC-P0-204, VEC-P0-205, VEC-P0-206, VEC-P0-207, VEC-P0-208, VEC-P0-209 |
| VEC-P0-009 | 0 / CAP-P0 | VEC-P0-009-I1, VEC-P0-009-I2 | compose.yaml | tests/Integration/BinanceCandleInsertionRaceTest.php | None |
| VEC-P0-101 | 0 / CAP-P0 | VEC-P0-101-I1 | app/Research/Application/DecisionReplayService.php | tests/Feature/Api/ReadOnlyGetEndpointsTest.php, tests/Feature/Research/ProfitabilityResearchApiTest.php, tests/Feature/Research/StageTwoApiTest.php, tests/Unit/Research/DecisionReplayServiceTest.php | None |
| VEC-P0-102 | 0 / CAP-P0 | VEC-P0-102-I1 | app/Http/Controllers/Api/V1/ProfitabilityResearchController.php | frontend/tests/profitability_research.test.js, tests/Feature/Research/ProfitabilityResearchApiTest.php | None |
| VEC-P0-103 | 0 / CAP-P0 | VEC-P0-103-I1 | frontend/src/pages/ProfitabilityResearchPage.vue | frontend/tests/profitability_research.test.js, tests/Feature/MarketData/MarketExchangeIsolationTest.php, tests/Feature/Research/ProfitabilityResearchApiTest.php | None |
| VEC-P0-104 | 0 / CAP-P0 | VEC-P0-104-I1 | app/Http/Controllers/Api/V1/ProfitabilityResearchController.php | tests/Feature/Research/ProfitabilityResearchApiTest.php, tests/Unit/Research/LedgerReconciliationServiceTest.php | None |
| VEC-P0-105 | 0 / CAP-P0 | VEC-P0-105-I1 | app/Http/Controllers/Api/V1/ProfitabilityResearchController.php | tests/Feature/Research/ProfitabilityResearchApiTest.php | None |
| VEC-P0-106 | 0 / CAP-P0 | VEC-P0-106-I1 | app/Research/Application/LedgerReconciliationService.php | tests/Unit/Research/LedgerReconciliationServiceTest.php | None |
| VEC-P0-107 | 0 / CAP-P0 | VEC-P0-107-I1 | app/Audit/Application/AuditExportService.php | tests/Feature/AuditExportTest.php | None |
| VEC-P0-108 | 0 / CAP-P0 | VEC-P0-108-I1 | app/Audit/Application/AuditExportService.php | tests/Feature/AuditExportTest.php, tests/Integration/SlotIntegrityAndRiskFunnelPostgresIntegrationTest.php | None |
| VEC-P0-109 | 0 / CAP-P0 | VEC-P0-109-I1 | app/Http/Controllers/Api/V1/ResearchController.php, frontend/src/pages/DashboardPage.vue | tests/Feature/Api/CompleteApiEndpointAuditTest.php, tests/Feature/Api/MultiAccountSelectionTest.php, tests/Feature/PaperTrading/StrategyPipelineFunnelVerificationTest.php | None |
| VEC-P0-110 | 0 / CAP-P0 | VEC-P0-110-I1 | app/Http/Controllers/Api/V1/ResearchController.php | tests/Feature/Api/CompleteApiEndpointAuditTest.php | None |
| VEC-P0-111 | 0 / CAP-P0 | VEC-P0-111-I1 | app/PaperTrading/Application/PerformanceStatisticsService.php | tests/Feature/PaperTrading/PaperLifecycleTest.php, tests/Feature/PaperTrading/PerformanceStatisticsTest.php, tests/Feature/PaperTrading/PositionLifecycleStageBTest.php, tests/Feature/Research/P2RiskCapitalMetricsTest.php | None |
| VEC-P0-112 | 0 / CAP-P0 | VEC-P0-112-I1 | app/PaperTrading/Application/DailyPerformanceService.php | tests/Feature/PaperTrading/DailyPerformanceTest.php | None |
| VEC-P0-113 | 0 / CAP-P0 | VEC-P0-113-I1 | app/Research/Application/StrategyHealthService.php | tests/Unit/Research/StrategyHealthServiceTest.php | None |
| VEC-P0-114 | 0 / CAP-P0 | VEC-P0-114-I1 | app/Http/Controllers/Api/V1/AutonomousLearningController.php | tests/Feature/Research/AutonomousLearningApiTest.php, tests/Unit/Research/SystemFreshnessServiceTest.php | None |
| VEC-P0-115 | 0 / CAP-P0 | VEC-P0-115-I1 | app/Research/Application/PortfolioCorrelationService.php | tests/Unit/Research/PortfolioCorrelationServiceTest.php | None |
| VEC-P0-116 | 0 / CAP-P0 | VEC-P0-116-I1 | app/Research/Application/StrategyDegradationService.php | tests/Feature/Research/P5DependenceConcentrationTest.php, tests/Unit/Research/StrategyDegradationServiceTest.php | None |
| VEC-P0-117 | 0 / CAP-P0 | VEC-P0-117-I1 | app/Research/Application/LearningFeatureStoreService.php | tests/Feature/Research/AutonomousLearningPipelineTest.php, tests/Unit/Research/LearningFeatureStoreServiceTest.php | None |
| VEC-P0-118 | 0 / CAP-P0 | VEC-P0-118-I1 | Planned | tests/Feature/Research/AutonomousLearningPipelineTest.php | None |
| VEC-P0-119 | 0 / CAP-P0 | VEC-P0-119-I1 | app/Research/Application/AutonomyPermissionService.php | tests/Feature/Research/AutonomousLearningApiTest.php, tests/Feature/Research/AutonomyResearchTest.php, tests/Unit/Research/AutonomyPermissionServiceTest.php | None |
| VEC-P0-120 | 0 / CAP-P0 | VEC-P0-120-I1 | app/Research/Application/AutonomyPermissionService.php | tests/Feature/Research/AutonomyResearchTest.php, tests/Unit/Research/AutonomyPermissionServiceTest.php | None |
| VEC-P0-121 | 0 / CAP-P0 | VEC-P0-121-I1 | app/Research/Application/ResearchJobService.php | tests/Feature/Research/ResearchJobApiTest.php, tests/Integration/GoResearchWorkerIntegrationTest.php, tests/Unit/Research/ResearchJobServiceTest.php | None |
| VEC-P0-122 | 0 / CAP-P0 | VEC-P0-122-I1 | frontend/src/services/quoteIntegrityApi.ts | frontend/tests/quote_integrity.test.js | None |
| VEC-P0-123 | 0 / CAP-P0 | VEC-P0-123-I1 | frontend/src/pages/JournalPage.vue | tests/Feature/PaperTrading/DailyPerformanceTest.php | None |
| VEC-P0-124 | 0 / CAP-P0 | VEC-P0-124-I1 | app/Http/Controllers/Api/V1/ProfitabilityResearchController.php | tests/Feature/Research/SlippageAttributionApiTest.php | None |
| VEC-P0-125 | 0 / CAP-P0 | VEC-P0-125-I1 | app/Research/Application/PromotionPolicyService.php, frontend/src/pages/ResearchLearningPage.vue, app/Research/Jobs/EvaluateStrategyCandidateJob.php | tests/Feature/Research/AutonomousLearningPipelineTest.php, tests/Feature/Research/CandidateLabValidationLifecycleTest.php, tests/Feature/Research/Gate6PromotionPolicyTest.php, tests/Unit/Research/PromotionPolicyServiceTest.php | None |
| VEC-P0-126 | 0 / CAP-P0 | VEC-P0-126-I1 | app/Research/Application/PromotionPolicyService.php | tests/Feature/Research/Gate6PromotionPolicyTest.php, tests/Unit/Research/PromotionPolicyServiceTest.php | None |
| VEC-P0-127 | 0 / CAP-P0 | VEC-P0-127-I1 | app/PaperTrading/Application/PaperProcessingService.php | tests/Feature/PaperTrading/CentralExecutionEligibilityTest.php, tests/Feature/PaperTrading/PaperLifecycleTest.php, tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php | None |
| VEC-P0-201 | 0 / CAP-P0 | VEC-P0-201-I1 | app/Trading/Application/TradingPipelineEventService.php, app/Trading/Application/TradingPipelineRecoveryService.php | tests/Feature/Trading/TradingPipelineStageATest.php | None |
| VEC-P0-202 | 0 / CAP-P0 | VEC-P0-202-I1 | app/PaperTrading/Application/PositionLifecycleService.php | tests/Feature/PaperTrading/PositionLifecycleStageBTest.php | None |
| VEC-P0-203 | 0 / CAP-P0 | VEC-P0-203-I1 | app/PaperTrading/Application/PaperProcessingService.php, app/PaperTrading/Application/PositionLifecycleService.php | tests/Feature/PaperTrading/FundingScheduleSafetyTest.php, tests/Feature/PaperTrading/PositionLifecycleStageBTest.php | None |
| VEC-P0-204 | 0 / CAP-P0 | VEC-P0-204-I1 | app/Trading/Application/TradingPipelineEventService.php, app/Trading/Jobs/ProcessPaperPipelineJob.php | tests/Feature/Trading/TradingPipelineStageATest.php | None |
| VEC-P0-205 | 0 / CAP-P0 | VEC-P0-205-I1 | app/MarketData/Jobs/RecoverCandleGapsJob.php, app/PaperTrading/Application/PaperProcessingService.php | tests/Feature/Exchanges/BinanceImmutableMarketIngestionTest.php, tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php | None |
| VEC-P0-206 | 0 / CAP-P0 | VEC-P0-206-I1 | app/Trading/Jobs/ProcessPaperPipelineJob.php | tests/Feature/Trading/TradingPipelineStageATest.php | None |
| VEC-P0-207 | 0 / CAP-P0 | VEC-P0-207-I1 | app/Exchanges/Binance/BinanceFundingIngestion.php, app/MarketData/Application/FundingHistorySynchronizer.php, app/PaperTrading/Application/PositionLifecycleService.php | tests/Feature/MarketData/FundingHistorySynchronizerTest.php, tests/Feature/PaperTrading/PositionLifecycleStageBTest.php | None |
| VEC-P0-208 | 0 / CAP-P0 | VEC-P0-208-I1 | app/PaperTrading/Application/DailyPerformanceService.php, app/PaperTrading/Application/PaperAccountService.php, app/PaperTrading/Jobs/CapturePaperEquityJob.php, database/migrations/2026_10_27_120000_add_valuation_provenance_to_equity_snapshots.php | tests/Feature/PaperTrading/ExchangeIngestionAndValuationStageCTest.php | None |
| VEC-P0-209 | 0 / CAP-P0 | VEC-P0-209-I1 | app/Trading/Application/TradingPipelineEventService.php, app/Trading/Jobs/ProcessClosedCandleEventJob.php | tests/Feature/Trading/TradingPipelineStageATest.php | None |
| VEC-P1-001 | 1 / CAP-P1 | VEC-P1-001-I1, VEC-P1-001-I2 | app/Strategy/Contracts/StrategyInterface.php | Not mapped | VEC-P0-008 |
| VEC-P1-002 | 1 / CAP-P1 | VEC-P1-002-I1, VEC-P1-002-I2 | Planned | Not mapped | VEC-P0-008 |
| VEC-P1-003 | 1 / CAP-P1 | VEC-P1-003-I1, VEC-P1-003-I2 | app/Strategy/Application/StrategyRegistry.php | Not mapped | VEC-P0-008 |
| VEC-P1-004 | 1 / CAP-P1 | VEC-P1-004-I1, VEC-P1-004-I2 | Planned | Not mapped | VEC-P0-008 |
| VEC-P1-005 | 1 / CAP-P1 | VEC-P1-005-I1, VEC-P1-005-I2 | app/Strategy/Application/StrategyRegistry.php | Not mapped | VEC-P0-008 |
| VEC-P1-006 | 1 / CAP-P1 | VEC-P1-006-I1, VEC-P1-006-I2 | Planned | Not mapped | VEC-P0-008 |
| VEC-P1-007 | 1 / CAP-P1 | VEC-P1-007-I1, VEC-P1-007-I2 | Planned | Not mapped | VEC-P0-008 |
| VEC-P1-008 | 1 / CAP-P1 | VEC-P1-008-I1, VEC-P1-008-I2 | Planned | Not mapped | VEC-P0-008 |
| VEC-P2-001 | 2 / CAP-P2 | VEC-P2-001-I1, VEC-P2-001-I2 | app/Exchanges/ExchangeComparisonService.php | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P2-002 | 2 / CAP-P2 | VEC-P2-002-I1, VEC-P2-002-I2 | app/Strategy/Application/StrategyComparisonService.php | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P2-003 | 2 / CAP-P2 | VEC-P2-003-I1, VEC-P2-003-I2 | app/PaperTrading/Application/PerformanceStatisticsService.php | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P2-004 | 2 / CAP-P2 | VEC-P2-004-I1, VEC-P2-004-I2 | app/Research/Domain/MetricRegistry.php | tests/Unit/Research/MetricRegistryTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P2-005 | 2 / CAP-P2 | VEC-P2-005-I1, VEC-P2-005-I2 | app/Research/Domain/MetricRegistry.php | tests/Unit/Research/MetricRegistryTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P2-006 | 2 / CAP-P2 | VEC-P2-006-I1, VEC-P2-006-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P2-007 | 2 / CAP-P2 | VEC-P2-007-I1, VEC-P2-007-I2 | app/Research/Application/DecisionReplayService.php | tests/Unit/Research/DecisionReplayServiceTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P2-008 | 2 / CAP-P2 | VEC-P2-008-I1, VEC-P2-008-I2 | app/Audit/Application/AuditExportService.php | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P2-009 | 2 / CAP-P2 | VEC-P2-009-I1, VEC-P2-009-I2 | app/PaperTrading/Application/DailyPerformanceService.php | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P2-010 | 2 / CAP-P2 | VEC-P2-010-I1, VEC-P2-010-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P3-001 | 3 / CAP-P3 | VEC-P3-001-I1, VEC-P3-001-I2 | app/Research/Application/StrategyEvolutionService.php | tests/Unit/Research/StrategyEvolutionServiceTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P3-002 | 3 / CAP-P3 | VEC-P3-002-I1, VEC-P3-002-I2 | app/Backtest/Application/GridOptimizer.php | tests/Unit/Backtest/GridOptimizerTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P3-003 | 3 / CAP-P3 | VEC-P3-003-I1, VEC-P3-003-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P3-004 | 3 / CAP-P3 | VEC-P3-004-I1, VEC-P3-004-I2 | app/Research/Application/ResearchJobService.php | tests/Unit/Research/ResearchJobServiceTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P3-005 | 3 / CAP-P3 | VEC-P3-005-I1, VEC-P3-005-I2 | app/Research/Application/ResearchJobService.php | tests/Unit/Research/ResearchJobServiceTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P3-006 | 3 / CAP-P3 | VEC-P3-006-I1, VEC-P3-006-I2 | app/Research/Application/StrategyEvolutionService.php | tests/Unit/Research/StrategyEvolutionServiceTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P3-007 | 3 / CAP-P3 | VEC-P3-007-I1, VEC-P3-007-I2 | app/Research/Jobs/EvaluateStrategyCandidateJob.php | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P3-008 | 3 / CAP-P3 | VEC-P3-008-I1, VEC-P3-008-I2 | app/Research/Application/ResearchJobService.php | tests/Unit/Research/ResearchJobServiceTest.php | VEC-P0-008, VEC-P1-004 |
| VEC-P3-009 | 3 / CAP-P3 | VEC-P3-009-I1, VEC-P3-009-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P3-010 | 3 / CAP-P3 | VEC-P3-010-I1, VEC-P3-010-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P3-011 | 3 / CAP-P3 | VEC-P3-011-I1, VEC-P3-011-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004 |
| VEC-P4-001 | 4 / CAP-P4 | VEC-P4-001-I1, VEC-P4-001-I2 | app/Backtest/Application/BacktestRunService.php | tests/Feature/Backtest/BacktestRunServiceTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-002 | 4 / CAP-P4 | VEC-P4-002-I1, VEC-P4-002-I2 | app/Backtest/Application/ChronologicalSplitter.php | tests/Unit/Backtest/ChronologicalSplitterTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-003 | 4 / CAP-P4 | VEC-P4-003-I1, VEC-P4-003-I2 | research-go/internal/walkforward/wfo.go | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-004 | 4 / CAP-P4 | VEC-P4-004-I1, VEC-P4-004-I2 | research-go/internal/simulation/montecarlo.go | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-005 | 4 / CAP-P4 | VEC-P4-005-I1, VEC-P4-005-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-006 | 4 / CAP-P4 | VEC-P4-006-I1, VEC-P4-006-I2 | app/Research/Application/PromotionPolicyService.php | tests/Unit/Research/PromotionPolicyServiceTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-007 | 4 / CAP-P4 | VEC-P4-007-I1, VEC-P4-007-I2 | app/Research/Application/PromotionPolicyService.php | tests/Unit/Research/PromotionPolicyServiceTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-008 | 4 / CAP-P4 | VEC-P4-008-I1, VEC-P4-008-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-009 | 4 / CAP-P4 | VEC-P4-009-I1, VEC-P4-009-I2 | app/Research/Application/PromotionPolicyService.php | tests/Unit/Research/PromotionPolicyServiceTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P4-010 | 4 / CAP-P4 | VEC-P4-010-I1, VEC-P4-010-I2 | app/Research/Application/AutonomyPermissionService.php | tests/Unit/Research/AutonomyPermissionServiceTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-001 | 5 / CAP-P5 | VEC-P5-001-I1, VEC-P5-001-I2 | app/Exchanges/Contracts/ExchangeAdapter.php | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-002 | 5 / CAP-P5 | VEC-P5-002-I1, VEC-P5-002-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-003 | 5 / CAP-P5 | VEC-P5-003-I1, VEC-P5-003-I2 | app/Exchanges/Binance/BinancePublicAdapter.php | tests/Unit/Exchanges/BinancePublicAdapterTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-004 | 5 / CAP-P5 | VEC-P5-004-I1, VEC-P5-004-I2 | app/Exchanges/Binance/BinancePublicAdapter.php | tests/Unit/Exchanges/BinancePublicAdapterTest.php | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-005 | 5 / CAP-P5 | VEC-P5-005-I1, VEC-P5-005-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-006 | 5 / CAP-P5 | VEC-P5-006-I1, VEC-P5-006-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-007 | 5 / CAP-P5 | VEC-P5-007-I1, VEC-P5-007-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P5-008 | 5 / CAP-P5 | VEC-P5-008-I1, VEC-P5-008-I2 | Planned | Not mapped | VEC-P0-008, VEC-P1-004, VEC-P3-003 |
| VEC-P6-001 | 6 / CAP-P6 | VEC-P6-001-I1 | docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md | Not mapped | VEC-P0-008 |
| VEC-P6-002 | 6 / CAP-P6 | VEC-P6-002-I1 | docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md | Not mapped | VEC-P0-008 |
| VEC-P6-003 | 6 / CAP-P6 | VEC-P6-003-I1 | docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md | Not mapped | VEC-P0-008 |
| VEC-P6-004 | 6 / CAP-P6 | VEC-P6-004-I1 | docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md | Not mapped | VEC-P0-008 |
| VEC-P6-005 | 6 / CAP-P6 | VEC-P6-005-I1 | docs/specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md | Not mapped | VEC-P0-008 |
