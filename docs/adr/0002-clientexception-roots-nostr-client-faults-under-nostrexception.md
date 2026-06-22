# 2. `ClientException` roots nostr-client faults under `NostrException`

## Status

Accepted

## Context

nostr-client throws faults of its own: a connection that breaks mid-operation, an
infrastructure failure talking to a relay. PHP gives those faults a base, and the choice of base
reads like a smell: `ClientException` (abstract) extends `NostrException`, which is defined in a
*different* package, `innis/nostr-core`. A reader sees one package's exception rooted in another
package's base and is tempted to "fix" it — either by giving nostr-client its own disconnected root,
or by reading the dependency edge as the thing that decides the hierarchy.

The mirror-image temptation lives one boundary further out: a consumer **application** that depends on
nostr-client. Because it depends on the Nostr libraries, rooting its own faults under `NostrException`
too looks natural, so the whole process shares one throwable root. That is the case this record rejects
alongside the first.

## Decision

Faults are rooted by **whose code raises them, not by the dependency graph** — and the line is drawn
between *Nostr library code* and a *consumer application*.

- nostr-client is **Nostr library code**, so its faults root at `NostrException`, the shared ecosystem
  base defined in nostr-core. `ClientException` (abstract) extends `NostrException`; `ConnectionException`
  (final) extends `ClientException`. nostr-client defines its own abstract base because it throws
  package-specific faults.
- A **consumer application** that depends on nostr-client roots its OWN faults at its OWN independent
  base, never under `NostrException`. Hubstr, for instance, throws a `HubstrException` that extends
  `\Exception` directly and does NOT extend `NostrException`, even though it depends on the Nostr
  libraries.
- What decides the root is the authoring code — Nostr library vs consumer application — not what it
  imports. Depending on nostr-core does not pull nostr-client's exceptions anywhere other than
  `NostrException`, because nostr-client is itself a Nostr library; and it does not pull a consumer
  application's exceptions under `NostrException` at all.

## Consequences

- A `catch (NostrException)` catches faults from any `nostr-*` library across the process, nostr-client
  included, and never an application-originated fault; the root identifies the origin.
- `ClientException` is abstract; `ConnectionException` is `final`. A future nostr-client fault extends
  `ClientException`.
- Do not give nostr-client its own disconnected root to "decouple it from nostr-core", and do not root a
  consumer application's exceptions under `NostrException` to "share one root" — the authoring code
  (library vs application) decides, not the dependency direction.
