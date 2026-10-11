# 21. A subscription the relay narrowed stays open, and is sent again once when the connection authenticates

## Status

Accepted

## Context

A relay that guards part of what it stores can answer a `REQ` reaching beyond a guest's scope by serving the part a guest may read and ending the stored events with NIP-67's `"auth"` hint, `["EOSE", <subscription_id>, ["auth"]]`: "the relay may have more stored events matching the subscription's filters if the user performs `AUTH`". NIP-42 adds that the relay "MUST send the `AUTH` challenge before the `EOSE` message", and the client answers a challenge when it arrives (ADR-0014). Once the relay accepts that answer, the subscription still has the narrowed scope it was admitted under. A relay does not re-scope or re-stream an open subscription on authentication, because NIP-01 makes every event after `EOSE` a new real-time event. The client widens the subscription by sending its `REQ` again on the same id, which NIP-01 makes a replacement, answered with its own `EOSE`.

The client read no hint and relied on the relay re-streaming after `AUTH`, so a narrowed subscription stayed narrowed. Two fixes look natural and are wrong:

- Parking a narrowed subscription like one refused with `CLOSED` and `auth-required:` (ADR-0014). The relay did not refuse it: it is open and delivering events, and a declined challenge or an auth timeout must not close it.
- Sending the `REQ` again on every hinted `EOSE` once authenticated. A relay whose policy still narrows the authenticated key answers the replacement with the hint again, and the client would loop.

## Decision

- An `EOSE` is read once per `REQ`. A repeated `EOSE` for a subscription already past its end of stored events is ignored, hint and all. A `REQ` sent again on the id, whether to widen it, to re-issue a parked subscription or as the caller's own replacement, awaits a new one.
- An `EOSE` whose hints include exactly `auth` marks its subscription narrowed, after the listener's `handleEose` has run; a subscription the listener closed there is not marked. Any other hint means nothing here.
- A narrowed subscription stays open, with its listener. It is not parked: the hint answers no challenge, starts no auth timeout, and a rejected `AUTH`, a declined challenge or an auth timeout leave it open and narrowed.
- A narrowed subscription is sent again, on its id with its filters, once:
  - when the relay answers the client's `AUTH` with `OK true`, after the parked work has been resumed;
  - at once, when an auth handler is registered and the relay has already accepted an `AUTH` on the connection.
- A subscription sent again is `Active` until its own `EOSE`, which reaches the listener, and events already delivered may arrive again. A hint on that `EOSE` is ignored.
- A subscription closed by the caller or the relay is no longer narrowed, and so is one the relay refuses with `auth-required:` and the client parks: the parked path re-issues it once (ADR-0014), not this one. A `REQ` the client sends fresh, whether a parked re-issue, the caller's `REQ` on the same id or one first sent after authenticating, has its hint read anew.
- A dropped connection closes a narrowed subscription as `disconnected`, as it does any open subscription (ADR-0015).

## Consequences

- A subscription to a relay that narrows reads gets the wider scope once the client authenticates, with no code in the caller.
- A listener may see a second `handleEose`, and events it already had, on a subscription that was sent again. It must tolerate both.
- A relay that narrows even the authenticated key costs one extra `REQ` per subscription, never a loop.
- Do not park a narrowed subscription, and do not drop the once-only marker: the first closes subscriptions the relay is serving, the second loops against a relay that keeps narrowing.
- Shared decisions: nostr-adrs ADR-0027, ADR-0029 and ADR-0030.
