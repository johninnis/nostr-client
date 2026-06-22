# 7. Offer two distinct health-check surfaces: over live connections and connectionless

## Status

Accepted

## Context

"Is this relay healthy?" has two genuinely different meanings, and a single health check cannot answer
both. A reviewer seeing two health-check code paths — `ConnectionManager::healthCheck()` and the
standalone `WebSocketHealthChecker` reached through a separate factory method — is tempted to read one
as a duplicate of the other and merge them.

- During a session the question is *"are the connections I already hold still alive, and how fast?"*
  Answering that by opening a fresh socket would measure the wrong thing (a brand-new connection, not
  the live one) and would churn connections the client is actively using.
- Before or without a session the question is *"can I reach this relay at all?"* — for example to pick
  relays from a candidate list, or to probe one the client is not connected to. Answering that needs a
  connection the client does not yet have, and the probe must not leave a connection behind.

## Decision

The two questions are served by two surfaces with different lifecycles.

- **Over live connections.** `NostrClientInterface::healthCheck()` pings every relay the client is
  currently connected to, concurrently, and reports per-relay latency or failure. It reuses the
  existing socket and measures the real connection. It checks only relays already connected — it never
  opens one.
- **Connectionless.** `RelayHealthCheckerInterface` (implemented by `WebSocketHealthChecker`, built via
  `NostrClientFactory::createHealthChecker()`) opens a fresh WebSocket to a single relay under a
  timeout, records the round-trip, and closes it immediately. It needs no `NostrClientInterface` and
  holds no long-lived state, so it can probe a relay the client has no relationship with.

The connectionless checker is a separate object reached by a separate factory method precisely because
its lifecycle is different: it is constructed and used without a `ConnectionManager`, and it owns no
connections.

## Consequences

- A connected application calls `healthCheck()` to monitor its live relays without disturbing them; an
  application choosing relays calls the standalone checker to probe candidates without committing to a
  connection.
- The two are not duplicates and must not be merged. Routing the live-connection check through the
  standalone checker would measure a throwaway socket instead of the real one and churn live
  connections; routing the probe through `healthCheck()` is impossible, as there is no connection to
  ping.
- The standalone checker always leaves the relay in the state it found it: it opens, measures, and
  closes. Its result is a `HealthCheckResult` value, matching the per-relay results returned by
  `healthCheck()`.
