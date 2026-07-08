# ADR-006: Validation, Quarantine, and Correction

Status: Accepted for Nutri vNext.

## Context

The legacy guardrail validates basic meal type, item count, positive calories, and consumed time. It does not validate structured provenance, compatibility, plausibility, or correction versioning.

## Decision

Every estimate passes structural, provenance, compatibility, plausibility, and operational validation. Relevant states include:

- `requires_clarification`
- `requires_confirmation`
- `validated`
- `quarantined`
- `rejected`

Corrections are real versioned domain operations. A corrected estimate supersedes the previous estimate. The agent may only say that a correction succeeded after the workflow confirms persistence.

Incorrect values must remain local and must never become reusable nutrition knowledge.

## Consequences

Correction, removal, and deletion flows require domain operations with audit events. Validation failure must stop registration or quarantine the estimate instead of relying on conversational phrasing.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: full validation rule thresholds and quarantine reasons.
- OPEN IMPLEMENTATION DECISION: user-facing copy for quarantine and correction states.
