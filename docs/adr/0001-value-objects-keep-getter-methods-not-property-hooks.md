# 1. Value objects expose state through `getX()` accessors, not public properties or hooks

## Status

Accepted

## Context

PHP 8.4 makes it idiomatic to drop an accessor and expose state directly: a `public readonly` property for a plain carrier, or a property hook for a computed or write-controlled one.

This package's types are a mix: plain carriers (`HealthCheckResult`, `ParkedPublish`), a configuration value object (`ConnectionConfig`), an immutable entity (`RelayConnection`), and one mutable holder for a relay's live connection state (`RelaySession`). Some of their reads are plain stored fields — exactly what tempts a reviewer to make them `public readonly` properties — but others are computed: `RelayConnection::isHealthy()` derives from the connection state, `ConnectionConfig::baseBackoffMs()` from the configured delays. Every one of them exposes state through `getX()`, and this record is why that surface is kept uniform rather than split.

## Decision

Every value object, DTO, entity, and internal holder exposes its state through `getX()` (or `toX()`) accessors over private properties. No public readonly state, no property hooks, no asymmetric visibility (`public private(set)`). The read surface is uniform across every class the package defines — the mutable `RelaySession` holder included, not only the value objects on the public boundary.

The decision keeps one stable, uniform read surface:

1. **A `getX()` accessor is a stable seam; a public property is not.** Binding a read to an interface, normalising its representation, or computing it on read is a non-event behind `getX()` — no call site changes — and a breaking change to every reader behind a public property. That is not hypothetical even here: `isHealthy()` is already a derived predicate, not a stored field, and sibling packages' value objects carry identity-bound (`equals()`) and normalised (bech32/hex) reads too. The accessor is the seam that keeps such reads, present and future, from fracturing call sites.

2. **One access style, with no split surface.** A computed read like `isHealthy()` or `baseBackoffMs()` can only ever be a method — and a `final readonly class` cannot carry a property hook at all, since a hook needs a non-readonly property. Exposing the *plain* carriers as public properties would therefore leave a bare `$vo->size` beside a `$vo->isHealthy()` call for every reader to navigate. Uniformity means every read is a method.

3. **The conversion is purely syntactic and buys nothing.** Replacing `getX()` with a property changes no behaviour and gives the analyser nothing it did not already have. It trades a uniform surface for a split one and rewrites call sites for no gain.

Collections differ in *shape*, not in principle: a typed collection exposes the iterable and countable surface (`IteratorAggregate`/`Countable`) plus `toArray()`, rather than a `getItems()` accessor.

## Consequences

- The read surface is uniformly `getX()`/`toX()` across the value objects, the entity, and the holders. The immutable types (`ConnectionConfig`, `HealthCheckResult`, `RelayConnection`, `ParkedPublish`, the collections) carry no setters: they transform, where they transform at all, by returning a new instance. `RelaySession` is the one mutable holder — it bundles a relay's live, evolving connection state — and it mutates in place through named methods, but it still reads through `getX()` and exposes no public property.
- Entity lifecycle state stays behind a `getX()` method, changed through named transformations that return a new instance — never a publicly-readable `private(set)` property.
- Do not "modernise" a `getX()` accessor into a public property or a hook, and do not exempt the holders because some of their reads happen to be plain. It fractures the access style, and turns a future interface-binding or computed read from a non-event into a breaking change across every call site.
