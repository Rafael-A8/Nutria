# Nutri vNext Architecture Target

Status: architecture freeze for M0.1.

This document formalizes the approved Nutri vNext target. It does not approve implementation details beyond the decisions recorded here. Undecided implementation details are marked as `OPEN IMPLEMENTATION DECISION`.

## Scope

Nutri vNext separates language understanding, deterministic domain workflows, catalog governance, nutrition calculation, persistence, memory, history, and auditability.

The approved target corrects these legacy risk categories:

- prompt-controlled nutrition workflow sequencing;
- a general nutrition agent that owns too many responsibilities;
- loss of calorie provenance after registration;
- historical meal reuse as nutrition authority;
- semantic-search contamination of nutrition decisions;
- weak correction and quarantine flows;
- under-modeled nutrition concepts.

## Canonical Domain

The nutrition domain is:

- `Meal`
- `MealEntry`
- `MealComponent`

`MealEntry` preserves the user's original description. `MealComponent` is the canonical nutrition calculation unit. Preparation is represented separately. Every quantity explicitly belongs to a component or to the total `MealEntry`. Compound descriptions may produce multiple components.

OPEN IMPLEMENTATION DECISION: exact model names, namespaces, and compatibility layer for legacy reads.

## Interpretation And Resolution

The LLM may produce an `InterpretationDraft` only. It does not produce calories.

A deterministic resolver maps components to catalog candidates. Preparation methods do not compete with food references. Generic aliases require clarification or an explicitly generic reference. The legacy "longest alias wins" behavior is not part of the target.

Quantities may be exact, household measures, sized units, vague descriptions, entry totals, or package fractions. Vague quantities are never silently converted. Main and fallback parsers must produce the same output contract.

OPEN IMPLEMENTATION DECISION: exact `InterpretationDraft` schema, resolver scoring, and household-measure source.

## Catalog

Reusable nutrition values come only from a curated, persisted, versioned catalog. Meal history is never a nutrition reference. Historical meal values never override deterministic catalog references.

The catalog separates:

- `FoodReference`
- `FoodReferenceVersion`
- `FoodAlias`
- `FoodPortion`
- `FoodSource`

Only active reviewed versions may feed official calculations. Catalog updates create new versions and do not overwrite historical snapshots.

User-provided labels, recipes, and product data remain private to that user. User-uploaded nutrition-label images are untrusted evidence by default and never enter the global catalog automatically. Global promotion requires explicit editorial review.

OPEN IMPLEMENTATION DECISION: editorial review UI, source taxonomy, and first catalog seed source.

## Estimates

Every nutrition calculation creates a structured `NutritionEstimate` preserving:

- reference;
- reference version;
- quantity;
- source;
- formula;
- confidence;
- assumptions;
- result.

Validated estimates are immutable. Registration accepts validated estimate identifiers, never free calorie values or LLM-generated text lines. Persisted meal components preserve calculation snapshots.

OPEN IMPLEMENTATION DECISION: estimate identifier format and snapshot payload shape.

## Validation And Correction

Every estimate passes:

- structural validation;
- provenance validation;
- compatibility validation;
- plausibility validation;
- operational validation.

Relevant states include:

- `requires_clarification`
- `requires_confirmation`
- `validated`
- `quarantined`
- `rejected`

Corrections are versioned domain operations. A corrected estimate supersedes the previous estimate. The agent may only say a correction succeeded after the workflow confirms persistence. Incorrect values remain local and never become reusable nutrition knowledge.

OPEN IMPLEMENTATION DECISION: plausibility thresholds, quarantine reasons, and user-facing correction copy.

## Memory, History, Templates, And RAG

Memory stores preferences and conversational context, not nutritional truth. Meal history stores events that occurred. Saved meal templates model recurring routines.

RAG may locate records or candidates but has no nutritional authority. Repeated meals use an exact source meal or an explicit saved template.

Conversation messages, domain state, catalog knowledge, and audit history remain separate.

OPEN IMPLEMENTATION DECISION: UX for selecting exact prior meals versus saved templates.

## Orchestration

The platform may have a general Conversation Orchestrator for domains such as Nutrition, Personal Trainer, and NutriChef. Each domain owns deterministic workflows and business rules.

The general orchestrator contains no nutrition business logic. LLM agents handle language and presentation, not critical state transitions or nutrition calculations. The new orchestrator must not become another God Object.

OPEN IMPLEMENTATION DECISION: orchestrator/service class boundaries and whether workflow execution is synchronous or queued.

## Required Workflows

Conceptual required workflows:

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

Workflow principles:

- every write operation has an idempotent operation identifier;
- pending questions are persisted and resumed by explicit identifiers;
- a pending meal or component never blocks the whole conversation;
- one user message may produce multiple independent operations;
- valid parts may complete while ambiguous parts remain pending;
- the user may postpone, cancel, accept an explicit estimate, or register only what is clear;
- the conversational experience remains fluid and humanized.

OPEN IMPLEMENTATION DECISION: operation identifier generation, pending-operation persistence contract, and multi-operation response shape.

## Target Persistence

The conceptual target model includes:

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
- old meals retain reference and calculation snapshots;
- official totals include only active validated components;
- writes are transactional and idempotent;
- conversation messages, domain state, catalog knowledge, and audit history remain separate.

OPEN IMPLEMENTATION DECISION: migration order, compatibility reads, and audit event schema.
