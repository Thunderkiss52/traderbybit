# Gate 5 — Quantitative Intelligence, Research Timeframes, and Meta-Labeling

## 1. Adaptive Multi-Timeframe Research (1m – 1d)
- Implemented in `App\MarketData\Domain\Interval` and `AdaptiveTimeframeResearchService`.
- Supports 7 canonical research intervals: `1`, `5`, `15`, `30`, `60`, `240`, `D`.
- Strictly enforces closed-candle inputs and point-in-time timestamp causality.
- Verified in `tests/Unit/MarketData/IntervalTest.php` and `tests/Feature/Research/AdaptiveTimeframeResearchTest.php`.

## 2. Signal Quality Policy (Grades A, B, C, D, E & UNRATED)
- Implemented in `SignalQualityPolicyService`.
- Evaluates signal score and net expectancy:
  - **Class A**: Score >= 80, positive expectancy, high confidence.
  - **Class B**: Score >= 60, positive expectancy.
  - **Class C**: Score >= 40, neutral-to-modest expectancy.
  - **Class D**: Sub-par expectancy or elevated uncertainty.
  - **Class E**: Negative expectancy / high tail risk.
  - **UNRATED**: Signals lacking required statistical warm-up or missing indicators.
- Trading rejection (SKIP) remains completely separated from signal quality classification.
- Verified in `tests/Unit/Strategy/SignalQualityPolicyTest.php`.

## 3. Meta-Labeling Paired Research
- Implemented in `MetaLabelingResearchService` and `ModelAdvisoryService`.
- Evaluates paired policy comparisons across 4 regimes: Base, Base + Advisor, Base + Quality Grade, Combined.
- Metrics computed: Filter rate of losing trades, net R improvement, Brier score reduction, and confirmation of trading direction invariance (never inverts a BUY into a SELL).
- Verified in `tests/Feature/Research/MetaLabelingResearchTest.php` and `tests/Feature/Research/ModelAdvisoryDecisionVetoTest.php`.

## 4. Counterfactual SKIP Analysis
- Implemented in `CounterfactualAnalyzerService`.
- Analyzes rejected signals against subsequent market price evolution (TP before SL vs SL before TP) to identify missed opportunities versus successfully avoided losses without altering production ledgers.
- Verified in `tests/Feature/Research/CounterfactualAnalysisTest.php`.

## 5. Exit Horizon & Pattern Research
- Implemented in `ExitHorizonResearchService` and `PatternResearchController`.
- Evaluates holding period horizons across 15m, 30m, 60m, 120m, 240m.
- Negative and inconclusive research results are preserved in telemetry records to prevent look-ahead bias and p-hacking.
- Verified in `tests/Feature/Research/ExitHorizonResearchTest.php` and `tests/Feature/Strategy/PatternResearchApiTest.php`.
