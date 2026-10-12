# 19. A subscription is one request value object, and the dispatcher delegates to a handler collection

## Status

Accepted

## Context

`innis/coding-standards` 0.2.1 flags any method beyond three parameters as a design signal, with no exemptions. Three shapes in the client tripped it, and each had previously been excused by a fence comment rather than decomposed.

`subscribe` and `subscribeMultiple` carried four parameters — relay, filter or filters, handler, optional subscription id — on `NostrClientInterface`, `MultiRelayNostrClient`, `ConnectionHandlerInterface` and `AmphpRelayConnection`, on the reasoning that the handler is a collaborator, not data, so no cohesive value object could be extracted. That reasoning looked at the wrong half of the argument list: the handler does not belong in a value object, but the relay, the filters and the correlation id are exactly a REQ addressed to a relay, and every consumer of the library was already re-storing that tuple by hand — hubstr-signer's resubscription registry kept it as an untyped array shape so it could reassemble the same four arguments on reconnect.

`InboundMessageDispatcher` took its six per-message handlers as six constructor parameters. And `ConnectionException` carried the `\Exception` `$code` that no caller ever sets.

## Decision

- A `SubscriptionRequest` value object carries the relay, the `FilterCollection` and the optional subscription id. `subscribe(SubscriptionRequest, EventHandlerInterface)` replaces both `subscribe` and `subscribeMultiple` on every interface and implementation; a single filter is a collection of one via `SubscriptionRequest::for()`. The id stays optional: the client assigns one when the caller does not, and `withFilters()`/`withSubscriptionId()` keep replays immutable.
- The six inbound handlers implement `InboundMessageHandlerInterface` — each names the message class it handles — and are handed to the dispatcher as one variadic `InboundMessageHandlers` collection, mirroring innis/nostr-relay's verb handlers (its ADR-0026). The dispatcher resolves the handler for a parsed message and stays the fault boundary.
- `ConnectionException` drops `$code`; the parent receives zero.

## Consequences

- Consumers stop re-inventing the subscription tuple: a host that must replay a subscription stores the `SubscriptionRequest` it was given and hands it back, typed, instead of an array shape with a fence comment.
- `unsubscribe(RelayUrl, SubscriptionId)` keeps its two parameters: the pair is under the limit and a handle object would add a type without adding meaning.
- Adding a new inbound message type means adding a handler to the collection, not a parameter to a constructor.
- The fence comments these shapes carried are deleted; the shapes no longer need defending.
