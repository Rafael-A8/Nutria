# ADR-007: Interpretation and Reference Resolution

Status: Accepted for Nutri vNext.

## Context

The legacy parser and estimator combine interpretation, reference matching, quantity defaults, ambiguity handling, history lookup, and calorie calculation. The deterministic fallback uses alias containment and specificity heuristics.

## Decision

The LLM produces only an `InterpretationDraft`, without calories. A deterministic resolver maps components to catalog candidates.

The rule "longest alias wins" is removed. Preparation methods do not compete with food references. Generic aliases require clarification or an explicitly generic reference.

Quantities may be exact, household measures, sized units, vague descriptions, entry totals, or package fractions. Vague quantities are never silently converted. Main and fallback parsers must produce the same output contract.

## Consequences

Interpretation, reference resolution, quantity normalization, and calculation must become separate stages. Preparation must be represented as context, not as a competing food match.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: `InterpretationDraft` schema and validation rules.
- OPEN IMPLEMENTATION DECISION: deterministic resolver scoring and tie-breaking rules.
- OPEN IMPLEMENTATION DECISION: household measure catalog source.
