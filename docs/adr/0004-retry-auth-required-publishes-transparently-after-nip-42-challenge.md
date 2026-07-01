# 4. Retry `auth-required` publishes transparently after a NIP-42 challenge

## Status

Accepted

## Context

A relay may refuse to store an event until the client has authenticated. Under NIP-42 the refusal
arrives as an `OK` response whose accepted flag is `false` and whose message is prefixed
`auth-required`. The relay then issues an `AUTH` challenge; the client signs it, sends the signed
auth event, and — once the relay accepts the auth — the original event can be stored.

The naive shape surfaces this whole dance to the caller: `publishEvent()` returns or throws an
`auth-required` outcome, the application signs the challenge, and then the application re-issues the
same `publishEvent()` call. That forces every publish site to carry retry-after-auth logic, and it
races: the `AUTH` challenge and the `auth-required` rejection arrive on the same socket in an order
the caller cannot control.

Parking a publish this way only makes sense when the client can actually complete the challenge.
Signing the `AUTH` challenge is the host's job, supplied through an optional
`AuthChallengeHandlerInterface`; a client publishing only to open relays registers none. With no
handler there is nothing to send back, so a publish parked on `auth-required` would wait for an auth
event that is never produced — a future pending forever. Parking must therefore be conditional on the
client being able to authenticate at all.

To avoid the retry-at-the-call-site problem, each relay's `RelaySession` holds two pieces of state that
read like a leak to a reviewer and invite deletion:

- `pendingEvents`, keyed by event id, retains the *full signed event* after it has already been sent.
  Holding a sent event looks like a memory leak.
- `authRetryQueue` holds the deferred futures of publishes parked on an `auth-required` rejection. A
  queue of "rejected" publishes looks like rejected work that should have been failed and discarded.

## Decision

When an `OK` rejection carries `auth-required` **and an `AuthChallengeHandlerInterface` is registered**,
the rejected publish is parked, not failed. The event stays in `pendingEvents` and its deferred future
is moved into the session's `authRetryQueue` rather than being errored. When the relay later accepts the
client's signed auth event, the queue is flushed: every parked event is re-sent on the same connection
and its original deferred future is re-registered against the fresh `OK`. The caller's single
`publishEvent()` future resolves once — on the eventual accept — and never observes the intermediate
`auth-required`.

- **With no auth handler registered, the publish is not parked.** Its future is completed with
  `PublishResult::rejected(...)` carrying the relay's `auth-required` message, exactly as any other
  relay refusal is returned. Parking would stake the future on a signed auth event the client cannot
  produce, so the relay's "no" is handed back as the publish's final outcome rather than left pending
  forever. This is the sole branch on which an `auth-required` rejection is surfaced to the caller.
- The signed event is retained precisely because it must be retransmitted byte-for-byte after auth;
  re-signing is not an option, as the caller is not in the loop. `pendingEvents` is the retransmit
  buffer, not a leak: an event is removed as its future resolves (on accept and on terminal rejection),
  and the whole buffer is dropped when the relay's `RelaySession` is discarded on disconnect.
- The retry queue lives on the per-relay session because auth is per-connection: one accepted auth
  event unblocks every publish parked on that relay, so they flush together.
- If the auth itself is rejected, the whole queue is failed with the auth's reason
  (`failAuthRetryQueue`); the parked publishes do not hang.

## Consequences

- `publishEvent()` is auth-transparent: a caller publishing to an auth-required relay needs only a
  registered `AuthChallengeHandlerInterface`, and writes no retry code. The published future resolves
  once, on the final outcome.
- A caller that publishes to an auth-required relay *without* a registered handler gets a resolved
  `PublishResult` (rejected, carrying the relay's `auth-required` reason), never a future that hangs.
  Registering a handler is exactly what upgrades that rejection into a transparent retry; its absence
  is what turns the same signal into a returned "no". Do not "simplify" the branch into unconditional
  parking — that reintroduces the pending-forever hang for the no-handler caller.
- Each relay's session holds sent events and parked futures in memory between the rejection and the
  auth accept. This is bounded by the in-flight publish count and is released on accept, terminal
  rejection, or disconnect. Do not "tidy" `pendingEvents`/`authRetryQueue` away as leaked state — they
  are the retransmit buffer that makes the retry transparent.
- `awaitPendingPublishes()` must drain the `authRetryQueue` futures as well as `pendingResponses`,
  because a publish parked on auth is still pending from the caller's point of view.
