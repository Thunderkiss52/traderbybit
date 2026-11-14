package signalintel

import (
	"context"
	"fmt"
	"testing"
)

// These measure bounded synthetic computation only, excluding database, queue,
// network and deployment latency.
func BenchmarkEvaluateSixtyCandlesSixHorizons(b *testing.B) {
	input := evaluationFixture()
	ctx := context.Background()
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		result, err := Evaluate(ctx, input)
		if err != nil || len(result.Outcomes) != 6 || result.Outcomes[5].Status != "MATURED" {
			b.Fatal("benchmark must evaluate all six mature horizons", result, err)
		}
	}
}

func BenchmarkClassify4096Observations(b *testing.B) {
	input := cohortFixture("0.001")
	input.Policy = DefaultQualitySettings()
	input.Observations = make([]Observation, MaxObservations)
	start := int64(1704067200000)
	for i := range input.Observations {
		entry := start + int64(i)*1440*MinuteMS
		input.Observations[i] = Observation{ID: fmt.Sprintf("benchmark-%d", i), InputHash: fmt.Sprintf("%064x", i+1),
			Scope: input.Scope, Status: "MATURED", AvailableMS: entry, EndMS: entry + MinuteMS, NetReturn: "0.001"}
	}
	input.AsOfMS = input.Observations[MaxObservations-1].EndMS
	datasetHash, err := CohortDatasetHash(input.Observations)
	if err != nil {
		b.Fatal(err)
	}
	input.Provenance = &Provenance{DatasetID: "benchmark-cohort", DatasetHash: datasetHash,
		ConfigurationHash: input.Scope.ConfigurationHash, CodeRevision: input.Scope.CodeRevision}
	ctx := context.Background()
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		result, err := Classify(ctx, input)
		if err != nil || result.IndependentSamples != MaxObservations || result.DescriptiveMetrics.ObservedCount != MaxObservations || result.Classification == "INVALID_DATA" {
			b.Fatal("benchmark must classify all mature observations", result, err)
		}
	}
}
