package decimal

import (
	"math/big"
	"testing"
)

// TestScaledIntAndFromScaledIntRoundTrip proves that the scaled integer accessors keep the exact
// fixed point value of a decimal and that they can rebuild it without loss.
func TestScaledIntAndFromScaledIntRoundTrip(t *testing.T) {
	cases := []string{"0", "1", "-1", "81479.40000000", "-0.00000001", "123456789.87654321"}

	for _, value := range cases {
		dec := MustFromString(value)
		scaled := dec.ScaledInt()

		expected := new(big.Int)
		parts := splitDecimal(value)
		expected.SetString(parts, 10)

		if scaled.Cmp(expected) != 0 {
			t.Fatalf("expected scaled integer %s for %s, got %s", expected.String(), value, scaled.String())
		}

		rebuilt := FromScaledInt(scaled)
		if rebuilt.String() != dec.String() {
			t.Fatalf("expected round trip %s, got %s", dec.String(), rebuilt.String())
		}
	}
}

// TestFromScaledIntDoesNotAliasInput proves that the constructor copies the value, so a caller can
// not mutate a decimal through the integer that was passed in.
func TestFromScaledIntDoesNotAliasInput(t *testing.T) {
	source := big.NewInt(12345)
	dec := FromScaledInt(source)
	source.SetInt64(1)

	if dec.String() != "0.00012345" {
		t.Fatalf("expected an independent copy, got %s", dec.String())
	}
}

// splitDecimal converts a decimal string into its unscaled integer representation.
func splitDecimal(value string) string {
	negative := false
	if len(value) > 0 && value[0] == '-' {
		negative = true
		value = value[1:]
	}
	integerPart := value
	fractionPart := ""
	for i := 0; i < len(value); i++ {
		if value[i] == '.' {
			integerPart = value[:i]
			fractionPart = value[i+1:]
			break
		}
	}
	for len(fractionPart) < Scale {
		fractionPart += "0"
	}
	if len(fractionPart) > Scale {
		fractionPart = fractionPart[:Scale]
	}
	combined := integerPart + fractionPart
	if negative {
		return "-" + combined
	}
	return combined
}
