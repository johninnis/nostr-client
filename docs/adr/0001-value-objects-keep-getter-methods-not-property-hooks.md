# 1. Value objects expose state through `getX()` accessors, not public properties or hooks

## Status

Accepted

## Context

PHP 8.4 makes it idiomatic to drop an accessor and expose state directly: a `public readonly` property for a plain carrier, or a property hook for a computed or write-controlled one.

Every immutable class this package defines is, today, a plain carrier. The domain value objects `ConnectionConfig` and `HealthCheckResult`, and the internal connection holders `ActiveWebSocket` and `ParkedPublish`, each return a stored field unchanged — none normalises, computes, or binds a read to an interface. That makes the temptation acute and specific: a reviewer reading this package sees nothing but `return $this->x;` getters and reasonably asks why they are not `public readonly` properties, which in 8.4 is the shorter, idiomatic form. This record is the answer.

## Decision

Every value object, DTO, and internal data holder exposes its state through `getX()` (or `toX()`) accessors over private properties. No public readonly state, no property hooks, no asymmetric visibility (`public private(set)`). The surface is uniform across every immutable class the package defines — the connection holders included, not only the value objects on the public boundary.

The decision is about keeping one stable, uniform surface, and it holds even though every read is currently a plain field:

1. **A `getX()` accessor is a stable seam; a public property is not.** Binding a read to an interface, normalising its representation, or computing it on read is a non-event behind `getX()` — no call site changes — and a breaking change to every reader behind a public property. That these moves are real, not hypothetical, is visible one package over: sibling packages' value objects already carry identity-bound (`equals()`), normalised (bech32/hex), and computed (derived-predicate) reads. This package's carriers being plain *today* is exactly why the seam is cheap to keep now and expensive to retrofit later.

2. **One access style, with no split surface.** A `final readonly class` cannot carry a property hook at all — a hook needs a non-readonly property — so a value that is ever computed or normalised on read can only be a method. Exposing the plain carriers as public properties now would, the first time one read has to become a method, leave a bare `$vo->size` beside a `$vo->getHash()` call for every reader to navigate. Uniformity therefore means every read is a method. The holders obey the same rule for the same reason: one style everywhere beats a boundary nobody remembers.

3. **The conversion is purely syntactic and buys nothing.** Replacing `getX()` with a property changes no behaviour and gives the analyser nothing it did not already have. It trades a uniform surface for a split one and rewrites call sites for no gain.

Collections differ in *shape*, not in principle: a typed collection exposes the iterable and countable surface (`IteratorAggregate`/`Countable`) plus `toArray()`, rather than a `getItems()` accessor.

## Consequences

- The surface is uniformly `getX()`/`toX()` across value objects, DTOs, and internal holders. Entity lifecycle state likewise stays behind a `getX()` method, mutated through named transformations that return a new instance — never a publicly-readable `private(set)` property.
- Setters do not arise: these types are immutable and transform, where they transform at all, by returning a new instance.
- Do not "modernise" a `getX()` accessor into a public property or a hook, and do not exempt the internal holders because their reads happen to be plain. It fractures the access style, and turns a future interface-binding or computed read from a non-event into a breaking change across every call site.
