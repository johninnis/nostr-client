# 11. Dispatch inbound frames on a single ordered fiber, bounded, so a slow handler cannot stall the reader

## Status

Accepted

## Context

Each live connection runs one read loop that iterates the WebSocket and dispatches every frame the
relay sends. The obvious shape handles each frame inline, on the read loop's own fiber:

```
foreach ($websocket as $message) {
    $this->handleMessage($relayUrl, $message->buffer());
}
```

That shape has a failure mode invisible at the call site. The transport answers the periodic WebSocket
heartbeat with control-frame pongs, and those pongs are parsed only as the read loop advances — they are
not delivered as application messages. Concretely, the underlying client publishes assembled messages
through a **zero-buffer queue**: when a data frame completes, the internal read fiber suspends until the
consumer takes that message. An application `handleEvent`/`handleNotice` callback is host code that may
be slow or may suspend on its own I/O. Handling a frame inline runs that callback on the read loop's
fiber, so while it works the consumer does not take the next message, the internal read fiber stays
suspended, no further frames are parsed, and the queued pongs go unread. The heartbeat then crosses its
unanswered-ping limit and the transport closes a healthy connection — a connection killed by its own
consumer's latency. So handler work cannot run on the read loop.

The obvious first fix — spawn a detached fiber per frame — keeps the reader draining but reads like a
smell and earns two real problems. Detached fibers lose ordering: a handler that suspends yields to a
later frame's fiber, so an `EVENT` can be delivered after the `EOSE` that followed it. And they are
unbounded: a relay that floods frames spawns a fiber each, with no ceiling.

## Decision

Inbound frames are drained by **one long-lived dispatch fiber over a bounded internal queue.** The read
loop's only job is to move each frame into that queue and continue, so it keeps taking messages from the
transport and the heartbeat stays answered. The single dispatch fiber pulls from the queue and runs
`handleMessage` **sequentially, in receipt order**, so a slow or suspending handler blocks only that
fiber — never the reader, and never the ordering of the frames behind it.

The queue is bounded by a high-water mark (`MAX_INBOUND_BACKLOG`). Each frame is offered without
blocking the reader; an offer that would push the backlog past the mark signals that the consumer has
fallen that far behind, and the read loop **fails the connection** with a `ConnectionException` rather
than growing memory without bound or stalling the reader. That failure travels the connection's ordinary
error path — subscribers are notified, in-flight publishes error, and auto-reconnect (if enabled) starts
a fresh connection with a fresh queue.

## Consequences

- Frames are delivered to handlers strictly in receipt order, regardless of whether a handler suspends.
  A consumer no longer has to defend against out-of-order delivery.
- A slow or suspending handler cannot stall the read loop, so the transport does not close a healthy
  connection because a consumer callback was slow. Do not "simplify" the dispatch back to an inline
  `handleMessage` call — that reintroduces the handler-latency-closes-the-connection failure.
- Memory is bounded: at most `MAX_INBOUND_BACKLOG` frames are buffered before a hopelessly-behind
  consumer fails its connection explicitly, instead of the silent, unbounded fiber growth a per-frame
  dispatch allowed. Do not remove the bound or replace the single fiber with a fiber-per-frame — that
  gives back both the ordering guarantee and the ceiling.
- The overflow is a deliberate, observable failure, not a dropped frame: nothing is silently discarded;
  the connection ends and (under auto-reconnect) is rebuilt. A consumer that legitimately needs to
  absorb large bursts raises the mark or drains faster, rather than the client quietly buffering forever.
