# 12. The multi-relay client and its interface are an Application service, not Infrastructure

## Status

Accepted

## Context

`MultiRelayNostrClient` is the concrete client the package hands its consumer: it implements
`NostrClientInterface`, the driving contract a host calls to connect, subscribe, publish, and check
health. What it actually does is orchestration — it de-duplicates concurrent connects to the same
relay, guards every operation behind a connected check, generates subscription ids, fans a health
probe across every live relay, and closes them all on shutdown. It holds no socket and performs no
handshake: it delegates all transport to a `ConnectionHandlerInterface` it never looks past. The real
per-relay live state — the session registry, the generation counters, the reconnect timers — lives in
the Infrastructure adapter behind that port (`AmphpRelayConnection`), not here.

Its placement reads like a question a reviewer will want to "correct". The class was originally filed
in `Infrastructure/` beside the transport it drives, which invites two opposite mistakes: leaving a
pure orchestrator among the socket-level classes, or concluding that anything touching the async
runtime must be Infrastructure and so it belongs there. The one genuinely infrastructural-looking thing
it does is call the async runtime directly — `async()` to run per-relay work concurrently, `awaitAll()`
to gather it — rather than routing that concurrency through an injected port the way it routes
transport.

## Decision

The client is an **application service**. `NostrClientInterface` (the driving contract) and its
implementation `MultiRelayNostrClient` both live in `Application/Service/`. The transport it drives,
`ConnectionHandlerInterface`, is a **driven port** whose implementation, `AmphpRelayConnection`, stays
in `Infrastructure/`. The line is the direction of control: the client is driven *by* the host and
drives the transport; the transport is driven *by* the client and reaches the outside world.

The async runtime is this package's **sanctioned concurrency substrate, not an external concern to hide
behind a port.** The public contract already returns `Future<PublishResult>` (see ADR-0009), so the
async library is a first-class part of the application boundary, not a detail leaking up from
infrastructure. Calling `async()`/`awaitAll()` to coordinate concurrent relay work is orchestration
expressed in that substrate — the same commitment as returning a `Future` — so it neither pushes the
class into Infrastructure nor warrants a bespoke concurrency/executor port. Time, randomness, and the
network still sit behind ports; the concurrency substrate the contract is already written in does not.

## Consequences

- Layer membership follows the direction of control, not the dependency list: a driving orchestrator is
  an Application service even though it coordinates concurrency, and a driven adapter is Infrastructure
  even though the port it implements is declared in Application. Do not move the client back into
  Infrastructure because it uses the async runtime — using the sanctioned substrate is not "direct
  infrastructure".
- No concurrency/executor port is introduced. Inventing one to "purify" the `async()`/`awaitAll()`
  calls would wrap a substrate the package has already committed to in empty scaffolding, and would buy
  the analyser and the tests nothing they do not already have.
- The connection registry stays in the Infrastructure adapter; the Application service owns only
  cross-relay coordination (connect de-duplication, connected-guards, subscription-id generation, the
  health fan-out, close-all). A new piece of per-relay live state is added to the adapter, a new piece
  of cross-relay policy to the service.
- `NostrClientInterface` lives in `Application/Service/`, beside the class reached through it — not in
  `Application/Port/`, which is for driven ports the app needs from the outside world. Filing a driving
  contract as a port would misrecord which side drives which.
