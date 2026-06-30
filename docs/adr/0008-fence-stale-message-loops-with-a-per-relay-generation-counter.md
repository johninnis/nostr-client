# 8. Fence stale message-handler loops with a per-relay generation counter

## Status

Accepted

## Context

Each live connection runs a detached background fiber — the message-handler loop in `startMessageHandler` — that iterates the WebSocket, dispatching every frame the relay sends. The loop ends when the socket closes: cleanly when the remote hangs up, or because the client itself closed the socket in `disconnect()` (and `disconnect()` is exactly what a `reconnect()` does first). On exit the loop reports the drop, which marks the connection `FAILED` and, under auto-reconnect, schedules a fresh connection.

That fiber outlives the connection that started it. Closing a socket does not stop its loop synchronously — the iterator unwinds on a later tick, after `disconnect()` has already returned and, on a reconnect, after a *new* socket and a new loop are already in place for the same relay. A relay is keyed by its URL, so the new connection occupies the very slot the dying fiber still believes it owns. Left unguarded, the stale fiber's "socket closed" path would then fire against the live connection: it would mark a relay the client just deliberately disconnected as `FAILED` and start reconnecting it, or it would tear down and reconnect a healthy connection that a reconnect had only just restored — a connection flapping against its own replacement.

The machinery that prevents this reads like something to delete. `connect()` and `disconnect()` both bump a per-relay integer in `connectionGenerations`, and `disconnect()` incrementing a counter for a connection it is in the middle of discarding looks pointless. The loop then guards three of its actions with `($this->connectionGenerations[$url] ?? 0) === $generation`, which reads like defensive over-checking. A reviewer is tempted to drop the counter and instead test "is there still an active socket for this relay?" — which is precisely the check that cannot tell the dying fiber's relay slot apart from its replacement's.

## Decision

Every connection carries a generation. `connect()` increments `connectionGenerations[$url]` and hands that number to the message-handler loop it starts; the loop captures it for life. `disconnect()` increments the same counter, so the act of tearing a connection down advances the generation past the one its loop holds.

A loop acts on the connection's death — calling `handleConnectionError`, which transitions state and may schedule a reconnect — **only while its captured generation is still the current one** for that relay. The check sits at every exit: normal loop end, an exception inside the loop, and the future's `catch`. `handleConnectionError` re-checks the same guard, so a report that was already in flight when the generation moved is dropped there too. A superseded loop, finding its generation stale, returns silently: its socket is gone, but its death is not news, because something newer already owns the relay.

The generation is a fencing token, not a liveness flag. It answers "am I still the current connection for this relay?", which neither the presence of an active socket nor the identity of a `WebsocketConnection` can answer once a reconnect has reused the relay's slot. An integer epoch also covers the disconnect-without-reconnect case — where there is no replacement socket to compare against — with the same single mechanism.

`handleConnectionError` is also called synchronously from `subscribeMultiple` when a send fails; that path passes no generation, and the guard treats a null generation as "current by definition", because a failure observed on the calling fiber is necessarily about the live connection.

## Consequences

- A deliberate `disconnect()`, and the `disconnect()` inside every `reconnect()`, cannot be undone by the old loop's trailing "socket closed" report: that report arrives against a newer generation and is discarded. Auto-reconnect fires once, from the live connection, not from the corpse of the previous one.
- `connectionGenerations[$url]` is bumped on connect **and** on disconnect, and is read with a `?? 0` default at four sites (`connect` start aside). This is intentional: the disconnect bump is what invalidates the outgoing loop. Do not "tidy" the disconnect increment away, and do not replace the generation guard with an active-socket or connection-identity check — neither distinguishes a stale fiber's relay from the live connection that has since taken its place.
- A new state or exit added to the message-handler loop must carry the same generation guard before it touches connection state; the guard is the loop's licence to act, not an optional precaution.
- The counter is monotonic per relay for the process lifetime and never reset. It is an opaque epoch: only equality against the captured value is meaningful, never its magnitude.
