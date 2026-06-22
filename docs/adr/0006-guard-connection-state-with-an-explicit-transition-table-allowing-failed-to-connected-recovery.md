# 6. Guard connection state with an explicit transition table that allows `FAILED → CONNECTED`

## Status

Accepted

## Context

A relay connection moves through a small set of states. The obvious encoding is a flag or a free enum
that any code may set to any value. That lets a connection jump from `DISCONNECTED` straight to
`DISCONNECTING`, or be marked `CONNECTED` while a teardown is in flight — illegal positions that then
have to be defended against everywhere the state is read.

There are four states, and two facts about them invite a reviewer to "simplify" the design wrongly:

- `DISCONNECTING` looks redundant next to `DISCONNECTED`. A reader could collapse the two and lose the
  window in which a teardown has begun but the socket is not yet closed — the window `disconnect()`
  relies on to stop a half-open connection re-entering the connected set.
- `FAILED` looks terminal. A reader could model it as an end state with no outgoing transitions,
  which would make `FAILED → CONNECTED` illegal and break auto-reconnect, since a failed connection
  is exactly what the reconnect loop brings back to `CONNECTED`.

## Decision

`ConnectionState` is a four-case enum — `DISCONNECTED`, `CONNECTED`, `DISCONNECTING`, `FAILED` — and it
owns the allowed-transition table in `canTransitionTo()`. `RelayConnection::updateState()` consults
that table and throws `InvalidArgumentException` on any transition not in it, so an illegal move fails
loudly at its source rather than corrupting state silently.

The permitted transitions are:

- `DISCONNECTED → CONNECTED`
- `CONNECTED → DISCONNECTING` (graceful teardown) or `CONNECTED → FAILED` (error)
- `DISCONNECTING → DISCONNECTED` (teardown complete) or `DISCONNECTING → FAILED` (error during teardown)
- `FAILED → CONNECTED` (recovery via reconnect)

`FAILED` is deliberately **not** terminal: its only outgoing edge is back to `CONNECTED`, which is the
edge the reconnect loop drives. `DISCONNECTING` is a distinct state because the graceful-teardown
window is real: between "we asked to close" and "the socket is closed" the connection must not be
treated as connected, and `disconnect()` uses that window.

## Consequences

- An illegal transition throws at the point it is attempted, naming the from/to states. Bugs that would
  otherwise surface far from their cause as inconsistent state are caught immediately.
- `FAILED → CONNECTED` is a supported recovery edge, not an oversight. Do not model `FAILED` as a dead
  end; auto-reconnect depends on a failed connection being able to return to `CONNECTED`.
- `DISCONNECTING` is not redundant with `DISCONNECTED`. Do not collapse the two; the intermediate state
  is what keeps a connection mid-teardown out of the healthy set.
- The transition table is the single source of truth for legal moves. New states or edges are added
  there, not by scattering ad-hoc checks at call sites.
