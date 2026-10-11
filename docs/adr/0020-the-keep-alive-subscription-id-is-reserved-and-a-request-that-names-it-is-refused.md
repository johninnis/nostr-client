# 20. The keep-alive subscription id is reserved, and a subscription request that names it is refused

## Status

Accepted

## Context

The idle heartbeat (ADR-0013) and the reply to a `ping` NOTICE (ADR-0003) both send `["CLOSE", "keepalive"]`. Both rest on no subscription of that id being open, so that the relay ignores the frame. NIP-01 lets a subscription id be "an arbitrary, non-empty string of max length 64 chars", and a caller may choose its own (ADR-0019). A caller that chose `keepalive` had its subscription closed on the relay by the next heartbeat or ping reply, while the client still held it open and its handler waited for events that would never come.

Moving the keep-alive to another fixed id moves the collision without removing it: any fixed id is one a caller can choose. A fresh id per keep-alive frame would send every relay a stream of ids it never saw. Renaming a caller's subscription behind its back would break the correlation the caller chose the id for.

## Decision

- The client reserves its keep-alive id, `ReservedSubscriptionId::KeepAlive` (`keepalive`). The heartbeat and the ping reply both take it from there, so the id has one definition.
- A `SubscriptionRequest` naming a reserved id is refused when it is built, whether through its constructor, `for()` or `withSubscriptionId()`, with an `InvalidArgumentException`. Asking for the id is a misuse of the API, not something a relay did, so it is thrown rather than returned (ADR-0015).
- An id the client generates (`SubscriptionId::generate()`, 32 hexadecimal digits) is never `keepalive`.
- The comparison is exact, as a relay compares ids: `KeepAlive` and `KEEPALIVE` are other ids and are accepted.

## Consequences

- No heartbeat or ping reply closes a subscription the client holds open.
- A host that built a request under `keepalive` learns it when it builds the request, before anything is sent to a relay.
- Do not lift the refusal to "allow any id NIP-01 allows", and do not move the keep-alive to another fixed id instead: either brings back a subscription closed by the client's own keep-alive.
- Shared decision: nostr-adrs ADR-0035.
