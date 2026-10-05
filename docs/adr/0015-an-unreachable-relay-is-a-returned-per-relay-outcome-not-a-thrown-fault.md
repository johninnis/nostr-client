# 15. A relay's refusal and a relay's unavailability are returned per-relay outcomes, not thrown faults

## Status

Accepted. Supersedes ADR-0009.

## Context

When a client publishes an event, the relay answers with a NIP-01 `OK` frame: an accepted flag and a message. A relay routinely declines a well-formed event — `duplicate:`, `rate-limited:`, `blocked:`, `invalid:`, `auth-required:` — and that "no" is a normal, expected answer, not a malfunction. NIP-01 requires every such reason to be "a machine-readable single-word prefix followed by a `:` and then a human-readable message".

Some publishes never get an `OK`. On an open network some relay is always down: the socket cannot be opened, it drops before the relay answers, or the relay never answers at all. ADR-0009 returned a relay's refusal as a value but kept those cases as thrown faults: `connect()`, and `publishEvent()`, `subscribe()`, `subscribeMultiple()`, `unsubscribe()` and `ping()` on a relay the client was not connected to, threw `ConnectionException`, and a publish's future errored with one when the connection dropped before the `OK`, parked publishes included. A publish the relay never answered stayed pending forever, and so did one in flight when the application called `disconnect()`. It also wrote the client's NIP-42 outcome as `auth-required, auth rejected: …`, which has no colon after the prefix and so does not parse as an `auth-required` reason.

That shape reads like the strict reading of "an infrastructure failure is a fault", and it fails the caller. PHP records nothing about what a method throws, so every caller has to remember a `try` around every call to every relay; one that forgets turns one relay being down into a crash and loses the answers the other relays gave. The shared decision (nostr-adrs ADR-0001, and nostr-core ADR-0089, which supersedes nostr-core ADR-0003) settles it for every relay client in the ecosystem: a relay that cannot be reached, drops the connection before answering, or does not answer in time is an anticipated outcome, returned as that relay's result beside every other relay's, and the TypeScript pool (@innis/nostr-relay-pool ADR-0003) already returns it.

Two tensions remain for a reader to "correct":

- The client's own outcome and a relay's refusal share a result type. If the client wrote its outcome in a relay's shape (`error: disconnected`), a caller classifying reasons would read the client's words as the relay's.
- `publishEvent()` is asynchronous — the `OK` arrives later, on the same socket — so the outcome cannot be a plain synchronous return without forcing every publish to block. A reader expecting a synchronous `PublishResult` will read the `Future` wrapper as overengineering.

## Decision

Everything a relay can do to a well-formed operation, answer it, refuse it, be unreachable, drop, or stay silent, is **an anticipated outcome, returned as that relay's value**. Only a broken client is a **thrown fault**.

- `connect()` and `reconnect()` return a `ConnectResult`: connected, or not connected with the message `failed to connect`. A relay whose handshake fails is reported there and every other relay is untouched.
- `publishEvent()` returns a `Future<PublishResult>` that always **completes** and never errors for a relay's behaviour. `PublishResult` mirrors the `OK` frame, `accepted` plus a message, and completes with:
  - `PublishResult::accepted(...)` or `PublishResult::rejected(...)` from the relay's `OK`, carrying the relay's own message;
  - `PublishResult::unavailable(RelayUnavailability::Disconnected)`, message `disconnected`, when the relay is not connected, when the send fails, when the connection drops before the `OK` (a publish parked on auth included), or when the application disconnects the relay while the publish is in flight;
  - `PublishResult::unavailable(RelayUnavailability::Timeout)`, message `timeout`, when no `OK` arrives within `ConnectionConfig::publishTimeoutMs` (default 8000). The timer starts when the event is sent, is suspended while the publish is parked on auth, where the auth timeout bounds it instead, and restarts when the parked event is resent.
- A second `publishEvent()` of an event already in flight to the same relay, parked on auth included, joins the first: the event is not sent again, no second timer starts, and both callers receive the same future and so the same outcome, as the TypeScript pool does. Keying a second publish over the first would strand the first future.
- Under NIP-42 (ADR-0014) the single returned future stays pending across the auth exchange and resolves once on the eventual outcome: the relay's `OK` to the resent event, or `rejected` with a reason the client writes — `auth-required: auth rejected: …`, `auth-required: auth declined`, `auth-required: auth timed out`. The caller never sees the intermediate `auth-required`.
- `subscribe()` and `subscribeMultiple()` on a relay that is not connected return the subscription id and close the subscription at once through the handler's `handleClosed()` with `disconnected`. A subscription open when the connection drops is closed the same way. `unsubscribe()` on a relay that is not connected does nothing, since there is nothing left to close.
- `ping()` returns a `HealthCheckResult`: healthy, or unhealthy with `disconnected` when the relay is not connected or the ping cannot be written. `healthCheck()` gathers them without a `catch`.
- **The client's own messages carry no reason prefix.** `failed to connect`, `disconnected` and `timeout` are the cases of `RelayUnavailability`, the same words the TypeScript pool uses, and none of them parses under `ReasonPrefix`, so a caller never mistakes them for a relay's reason. Settling work the relay refused with `auth-required` keeps that prefix, because that outcome is the relay's refusal.
- **What still throws is a broken client**: a misused API, a broken invariant, a failure of the client's own machinery. `ConnectionFactory` converts only an `Exception` from the transport into an unreachable relay; an `Error` propagates. `ConnectionException` remains the client's fault, rooted as ADR-0002 records.

The future is returned, not held privately, so the caller chooses: `->await()` it for the outcome, or drop it for fire-and-forget. It is internally `ignore()`d so a dropped future never surfaces as an unhandled error. `Future` appears in the public contract deliberately: this is an AMPHP-based async client, callers already drive it with Amp, and a per-publish handle is what lets many publishes be fired and their individual outcomes awaited.

The connected check stays in the application service (ADR-0012). The transport adapter makes the same check itself, so that it is total when driven directly and during the window in which a reconnect has no session.

## Consequences

- A caller learns each relay's answer: `$client->publishEvent($relay, $event)->await()->isAccepted()`, with the relay's reason, or the client's own word for a relay that could not answer, in `getMessage()`. Publishing to ten relays with one down yields nine answers and one `disconnected`, never an exception.
- No publish waits forever: it settles on the relay's `OK`, the client's `auth-required:` reasons, a dropped or closed connection, or the publish timeout.
- Every rejection reason a relay writes, and every reason the client writes when settling auth-refused work, starts with a machine-readable prefix followed by `:`; the client's unavailability messages carry none. Do not prefix them, and do not dress an unreachable relay as a relay's refusal.
- A `try`/`catch` around a client call is never needed for a relay being down. Do not "restore" throwing for an unreachable relay, a drop or a timeout: it re-conflates a relay's absence with a broken client and loses the other relays' answers.
- `ConnectionException` no longer escapes a client operation for a relay's behaviour; the soak harness and the connection fuzz test assert that nothing escapes at all.
- `awaitPendingPublishes()` remains the batch drain for "wait until every in-flight publish for this relay has settled", parked publishes included; per-publish outcomes come from each returned future.
