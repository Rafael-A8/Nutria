# ADR-001: Canonical Nutrition Domain

Status: Accepted for Nutri vNext.

## Context

The legacy meal model stores meal items as flat text, quantity, calories, and embedding data. It does not preserve a separate user-reported entry, canonical component interpretation, preparation context, or calculation snapshot.

## Decision

The canonical domain is structured as:

- `Meal`
- `MealEntry`
- `MealComponent`

`MealEntry` preserves the user's original description. `MealComponent` is the canonical nutrition calculation unit. Preparation is represented separately from the food reference. Every quantity must explicitly belong to a component or to the total `MealEntry`. Compound descriptions may produce multiple components.

## Consequences

Registration and correction workflows must preserve separately:

- what the user reported;
- how the system interpreted it;
- which components were calculated and persisted.

Flat meal-item persistence is a legacy shape and must be adapted or replaced during migration.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: exact PHP model names, namespaces, casts, and relation method names.
- OPEN IMPLEMENTATION DECISION: whether `MealEntry` and `MealComponent` are introduced behind a compatibility read model during migration.
