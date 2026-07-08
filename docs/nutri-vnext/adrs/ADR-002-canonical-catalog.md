# ADR-002: Canonical Nutrition Catalog

Status: Accepted for Nutri vNext.

## Context

The legacy system can reuse historical meal items as nutrition references. That allows test or incorrect historical values to influence later calculations.

## Decision

Reusable nutrition values come only from a curated, persisted, versioned catalog. Meal history is never a nutrition reference. Historical meal values must never override deterministic catalog references.

Semantic search may suggest catalog candidates in the future, but it cannot determine calories.

## Consequences

History and embeddings may support discovery, recall, or candidate suggestion only when a deterministic resolver still selects an approved catalog reference. Meal events must not feed official nutrition calculations.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: catalog editorial workflow and review UI.
- OPEN IMPLEMENTATION DECISION: initial catalog import source and version identifiers.
- OPEN IMPLEMENTATION DECISION: whether semantic candidate search is implemented in the first catalog phase or deferred.
