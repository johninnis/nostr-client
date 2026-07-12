# 10. Register optional observer hooks after construction, not through the constructor

## Status

Accepted

## Context

The client accepts optional hooks from its host — collaborators it does not need in order to publish or
subscribe, that the host may or may not supply. Each is registered through a `set*()` setter that assigns
a nullable instance field and is consulted only when present. Examples in the client today:

- `setAuthHandler()` — an `AuthChallengeHandlerInterface` that signs a NIP-42 challenge;
- `setReconnectionListener()` — a `ReconnectionListenerInterface` told when a dropped connection is
  restored, so the application can re-subscribe or re-authenticate;
- `setAuthResultListener()` — an `AuthResultListenerInterface` told the relay's verdict on a NIP-42 auth
  event, with the relay's message.

Constructor injection is the house default, so a setter that mutates a nullable instance-held collaborator
reads like a violation: temporal coupling (a caller must register before the hook is needed) and shared
mutable state. A reviewer is tempted to "fix" these into constructor parameters.

Two forces make constructor injection the wrong tool for this class of collaborator:

- **They are genuinely optional.** A client that publishes only to open relays needs no auth handler and
  no auth-result listener; a client with auto-reconnect disabled needs no reconnection listener. Threading
  optional hooks through the constructor forces every caller to pass nulls for capabilities it does not
  use.
- **A hook may observe the very client it depends on.** The reconnection listener's work on `onReconnected`
  is to drive that client again — re-subscribe, re-authenticate — so it needs a reference to the client,
  which cannot exist until the client is constructed. Constructor-injecting it is a chicken-and-egg; late
  registration is the only order that works. (This force applies to hooks that call back into the client;
  the others are pure notifications the host may ignore.)

The client is built by `NostrClientFactory::create()`, a zero-argument factory returning a ready client;
hooks are then registered on it. This is the Observer registration pattern — attaching a listener to an
already-built subject — not service-dependency injection. Each hook is its own single-method interface
rather than one combined "client observer", so a host implements only the notification it cares about.

## Decision

An **optional hook** — a collaborator the client does not need to publish or subscribe, that the host may
or may not supply — is registered after construction through a `set*()` setter, held as a nullable
instance field, and consulted only when present. Every **mandatory** collaborator — the connection
factory, the message deserialiser, the logger — is constructor-injected as usual.

The setters listed in the Context are the hooks that exist today; a further optional hook is added the
same way, and this record governs it without restating the list.

## Consequences

- A host wires only the hooks it wants on the built client (`$client->setAuthResultListener(...)`); none is
  required to publish or subscribe.
- The nullable hook fields and their setters are the sanctioned exception to constructor injection in this
  package. Do not "fix" them into constructor parameters: that forces null-passing for unused capabilities
  and cannot express a hook that observes the client it is registered on.
- A new optional hook follows the established shape — its own single-method `*ListenerInterface` (or
  handler), a `set*()` setter, a nullable field consulted when present — and needs **no new ADR**, because
  this record states the pattern by category rather than by enumerating the instances. Adding an example to
  the Context above is a clarification, not a new decision.
- A new *mandatory* collaborator is still constructor-injected; the exception is for optional hooks only.
