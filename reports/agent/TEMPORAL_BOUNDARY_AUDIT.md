# Temporal boundary audit

## Contract established from actual consumers

- `from` and `to` are UTC instants. The simulator selects a closed 5-minute candle iff `from <= open_time < to`. Its last selected candle can close exactly at `to`. No candle with open time exactly `to` is selected.
- Strategy inputs for a decision at time `T` use each timeframe's latest candle with `open_time + interval <= T`. The 30-minute setup frame is required alongside 5/15/60 minutes. An `is_closed` flag alone does not make an hourly candle available before its close.
- Context observations at or before a simulated close can affect bid/ask, sizing metadata and strategy quality. The latest observation before `from` may be used at the first close. Observations strictly after `to` cannot be used. Funding for an open position is applied only after entry and through its last close, including an event exactly at `to`.
- `from` and `to` must align to 5-minute UTC boundaries because the simulator selects whole 5-minute bars by open time. For each higher timeframe, validation starts with the bar containing `from` and stops at the last bar fully closed by `to`; a partial 30/60-minute period at the right edge is not hashed. Missing or noncontiguous required bars fail closed. Pre-window warm-up bars are included in the checksum; an insufficient count remains a warning, not invented history.
- PostgreSQL source timestamps are `timestamptz(3)`. Query bindings and hashes preserve milliseconds and timezone offsets; same UTC instant has the same checksum regardless of caller offset.
- Ordinary forward-PAPER audit events use half-open `[from,to)`. That export is separate from historical simulation and must not be advertised as a simulation replay package.

## Defects confirmed at initial HEAD

`DatasetInspector` omitted 30-minute bars, hashed `getTimestamp()` at second precision, omitted pre-window context, loaded full candle series into memory, and accepted `to + 1ms` as complete due integer-second comparison. It also hashed higher-timeframe bars whose closes occurred after `to`. Eloquent source models formatted written timestamps without milliseconds/offset. A run captured its checksum once, then could complete after inputs changed. Archive ZIP members were not checked against pre-assembly hashes after libzip read staged files.

The apparently inconsistent `whereBetween(..., [$from,$to])` for context/funding at `to` was not changed to `< to`: the final candle close occurs at `to`, and those observations can affect its fill/funding. This is a deliberate difference between candle-open and event-time predicates.

## Unverified boundary cases

No end-to-end historical ZIP exists at current HEAD. No immutable dataset snapshot, concurrent multi-connection mutation proof, all-strategy auxiliary-input manifest, or production provenance proof was established. Funding completeness is recorded by simulated trade logic but no run-wide funding coverage manifest exists. Duplicate candles are constrained by the database unique key; immutable conflicting observations are handled by a separate journal and were not replayed here. Browser timestamp rendering was not retested.
