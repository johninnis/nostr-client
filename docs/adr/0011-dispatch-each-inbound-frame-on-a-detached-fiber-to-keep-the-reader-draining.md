# 11. Dispatch each inbound relay frame on a detached fiber so the reader keeps draining

## Status

Accepted

## Context

Each live connection runs one read loop that iterates the WebSocket and dispatches every frame the
relay sends. The obvious shape handles each frame inline:

```
foreach ($websocket as $message) {
    $this->handleMessage($relayUrl, $message->buffer());
}
```

That shape reads as the simplest and most obviously-ordered one, and a later reader is tempted to
"simplify" the detached-fiber version back to it. It has a failure mode that is not visible at the call
site.

The transport answers the periodic WebSocket heartbeat with control-frame pongs, and those pongs are
parsed only as the read loop advances the iterator — they are not delivered as application messages.
The heartbeat runs on its own timer: it sends a ping every period and closes the connection once the
number of *unanswered* pings exceeds a small limit. An application `handleEvent`/`handleNotice` callback
is host code that may be slow or may suspend on its own I/O. Handling a frame inline runs that callback
on the read loop's fiber, so while it is working the iterator does not advance, no further frames are
parsed, and the queued pongs are not read. A handler that takes longer than a couple of heartbeat
periods therefore lets the unanswered-ping count cross its limit, and the transport closes a healthy
connection out from under the application — a connection killed by its own consumer's latency.

## Decision

The read loop buffers each frame and dispatches it on a detached fiber, then immediately continues to
the next iteration:

```
foreach ($websocket as $message) {
    $payload = $message->buffer();
    async(fn () => $this->handleMessage($relayUrl, $payload))->ignore();
}
```

The loop's single job is to keep draining the socket — buffering frames and advancing the iterator so
control frames (pongs) are parsed and the heartbeat stays answered — never to run handler code. Handler
work happens off the loop, on its own fiber, so a slow or suspending handler cannot stall the reader or
trip the heartbeat's unanswered-ping limit.

The dispatched fiber is detached (`ignore()`d) because `handleMessage` contains its own error handling:
it catches and logs its failures, so nothing meaningful can surface from the fiber to await.

## Consequences

- A slow or suspending application handler no longer stalls the read loop, so the transport does not
  close a healthy connection because a consumer callback was slow. This is the reason the detached fiber
  exists: do not "simplify" the dispatch back to an inline `handleMessage` call — that reintroduces the
  handler-latency-closes-the-connection failure.
- Frames are dispatched in receipt order and each `handleMessage` runs to completion without suspending
  for the common case of a synchronous handler, so ordering is preserved there. A handler that itself
  suspends yields to the next frame's fiber, so ordering across frames is best-effort, not guaranteed:
  a handler that must observe frames strictly in order has to order them itself. This trade — reader
  liveness over a strict cross-frame ordering guarantee — is deliberate.
- Dispatch is unbounded: a flood of frames spawns a fiber each. The relay-side rate limit on the
  connection (bytes and frames per second) bounds the inflow, so the fiber count is bounded in practice
  by that limit rather than left to grow without any ceiling.
