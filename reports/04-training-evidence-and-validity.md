# Gate 4 — Training Evidence, Point-in-Time Features, and Model Lifecycle

## 1. Feature Store & Point-in-Time Causality
- Implemented in `LearningFeatureStoreService`.
- Every feature vector is pinned to `decision_time` and verifies `available_at <= decision_time` (`no_look_ahead_verified`).
- Snapshot features include `hl_spread_pct`, `body_spread_pct`, `recent_volatility`, `rsi_14`, `volume_ratio`.
- Labels mature strictly upon subsequent price action (`TP`, `SL`, `AMBIGUOUS`, or `TIMEOUT`).

## 2. Actual Machine Learning Training & Artifact Persistence
- Implemented in `LearningPredictorService::trainModel` using batch gradient descent with L2 regularization.
- Weights and feature normalizations (means, standard deviations) are computed exclusively on the training slice.
- Evaluated on an untouched chronological out-of-sample validation split.
- Brier score, log loss, ECE (expected calibration error), and accuracy are computed and persisted in `LearningModelArtifactRecord`.
- Verified in `tests/Feature/Research/AutonomousLearningPipelineTest.php` and `tests/Unit/Research/LearningPredictorServiceTest.php`.
