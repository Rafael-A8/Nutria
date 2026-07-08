# ADR-010: Persistence, Versioning, and Audit

Status: Accepted for Nutri vNext.

## Context

The legacy persistence model stores visible chat messages, agent conversation messages, flat meals, flat meal items, user memories, and conversation summaries. It does not persist canonical entries, components, estimates, catalog versions, pending nutrition operations, templates, or audit events.

## Decision

The target model conceptually includes:

- `meals`
- `meal_entries`
- `meal_components`
- `nutrition_estimates`
- `food_references`
- `food_reference_versions`
- `food_aliases`
- `food_portions`
- `food_sources`
- `user_food_references`
- `nutrition_label_evidences`
- `pending_nutrition_operations`
- `meal_templates`
- `meal_template_versions`
- `meal_template_components`
- `nutrition_audit_events`

Persistence principles:

- preserve what the user reported;
- preserve how the system interpreted and calculated it;
- preserve what was ultimately persisted;
- estimates are immutable;
- corrections create new versions;
- old meals retain snapshots;
- official totals include only active validated components;
- writes are transactional and idempotent;
- conversation messages, domain state, catalog knowledge, and audit history remain separate.

## Consequences

The migration must introduce new persistence without editing historical production migrations. Legacy test data may be discarded later, but production infrastructure still requires forward-safe changes.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: physical table order and migration chunking.
- OPEN IMPLEMENTATION DECISION: read compatibility layer for existing meal summaries.
- OPEN IMPLEMENTATION DECISION: audit event payload schema.
