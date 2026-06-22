# 2. Value objects keep `getX()` methods, not property hooks

## Status

Accepted

## Context

PHP 8.4 property hooks and asymmetric visibility (`public private(set)`) let a *property* carry the
computation and write-control that previously needed a `getX()` / `setX()` method, so the idiomatic 8.4
move is usually to expose a property and drop the accessor. Applied to this package's value objects
(`HealthCheckResult`, `ConnectionConfig`, `HealthCheckResultCollection`) and to the lifecycle state of
the `RelayConnection` entity, that would replace each `getX()` with a bare or hooked property.

## Decision

Value objects keep `getX()` methods. Property hooks and asymmetric visibility are not used to replace
getters.

1. **`readonly` forbids hooks.** A property hook requires a non-readonly property, and a
   `final readonly class` makes every property readonly — so inside these value objects a hook is not
   available at all. The language gives an immutable object exactly one tool for a read, and it is a
   method.
2. **A partial migration is worse than none.** A nullable, interface-shaped accessor such as
   `HealthCheckResult::getLatencyMs(): ?float` reads naturally as a method; converting only the trivial
   pass-throughs would split the public API into two access styles (a bare property next to a `getX()`
   call). A uniform `getX()` surface is the only internally consistent option.
3. **No behavioural or type-safety gain.** Getter-to-property is purely syntactic: it would rewrite call
   sites for no change in behaviour or analyser coverage.

## Consequences

- The value-object surface is uniformly `getX()`; the `RelayConnection` entity's lifecycle state likewise
  stays behind a `getX()` method, mutated through named transformations rather than a publicly-readable
  `private(set)` property.
- Setters do not arise. Value objects are immutable and transform by returning a new instance; the
  `RelayConnection` entity mutates only through named transformations.
- Do not "modernise" individual getters into property hooks — it is unavailable on readonly properties
  and would fracture the access style.
