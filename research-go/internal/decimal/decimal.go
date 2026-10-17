package decimal

import (
	"fmt"
	"math"
	"math/big"
	"strconv"
	"strings"
)

const Scale = 8

var scaleMultiplier = big.NewInt(100000000) // 10^8

// Decimal represents a fixed-point decimal with 8 decimal places using big.Int
type Decimal struct {
	value *big.Int
}

func Zero() Decimal {
	return Decimal{value: big.NewInt(0)}
}

func FromInt64(n int64) Decimal {
	v := new(big.Int).Mul(big.NewInt(n), scaleMultiplier)
	return Decimal{value: v}
}

func FromFloat64(f float64) Decimal {
	scaled := math.Round(f * 1e8)
	return Decimal{value: big.NewInt(int64(scaled))}
}

func FromString(s string) (Decimal, error) {
	s = strings.TrimSpace(s)
	if s == "" || s == "0" {
		return Zero(), nil
	}
	parts := strings.Split(s, ".")
	intPart := parts[0]
	decPart := ""
	if len(parts) > 1 {
		decPart = parts[1]
	}

	if len(decPart) > Scale {
		decPart = decPart[:Scale]
	} else {
		decPart = decPart + strings.Repeat("0", Scale-len(decPart))
	}

	combined := intPart + decPart
	bi, ok := new(big.Int).SetString(combined, 10)
	if !ok {
		return Zero(), fmt.Errorf("invalid decimal string: %s", s)
	}
	return Decimal{value: bi}, nil
}

func MustFromString(s string) Decimal {
	d, err := FromString(s)
	if err != nil {
		panic(err)
	}
	return d
}

func (d Decimal) Add(other Decimal) Decimal {
	res := new(big.Int).Add(d.value, other.value)
	return Decimal{value: res}
}

func (d Decimal) Sub(other Decimal) Decimal {
	res := new(big.Int).Sub(d.value, other.value)
	return Decimal{value: res}
}

func (d Decimal) Mul(other Decimal) Decimal {
	prod := new(big.Int).Mul(d.value, other.value)
	res := new(big.Int).Quo(prod, scaleMultiplier)
	return Decimal{value: res}
}

func (d Decimal) Div(other Decimal) (Decimal, error) {
	if other.IsZero() {
		return Zero(), fmt.Errorf("division by zero")
	}
	scaled := new(big.Int).Mul(d.value, scaleMultiplier)
	res := new(big.Int).Quo(scaled, other.value)
	return Decimal{value: res}, nil
}

func (d Decimal) Neg() Decimal {
	return Decimal{value: new(big.Int).Neg(d.value)}
}

func (d Decimal) Cmp(other Decimal) int {
	return d.value.Cmp(other.value)
}

func (d Decimal) IsZero() bool {
	return d.value == nil || d.value.Sign() == 0
}

func (d Decimal) IsPositive() bool {
	return d.value != nil && d.value.Sign() > 0
}

func (d Decimal) IsNegative() bool {
	return d.value != nil && d.value.Sign() < 0
}

func (d Decimal) Float64() float64 {
	if d.value == nil {
		return 0
	}
	f, _ := new(big.Float).Quo(new(big.Float).SetInt(d.value), new(big.Float).SetInt(scaleMultiplier)).Float64()
	return f
}

func (d Decimal) String() string {
	if d.value == nil || d.value.Sign() == 0 {
		return "0.00000000"
	}
	sign := ""
	val := new(big.Int).Set(d.value)
	if val.Sign() < 0 {
		sign = "-"
		val.Neg(val)
	}
	s := val.String()
	if len(s) <= Scale {
		s = strings.Repeat("0", Scale-len(s)+1) + s
	}
	intPart := s[:len(s)-Scale]
	decPart := s[len(s)-Scale:]
	return sign + intPart + "." + decPart
}

func (d Decimal) ToRat() *big.Rat {
	if d.value == nil {
		return new(big.Rat)
	}
	return new(big.Rat).SetFrac(d.value, scaleMultiplier)
}

func FromRat(r *big.Rat) Decimal {
	if r == nil {
		return Zero()
	}
	return MustFromString(r.FloatString(Scale))
}

func (d Decimal) MarshalJSON() ([]byte, error) {
	return []byte(strconv.Quote(d.String())), nil
}

func (d *Decimal) UnmarshalJSON(data []byte) error {
	s := string(data)
	if s == "null" || s == `""` {
		*d = Zero()
		return nil
	}
	unquoted, err := strconv.Unquote(s)
	if err != nil {
		unquoted = s
	}
	parsed, err := FromString(unquoted)
	if err != nil {
		return err
	}
	*d = parsed
	return nil
}
