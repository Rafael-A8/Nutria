# Nutri vNext Master Test Matrix

Status: master invariant map for future implementation phases.

## Permanent Regression

Input:

```text
120g de tilápia grelhada na manteiga
```

Forbidden result:

```text
manteiga = 120g
```

Forbidden calculation:

```text
120 * 717 / 100 = 860 kcal
```

Expected domain interpretation:

- tilapia: primary food, 120g;
- grilled: preparation;
- butter: cooking fat, unknown quantity.

Minimum permanent assertions:

- interpretation returns tilapia as the primary food component with 120g;
- interpretation does not assign 120g to butter;
- butter is represented as cooking fat/preparation with unknown quantity;
- estimation either requires clarification for butter or uses only an explicitly confirmed butter quantity;
- no persisted result contains the forbidden butter calculation.

## Matrix

| Category | Invariant | First phase | Notes |
|---|---|---:|---|
| Catalog | Only active reviewed catalog versions feed official calculations. | M2 | Static config may remain only until replaced. |
| Catalog | Meal history is never a nutrition reference. | M0.2 | Must be contained before vNext catalog exists. |
| Interpretation | LLM output is an `InterpretationDraft` without calories. | M1 | Main and fallback parsers share one contract. |
| Interpretation | Preparation methods do not compete with food references. | M1 | Covered by tilapia/butter regression. |
| Reference resolution | Generic aliases require clarification or an explicit generic reference. | M2 | Removes longest-alias heuristic as authority. |
| Reference resolution | Semantic search may suggest candidates but cannot determine calories. | M2 | M0.2 disables meal-item embedding authority. |
| Quantities | Every quantity belongs to a component or total entry. | M1 | Total entry quantities cannot be silently distributed. |
| Quantities | Vague quantities are never silently converted. | M1 | Unknown cooking-fat quantity requires clarification or explicit assumption workflow. |
| Deterministic calculation | Formula uses resolved catalog reference version and component quantity. | M3 | Historical values cannot override catalog values. |
| Provenance | Estimate stores reference, version, source, formula, confidence, assumptions, and result. | M3 | Must be queryable for explanations. |
| Validation | Estimates pass structural, provenance, compatibility, plausibility, and operational validation. | M3 | Basic legacy guardrail is insufficient. |
| Quarantine | Failed plausibility/provenance becomes quarantined or rejected, not registered as truth. | M3 | User-facing copy remains an open implementation decision. |
| Registration | Registration accepts validated estimate identifiers only. | M3 | Free calorie text from tools is forbidden. |
| Correction | Corrections supersede prior estimates through versioned domain operations. | M4 | Agent confirms only after persistence succeeds. |
| Repetition | Repeated meals use exact source meal or explicit template. | M4 | No fuzzy historical calorie reuse. |
| Templates | Templates are versioned and contain components. | M5 | Templates model recurrence, not history search. |
| Conversational non-blocking behavior | Valid components can complete while ambiguous components remain pending. | M4 | Pending questions are persisted by identifiers. |
| RAG isolation | RAG locates records/candidates only; it has no nutritional authority. | M0.2, M2 | M0.2 containment disables meal-item embedding decisions. |
| Security | Prompt injection guardrails do not bypass deterministic workflows. | M3 | Existing guardrails can remain as safety layer. |
| Auditability | Nutrition writes, corrections, validations, and catalog uses emit audit events. | M5 | Audit event schema is open. |
| Idempotency | Every write operation has an operation identifier. | M4 | Prevents duplicate registration from retries. |
| Transactions | Writes persist domain state, estimates, components, and audit atomically. | M3 | Registration and correction require transaction tests. |

## Current Test Gaps

- No permanent test currently covers `120g de tilápia grelhada na manteiga`.
- Current tests allow historical calorie scaling and must change during M0.2.
- Current tests assert meal-item embeddings are generated on item persistence; M0.2 should stop using those embeddings for nutrition decisions without necessarily deleting the column yet.
- Current registration tests allow plain text calorie lines; M3 must replace these with validated estimate identifiers.
