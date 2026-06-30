# 9. A relay's publish rejection is a returned `PublishResult`, not a thrown fault

## Status

Accepted

## Context

When a client publishes an event, the relay answers with a NIP-01 `OK` frame: an accepted flag and a
message. A relay routinely declines a well-formed event — `duplicate:`, `rate-limited:`, `blocked:`,
`invalid:`, `auth-required:` — and that "no" is a normal, expected answer, not a malfunction.

The shape this takes reads like a smell from two opposite directions, and a reader is tempted to
"correct" either:

- An earlier design completed the publish's future with a bare `bool` and **errored** it on a rejection,
  so a relay declining an event surfaced as a thrown `ConnectionException`. That conflates "the relay
  said no" with "the connection broke", and pushes an anticipated outcome through exception flow.
- The fire-and-forget alternative — `publishEvent()` returning `void` and the outcome being dropped — is
  worse: the relay's verdict is computed from the `OK` and then discarded, so the application can never
  learn whether its event was stored. That silently swallows the outcome.

A third tension is the return type itself. `publishEvent()` is asynchronous — the `OK` arrives later, on
the same socket — so the outcome cannot be a plain synchronous return without forcing every publish to
block. A reader expecting a synchronous `PublishResult` will read the `Future` wrapper as overengineering.

## Decision

A relay's accept/reject is an **anticipated outcome, returned as a value**; only a broken connection is a
**thrown fault**.

- `publishEvent()` returns a `Future<PublishResult>`. `PublishResult` mirrors the `OK` frame: `accepted`
  plus the relay's `message`. The future **completes** with `PublishResult::accepted(...)` or
  `PublishResult::rejected(...)` — a rejection is a completion, never an error.
- The future **errors** only on a genuine fault: the connection breaks mid-publish, or an internal
  retransmit step fails. Those are `ConnectionException`, caught by awaiting code.
- The future is returned, not held privately, so the caller chooses: `->await()` it for the verdict, or
  drop it for fire-and-forget. It is internally `ignore()`d so a dropped future never surfaces as an
  unhandled error.
- Under NIP-42 (ADR-0004), the single returned future stays pending across the auth dance and resolves
  once on the eventual outcome — `accepted` after the retried store, or `rejected` carrying
  `auth-required, auth rejected: …` if the relay declines the auth. The caller never sees the
  intermediate `auth-required`.

`Future` appears in the public contract deliberately: this is an AMPHP-based async client, callers
already drive it with Amp, and a per-publish handle is what lets many publishes be fired and their
individual verdicts awaited.

## Consequences

- A caller learns each event's fate: `$client->publishEvent($relay, $event)->await()->isAccepted()`, with
  the relay's reason in `getMessage()`. Rejections no longer have to be caught as exceptions, and are no
  longer silently lost.
- `try/catch` around an awaited publish catches only connection faults; relay rejections are handled by
  inspecting the returned `PublishResult`. Do not "restore" throwing on rejection — it re-conflates a
  policy "no" with a broken connection.
- `awaitPendingPublishes()` remains the batch drain for "wait until every in-flight publish for this
  relay is acknowledged" without inspecting each result; per-publish verdicts come from each returned
  future.
- A connection that drops while a publish is parked on auth resolves that publish's future with a
  `ConnectionException` rather than leaving it pending forever.
