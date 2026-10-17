package decimal

import (
	"testing"
)

func TestDecimalArithmetic(t *testing.T) {
	d1 := MustFromString("100.50000000")
	d2 := MustFromString("50.25000000")

	// Add
	sum := d1.Add(d2)
	if sum.String() != "150.75000000" {
		t.Fatalf("expected 150.75000000, got %s", sum.String())
	}

	// Sub
	diff := d1.Sub(d2)
	if diff.String() != "50.25000000" {
		t.Fatalf("expected 50.25000000, got %s", diff.String())
	}

	// Mul
	prod := d1.Mul(MustFromString("2.00000000"))
	if prod.String() != "201.00000000" {
		t.Fatalf("expected 201.00000000, got %s", prod.String())
	}

	// Div
	quot, err := d1.Div(MustFromString("2.00000000"))
	if err != nil || quot.String() != "50.25000000" {
		t.Fatalf("expected 50.25000000, got %s (err: %v)", quot.String(), err)
	}

	// Zero div
	_, err = d1.Div(Zero())
	if err == nil {
		t.Fatalf("expected division by zero error")
	}
}

func TestDecimalComparison(t *testing.T) {
	d1 := MustFromString("10.00000000")
	d2 := MustFromString("20.00000000")

	if d1.Cmp(d2) >= 0 {
		t.Fatalf("expected d1 < d2")
	}
	if d2.Cmp(d1) <= 0 {
		t.Fatalf("expected d2 > d1")
	}
	if d1.Cmp(MustFromString("10.00000000")) != 0 {
		t.Fatalf("expected d1 == 10")
	}
}
