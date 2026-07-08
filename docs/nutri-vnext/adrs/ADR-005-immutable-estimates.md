# ADR-005: Immutable and Traceable Estimates

Status: Accepted for Nutri vNext.

## Context

The legacy system passes calories as plain text between tools and persists only the resulting item calorie value. It does not persist formula, source, confidence, assumptions, reference version, or validation status as an immutable estimate.

## Decision

Every nutrition calculation creates a structured `NutritionEstimate`. Estimates preserve reference, version, quantity, source, formula, confidence, assumptions, and result.

Validated estimates are immutable. Registration accepts validated estimate identifiers, never free calorie values or text lines from the LLM. Persisted meal components preserve calculation snapshots.

## Consequences

The meal registration boundary must change from text lines with calories to validated estimate identifiers. Historical meals must remain explainable even after catalog updates.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: estimate identifier format and public/internal exposure.
- OPEN IMPLEMENTATION DECISION: snapshot JSON shape for persisted meal components.
