# ADR-009: Deterministic and Non-Blocking Workflows

Status: Accepted for Nutri vNext.

## Context

The legacy flow relies on prompt instructions for parse, estimate, similar-history lookup, registration, and clarification sequencing. Pending questions are conversational, not first-class persisted operations.

## Decision

Required workflows include, conceptually:

- `RegisterMealWorkflow`
- `ProvideClarificationWorkflow`
- `ConfirmAssumptionWorkflow`
- `CorrectMealWorkflow`
- `RemoveMealComponentWorkflow`
- `DeleteMealWorkflow`
- `RepeatMealWorkflow`
- `SaveMealTemplateWorkflow`
- `UseMealTemplateWorkflow`
- `UpdateMealTemplateWorkflow`
- `QueryMealHistoryWorkflow`
- `ExplainMealCalculationWorkflow`
- `RegisterLabelEvidenceWorkflow`

Every write operation has an idempotent operation identifier. Pending questions are persisted and resumed by explicit identifiers. A pending meal or component never blocks the whole conversation.

One user message may produce multiple independent operations. Valid parts may complete while ambiguous parts remain pending. The user may postpone, cancel, accept an explicit estimate, or register only what is clear.

The conversational experience must remain fluid, empathetic, and humanized rather than behaving like a rigid form.

## Consequences

Prompt-only sequencing must be replaced by deterministic workflow state. Partial completion and pending operation state must be explicit.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: operation identifier generation and idempotency storage.
- OPEN IMPLEMENTATION DECISION: UX for multiple simultaneous pending questions.
- OPEN IMPLEMENTATION DECISION: cancellation and postponement semantics.
