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

To avoid that, the connection holds two pieces of state that read like a leak to a reviewer and
invite deletion:

- `pendingEvents`, keyed `relay:eventIdHex`, retains the *full signed event* after it has already
  been sent. Holding a sent event looks like a memory leak.
- `authRetryQueue`, keyed by relay, holds the deferred futures of publishes parked on an
  `auth-required` rejection. A per-relay queue of "rejected" publishes looks like rejected work that
  should have been failed and discarded.

## Decision

When an `OK` rejection carries `auth-required`, the rejected publish is parked, not failed. The event
stays in `pendingEvents` and its deferred future is moved into `authRetryQueue[relay]` rather than
being errored. When the relay later accepts the client's signed auth event, the queue is flushed:
every parked event is re-sent on the same connection and its original deferred future is re-registered
against the fresh `OK`. The caller's single `publishEvent()` future resolves once — on the eventual
accept — and never observes the intermediate `auth-required`.

- The signed event is retained precisely because it must be retransmitted byte-for-byte after auth;
  re-signing is not an option, as the caller is not in the loop. `pendingEvents` is the retransmit
  buffer, not a leak: it is cleared on accept, on terminal rejection, and on disconnect
  (`clearPendingForRelay`).
- The retry queue is keyed by relay because auth is per-connection: one accepted auth event unblocks
  every publish parked on that relay, so they flush together.
- If the auth itself is rejected, the whole queue for that relay is failed with the auth's reason
  (`failAuthRetryQueue`); the parked publishes do not hang.

## Consequences

- `publishEvent()` is auth-transparent: a caller publishing to an auth-required relay needs only a
  registered `AuthChallengeHandlerInterface`, and writes no retry code. The published future resolves
  once, on the final outcome.
- The connection holds sent events and parked futures in memory between the rejection and the auth
  accept. This is bounded by the in-flight publish count and is released on accept, terminal
  rejection, or disconnect. Do not "tidy" `pendingEvents`/`authRetryQueue` away as leaked state — they
  are the retransmit buffer that makes the retry transparent.
- `awaitPendingPublishes()` must drain the `authRetryQueue` futures as well as `pendingResponses`,
  because a publish parked on auth is still pending from the caller's point of view.
