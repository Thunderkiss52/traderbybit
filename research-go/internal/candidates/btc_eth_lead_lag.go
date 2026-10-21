package candidates

import (
	"fmt"
	"time"

	"github.com/traderbybit/research-go/internal/decimal"
)

// LeadLagConfig is the precommitted configuration of the cross-symbol lead/lag family.
//
// The peer series is aligned on CLOSE time and on KNOWN time. A peer bar that was not fully closed
// and known at the decision timestamp can never influence a decision.
type LeadLagConfig struct {
	MinBars             int
	ATRPeriod           int
	StopATRMult         float64
	TargetATRMult       float64
	PeerLagBars         int
	LocalLagBars        int
	PeerMoveThreshold   decimal.Decimal
	LocalMoveThreshold  decimal.Decimal
	MaxPeerStalenessBar int
}

// DefaultLeadLagConfig returns the precommitted default configuration.
func DefaultLeadLagConfig() LeadLagConfig {
	return LeadLagConfig{
		MinBars:             60,
		ATRPeriod:           14,
		StopATRMult:         1.5,
		TargetATRMult:       3.0,
		PeerLagBars:         4,
		LocalLagBars:        4,
		PeerMoveThreshold:   decimal.MustFromString("0.00200000"),
		LocalMoveThreshold:  decimal.MustFromString("0.00050000"),
		MaxPeerStalenessBar: 2,
	}
}

// BTCETHLagLead evaluates the BTC/ETH lead-lag hypothesis family.
//
// The candidate only produces a RESEARCH SIGNAL. Executing both legs of a pair with coherent
// financing, synchronized exposure and concurrency is not modelled by the research engine, so the
// pipeline marks this cohort EXECUTION_NOT_VALIDATED and never simulates a pair fill.
type BTCETHLagLead struct {
	cfg LeadLagConfig
}

// NewBTCETHLagLead builds the candidate with the given configuration.
func NewBTCETHLagLead(cfg LeadLagConfig) *BTCETHLagLead {
	return &BTCETHLagLead{cfg: cfg}
}

// ID returns the research cohort identifier.
func (c *BTCETHLagLead) ID() string { return "btc_eth_lead_lag" }

// Version returns the versioned rule set of the candidate.
func (c *BTCETHLagLead) Version() string { return "1.0.0" }

// Generate produces the candidate signals.
func (c *BTCETHLagLead) Generate(d Dataset) ([]Signal, error) {
	if err := validateDataset(d, c.cfg.MinBars); err != nil {
		return nil, err
	}
	if len(d.PeerBars) < c.cfg.PeerLagBars+c.cfg.MaxPeerStalenessBar+1 {
		return nil, fmt.Errorf("candidate %s: not enough aligned peer bars (%d)", c.ID(), len(d.PeerBars))
	}

	ind := computeIndicators(d.Bars, c.cfg.ATRPeriod, 20, 50, 14, 20, 2.0)
	barDuration := d.Bars[1].CloseTime.Sub(d.Bars[0].OpenTime)
	if barDuration <= 0 {
		return nil, fmt.Errorf("candidate %s: cannot derive the bar duration", c.ID())
	}

	signals := make([]Signal, 0, 16)

	for i := c.cfg.MinBars; i < len(d.Bars); i++ {
		decisionTime := d.Bars[i].CloseTime

		peer, ok := lastKnownBar(d.PeerBars, decisionTime)
		if !ok {
			continue
		}
		if decisionTime.Sub(peer.CloseTime) > time.Duration(c.cfg.MaxPeerStalenessBar)*barDuration {
			continue
		}

		peerIdx := -1
		for j := range d.PeerBars {
			if d.PeerBars[j].OpenTime.Equal(peer.OpenTime) {
				peerIdx = j
				break
			}
		}
		if peerIdx < c.cfg.PeerLagBars {
			continue
		}

		peerBase := d.PeerBars[peerIdx-c.cfg.PeerLagBars].Close
		if peerBase.IsZero() {
			continue
		}
		peerMove, err := d.PeerBars[peerIdx].Close.Sub(peerBase).Div(peerBase)
		if err != nil {
			continue
		}

		localBase := d.Bars[i-c.cfg.LocalLagBars].Close
		if localBase.IsZero() {
			continue
		}
		localMove, err := d.Bars[i].Close.Sub(localBase).Div(localBase)
		if err != nil {
			continue
		}

		side := ""
		reason := ""
		if peerMove.Cmp(c.cfg.PeerMoveThreshold) >= 0 && localMove.Cmp(c.cfg.LocalMoveThreshold) <= 0 {
			side, reason = "BUY", "PEER_UP_LOCAL_LAGGING"
		} else if peerMove.Cmp(c.cfg.PeerMoveThreshold.Neg()) <= 0 && localMove.Cmp(c.cfg.LocalMoveThreshold.Neg()) >= 0 {
			side, reason = "SELL", "PEER_DOWN_LOCAL_LAGGING"
		}
		if side == "" {
			continue
		}

		atrValue, ok := atrAt(ind.atr, i)
		if !ok || !atrValue.IsPositive() {
			continue
		}

		stop, target := stopAndTarget(side, d.Bars[i].Close, atrValue, c.cfg.StopATRMult, c.cfg.TargetATRMult)

		signals = append(signals, Signal{
			CandidateID:  c.ID(),
			Side:         side,
			DecisionTime: decisionTime,
			DecisionBar:  i,
			EntryBar:     i + 1,
			StopLoss:     stop,
			TakeProfit:   target,
			Reason:       reason,
			Features: map[string]string{
				"peer_symbol":     d.PeerSymbol,
				"peer_close_time": peer.CloseTime.UTC().Format(time.RFC3339),
				"peer_move":       peerMove.String(),
				"local_move":      localMove.String(),
			},
		})
	}

	return signals, nil
}
