# 3. Respond to application-level `ping` NOTICEs with a throwaway `CLOSE`

## Status

Accepted

## Context

The Nostr protocol has no application-level ping frame. WebSocket has its own control-frame
ping/pong, and this client already runs a periodic WebSocket heartbeat. Some relays, however, do not
rely on the control frame: they send a relay `NOTICE` whose body is the literal text `ping` and treat
the connection as dead unless the client sends *some* frame back within a short window. A client that
only answers control-frame pings is silently dropped by those relays.

Handling that NOTICE reads like a smell on two counts, and a later reader is tempted to "correct" both:

- The reply is a `CLOSE` message for a subscription id (`keepalive`) that was never opened. Sending a
  `CLOSE` for a subscription the client never `REQ`-ed looks like a bug.
- Treating one specific NOTICE body as a control signal looks like leaking relay-specific behaviour
  into generic message handling, rather than forwarding every NOTICE to the registered handlers.

## Decision

When a relay NOTICE body is exactly `ping` (case-insensitive, trimmed), reply with a `CLOSE` for a
fixed throwaway subscription id and do **not** forward that NOTICE to the application handlers.

- A `CLOSE` is the cheapest well-formed client frame: it is a fixed two-element array, needs no signing
  and no event, and a relay ignores a `CLOSE` for an unknown subscription. Any frame proves liveness;
  `CLOSE` is the smallest one that is unambiguously valid and side-effect-free.
- The `ping` NOTICE is a transport keep-alive, not application content, so forwarding it to
  `handleNotice` would surface protocol plumbing the consumer cannot act on.

## Consequences

- Connections to relays that use NOTICE-based liveness checks stay open without any application code.
- The `CLOSE`-for-an-unopened-subscription is intentional. It is pinned by a Chesterton's-fence comment
  at the call site pointing here; do not "fix" it into a real subscription teardown or a NOTICE reply.
- A genuine relay NOTICE whose body happens to be the word `ping` is consumed rather than delivered to
  handlers. This collision is accepted: a bare `ping` carries no application meaning, and liveness is
  the only sensible interpretation.
