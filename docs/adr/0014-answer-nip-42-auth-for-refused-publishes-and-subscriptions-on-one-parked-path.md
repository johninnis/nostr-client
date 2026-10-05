# 14. Answer NIP-42 auth for refused publishes and subscriptions on one parked path

## Status

Accepted. Supersedes ADR-0004.

## Context

NIP-42 lets a relay refuse an `EVENT` with `OK false` or a `REQ` with `CLOSED`, both prefixed `auth-required:`. Its protocol flow shows the client authenticating, the relay answering the `AUTH` with `OK true`, and the client then sending the `EVENT` or `REQ` again. The NIP says `AUTH` messages sent by clients "MUST be answered with an `OK` message", so the relay's verdict on the `AUTH` always arrives, and that "the client must have a stored challenge associated with that relay so it can act upon that in response to the `auth-required` `CLOSED` message". A challenge "is valid for the duration of the connection or until another challenge is sent by the relay". NIP-01 requires every refusal reason to be "a machine-readable single-word prefix followed by a `:` and then a human-readable message".

Handing that sequence to every caller forces retry code into all of them and races: the challenge and the refusal arrive in an order the caller cannot control. ADR-0004 parked refused publishes and resent them when the relay accepted the client's `AUTH`. It had four defects:

- It wrote its own rejection reason as `auth-required, auth rejected: …`, which has no colon after the prefix and so does not parse as an `auth-required` reason.
- It treated every `OK false` for its `AUTH` event as final. A relay that receives an `AUTH` it has no challenge for issues a fresh challenge and answers the `AUTH` with `OK false` `auth-required:` (innis/nostr-relay `ProcessAuthUseCase`; shared record ADR-0028). That reply asks for the fresh challenge to be answered; it is not a refusal.
- A handler that declined the challenge (returned `null`) left the parked publishes parked forever, and so did a relay that never answered the `AUTH` or never sent a challenge.
- It treated every `CLOSED` as terminal, so a subscription refused with `auth-required:` ended even when the client could authenticate, contrary to the NIP's flow.

Parking only makes sense while the client can still authenticate the connection. Signing the challenge is the host's job, supplied through the optional `AuthChallengeHandlerInterface` (ADR-0010); a client talking only to open relays registers none, and a person in the loop may decline.

## Decision

There is one path for auth-parked work on a connection, shared by publishes and subscriptions. Both are a `ParkedWorkInterface` held on the connection's `RelaySession`, which knows how to resume itself (resend the retained signed event byte for byte, or re-issue the `REQ` on the same subscription id with the same filters) and how to refuse itself (resolve the publish's future as rejected, or close the subscription through `handleClosed`).

- **Parking.** An `OK false` or `CLOSED` whose reason prefix is `auth-required` is parked only when an auth handler is registered, the relay has not already accepted an `AUTH` on this connection, and the handler has not declined the relay's current challenge. Otherwise the relay's refusal is the outcome: the publish resolves with the relay's `OK` as a `PublishResult::rejected`, and the subscription is closed with the relay's message. A publish's future stays registered while parked, so `awaitPendingPublishes()` still waits for it. Parking suspends the publish's own timeout (ADR-0015): the auth timeout bounds parked work instead, and the resend starts the publish timeout again.
- **The stored challenge.** Every `AUTH` challenge is stored on the session, and a new one clears an earlier decline. It is answered on arrival when a handler is registered, the relay has not accepted an `AUTH`, and no answer is in flight. Parking answers the stored challenge if it has not been answered yet, so a challenge that arrived before the handler was set, or while another answer was in flight, is still answered.
- **The relay's `OK` for the client's `AUTH` event releases parked work:**
  - `OK true`: the connection is authenticated; every parked event is resent and every parked subscription's `REQ` re-issued.
  - `OK false` with `auth-required:`: the stored challenge is answered if it is fresh, now or when it arrives, and the work stays parked.
  - `OK false` with any other reason: the parked work is refused with `auth-required: auth rejected: ` followed by the relay's message.
- **A decline.** A handler returning `null` declines the relay's current challenge: parked work is refused with `auth-required: auth declined`, and later `auth-required` refusals are returned at once until the relay sends a new challenge.
- **The auth timeout.** One timer per connection, `ConnectionConfig::authTimeoutMs` (default 60000), runs while an answer is in flight or work is parked, and restarts with each answer sent. When it fires, the answer in flight is abandoned and parked work is refused with `auth-required: auth timed out`.
- **A dropped connection** settles parked publishes and closes parked subscriptions as `disconnected`, as it does any other in-flight work (ADR-0015), and cancels the timer. So does a resend or re-issued `REQ` that cannot be written.

Nothing else releases parked work. Every reason the client writes for auth-refused work starts with `auth-required:`, so it parses under the shared `OK`/`CLOSED` reason vocabulary; `disconnected` is the client's word for a relay that went away and carries no prefix (ADR-0015).

## Consequences

- Callers write no retry code. `publishEvent()` resolves once, on the final outcome, and a subscription to a relay that requires authentication for reads receives events once the relay accepts the `AUTH`, without the caller seeing the intermediate `CLOSED`.
- No parked work waits forever: it is released by the relay's verdict on the `AUTH`, a decline, the auth timeout or a dropped connection. Do not park unconditionally, and do not remove the timeout: either reintroduces a publish or subscription that never settles.
- Nothing is resent before the relay has accepted the `AUTH`, and a relay that refuses again after accepting one ends the work instead of looping.
- A relay's `auth-required:` answer to the client's `AUTH` is a request to answer again, never a refusal. Do not fold it into the rejection branch.
- A person who declined is not asked again until the relay sends a new challenge.
- Do not let the publish timeout run while a publish is parked: it would settle it as `timeout` while a slow handler is still answering the challenge.
- The session holds the retained signed events, the parked work, the stored challenge and the timer between the refusal and the verdict. They are released on the verdict, a decline, the timeout or disconnect; do not tidy them away as leaked state.
- A publish and a subscription share the park and release code. A new kind of auth-refused work implements `ParkedWorkInterface` rather than adding a third path.
