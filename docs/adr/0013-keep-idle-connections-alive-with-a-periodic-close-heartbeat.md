# 13. Keep idle connections alive with a periodic CLOSE heartbeat

## Status

Accepted.

## Context

A relay commonly closes a connection that has been silent for too long — it reads the socket with an
idle timeout and drops a client that sends nothing within it. A subscriber that only *reads* (waits
for matching events and rarely publishes) is exactly such a client: it can sit silent for minutes,
long enough to be closed, then has to reconnect and re-subscribe, which on a storing relay replays a
backlog and on any relay churns the connection. The fix is for the client to send *something*
periodically.

Two things constrain what that something can be:

- A WebSocket ping control frame does not help. A relay's idle timer is armed on the next
  *application* message; the parser answers a ping with a pong internally and never surfaces it to the
  message loop, so a ping does not reset the timer.
- The heartbeat must not itself be throttled or leave state behind. A REQ counts against a
  subscription budget and opens a real subscription; an EVENT counts against a publish budget. Neither
  is acceptable for a bare keep-alive.

## Decision

Send a `CLOSE` for a throwaway subscription id (`keepalive`) at a configurable interval
(`heartbeatIntervalMs`, default 30000, `0` disables). A `CLOSE` is an application frame the relay
routes — resetting its idle timer — is never rate-limited, and un-subscribing an id that was never
opened is a no-op, so it leaves no state on either side.

The heartbeat runs as one background loop per connection, started in `connect()` and fenced by the
same per-relay generation counter as the message-handler loop: after each interval it stops if the
generation has moved on (a reconnect or disconnect happened) or the session is gone or unhealthy, and
a send failure ends it silently — the message loop, not the heartbeat, owns detecting and recovering
a dead connection.

## Consequences

- An idle read-only subscriber stays connected across a relay's idle timeout without reconnect churn,
  provided its interval is shorter than the relay's timeout. The default 30s clears the common
  values; a host on an unusually aggressive relay lowers it, and `0` turns it off entirely.
- The heartbeat is best-effort and deliberately does not trigger reconnection on a failed send; that
  responsibility stays with the message-handler loop, so there is one place that fails a connection.
- The throwaway id collides by design with the `CLOSE` the client sends in reply to an application
  `ping` NOTICE — both are keep-alives for a subscription that was never opened, and sharing the id
  keeps that intent in one place.
