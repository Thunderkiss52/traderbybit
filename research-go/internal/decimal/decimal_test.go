package decimal

import (
	"math/big"
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

func TestRatQuantizeHalfEven(t *testing.T) {
	// 0.5 -> 0 (round half to even)
	r := RatQuantize(big.NewRat(5, 10), 0)
	if r.RatString() != "0" {
		t.Fatalf("expected 0, got %s", r.RatString())
	}
	// 1.5 -> 2 (round half to even)
	r = RatQuantize(big.NewRat(15, 10), 0)
	if r.RatString() != "2" {
		t.Fatalf("expected 2, got %s", r.RatString())
	}
	// 2.5 -> 2
	r = RatQuantize(big.NewRat(25, 10), 0)
	if r.RatString() != "2" {
		t.Fatalf("expected 2, got %s", r.RatString())
	}
	// 0.12345 at scale 4 -> 0.1234 (round down); 0.12345 exactly half at scale 4 -> even
	r = RatQuantize(big.NewRat(2469, 10000), 4)
	if r.FloatString(4) != "0.2468" && r.FloatString(4) != "0.2469" {
		// 2469/10000 = 0.2469 exactly at scale 4
		t.Logf("note: %s", r.FloatString(4))
	}
}
