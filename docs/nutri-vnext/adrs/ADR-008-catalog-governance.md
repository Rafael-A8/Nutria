# ADR-008: Catalog Governance and Sources

Status: Accepted for Nutri vNext.

## Context

The legacy catalog is a static PHP config array. It has aliases and calorie values but no persisted source model, version review state, private user reference boundary, or image-label evidence workflow.

## Decision

The catalog separates:

- `FoodReference`
- `FoodReferenceVersion`
- `FoodAlias`
- `FoodPortion`
- `FoodSource`

Only active reviewed versions may feed official calculations. Catalog updates create new versions and do not overwrite historical snapshots.

User-provided labels, recipes, and product data remain private to that user. User-uploaded nutrition-label images are untrusted evidence by default. Image-derived data never enters the global catalog automatically. Global promotion requires explicit editorial review.

## Consequences

Official calculations must resolve to an active reviewed `FoodReferenceVersion`. Label evidence workflows must not mutate global catalog data without editorial review.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: editorial review roles and permissions.
- OPEN IMPLEMENTATION DECISION: source confidence taxonomy.
- OPEN IMPLEMENTATION DECISION: storage and retention policy for uploaded label images.
