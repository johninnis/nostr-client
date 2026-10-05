# 18. A subscription that can match nothing ends at once and is never sent

## Status

Accepted

## Context

NIP-01 says a filter's lists "are JSON arrays with one or more values" and that "an event matches a filter if `since <= created_at <= until` holds". A caller can still hand the client a filter with an empty `authors` list (a follow list with nobody in it) or crossed bounds. `innis/nostr-core` parses and builds such a filter as it is, and gives the client `FilterCollection::matchable()` to decide whether there is anything worth sending (its ADR-0094).

Sending the filter anyway would let a relay that skips an empty list stream its whole store to a subscription meant to select nothing. Sending it costs a round trip for an answer the client already knows.

## Decision

- `AmphpRelayConnection::subscribe` answers a subscription none of whose filters can match with `handleEose` on its handler and returns. It sends no `REQ`, records no subscription, and does not look at the connection.
- A subscription with some filters that can match sends and records only those.
- This is the answer @innis/nostr-relay-pool gives the same subscription.

## Consequences

- A caller learns at once that there is nothing stored for such a subscription, and nothing live will arrive.
- A relay never sees a request for nothing from this client.
- Do not send the filter to "let the relay decide", and do not report it as a closed or failed subscription: nothing went wrong.
