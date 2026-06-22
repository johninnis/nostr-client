# 5. Reconnect with jittered exponential backoff, treating `0` attempts as unlimited

## Status

Accepted

## Context

A relay connection can drop mid-session: the socket closes from the remote, or a read fails. When
auto-reconnect is enabled the client must re-establish the connection on its own. Two parts of how it
does so read like mistakes a later reader would "correct":

- `reconnectMaxAttempts` defaults to `0`, and the loop treats `0` as *unlimited* retries rather than
  *no* retries. A reader scanning `0 === $maxAttempts` could read it as "give up immediately" and
  invert the guard, or could decide an unbounded retry loop is a runaway and cap it.
- The reconnection listener is invoked only after a reconnect *succeeds*, never on each failed attempt.
  A reader wanting symmetry might add an `onReconnectFailed` call into the retry loop, turning a
  success notification into a per-attempt firehose.

The retry must also not stampede: a fleet of clients all reconnecting to the same relay on identical
fixed delays would re-converge on synchronised reconnect storms.

## Decision

Auto-reconnect runs a single background loop per relay (`scheduleReconnect`), guarded by a per-relay
cancellation so only one loop runs at a time and `disconnect()` can stop it.

- **Backoff is exponential with jitter.** The base delay is `min(initialDelay * 2^attempt, maxDelay)`,
  plus a random jitter of up to 25% of that delay. The cap (`reconnectMaxDelayMs`) bounds the wait;
  the jitter de-synchronises clients so they do not reconnect in lockstep.
- **`reconnectMaxAttempts == 0` means unlimited.** Zero is the sentinel for "keep trying", chosen as
  the default because a transient relay outage should self-heal without the application restarting the
  connection. A positive value bounds the attempts and the loop gives up after them.
- **The listener fires only on a successful reconnect.** `ReconnectionListenerInterface::onReconnected`
  exists so an application can re-establish per-connection state (re-subscribe, re-authenticate) once
  the socket is back. A failed attempt has nothing to re-establish, so it is logged, not signalled. A
  throw from the listener is caught and logged so it cannot abort the loop.

## Consequences

- With the defaults, a dropped connection retries forever with backoff capped at one minute, so a relay
  that comes back is picked up automatically; an application that wants bounded retries sets
  `reconnectMaxAttempts` to a positive number.
- `0 === $maxAttempts` is the unlimited sentinel, not "no attempts". Do not invert that guard or cap the
  unbounded loop: cancellation, not an attempt ceiling, is how a reconnect loop is stopped
  (`disconnect()` cancels it).
- `onReconnected` is a success-only signal. Do not add per-attempt failure callbacks to it; the loop's
  failures are a logging concern, and the listener's contract is "the connection is usable again".
- The 25% jitter makes the exact reconnect delay non-deterministic by design; tests assert on the
  bounded range, not an exact value.
