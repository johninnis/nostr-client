# 10. Register the optional auth and reconnection handlers after construction, not through the constructor

## Status

Accepted

## Context

The client needs two optional collaborators from its host: an `AuthChallengeHandlerInterface` that signs
a NIP-42 challenge, and a `ReconnectionListenerInterface` that is told when a dropped connection has been
restored so the application can re-subscribe or re-authenticate. Both are registered through setters —
`setAuthHandler()` and `setReconnectionListener()` — that assign nullable instance fields.

Constructor injection is the house default, so a setter that mutates a nullable instance-held collaborator
reads like a violation: temporal coupling (a caller must remember to register before the handler is
needed) and shared mutable state. A reviewer is tempted to "fix" it by moving both into constructor
parameters.

Two forces make constructor injection the wrong tool here:

- **Both are genuinely optional.** A client that publishes only to open relays needs no auth handler; a
  client with auto-reconnect disabled needs no reconnection listener. Threading them through the
  constructor forces every caller to pass nulls for capabilities it does not use.
- **The reconnection listener observes the very client it depends on.** Its work on `onReconnected` is to
  drive that client again — re-subscribe, re-authenticate — so it needs a reference to the client, which
  cannot exist until the client is constructed. Constructor-injecting it is a chicken-and-egg; late
  registration is the only order that works.

The client is built by `NostrClientFactory::create()`, a zero-argument factory returning a ready client;
handlers are then registered on that client. This is the Observer registration pattern — attaching a
listener to an already-built subject — not service-dependency injection.

## Decision

The auth and reconnection handlers are registered after construction through `setAuthHandler()` /
`setReconnectionListener()`, held as nullable instance fields, and consulted only when present. They are
the two optional observer hooks. Every non-optional collaborator — the connection factory, the message
deserialiser, the logger — is constructor-injected as usual.

## Consequences

- A host wires handlers on the built client (`$client->setAuthHandler(...)`); no handler is required to
  publish or subscribe.
- The two nullable handler fields and their setters are the sanctioned exception to constructor injection
  in this package. Do not "fix" them into constructor parameters: that forces null-passing for unused
  capabilities and cannot express a reconnection listener that observes the client it is registered on.
- Only these two optional observer hooks use post-construction registration. A new *mandatory*
  collaborator is still constructor-injected.
