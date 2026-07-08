# Nutri vNext Migration Plan

Status: planning document for architecture freeze. No implementation is approved by this file.

## Constraints

- Do not modify production migrations in place.
- Do not treat current meal, memory, conversation, or embedding test data as trusted nutrition knowledge.
- Do not preserve historical meal values as references.
- Do not let LLM output become persisted nutrition truth without deterministic validation.

## Phase M0.1: Architecture Freeze And Legacy Inventory

Deliverables:

- target architecture documentation;
- accepted ADRs;
- legacy lifecycle inventory;
- legacy dependency and deletion map;
- phase M0.2 containment plan;
- master test matrix.

No production code changes are part of M0.1.

## Phase M0.2: Containment

Goal: stop the highest-risk legacy behaviors before the target architecture is implemented.

Recommended scope:

- disable historical meal items as a calorie source;
- disable global meal-history calorie reuse;
- prevent new nutrition decisions from relying on meal-item embeddings;
- preserve history queries only as event queries;
- add the permanent tilapia/butter regression;
- prove butter cannot receive tilapia's 120g quantity;
- add temporary runtime observability for estimate source, reference key, formula, and history item when present.

Do not begin the target domain rewrite in M0.2.

`nutrition_v2_enabled` is not recommended for M0.2 because there is no second implemented architecture to switch to. OPEN IMPLEMENTATION DECISION: introduce a feature flag later only if M1 or M2 needs parallel legacy/vNext routing in production infrastructure.

## Phase M1: Canonical Domain Skeleton

Goal: introduce the canonical domain boundaries without changing all user-facing behavior at once.

Conceptual work:

- add `MealEntry` and `MealComponent` persistence;
- define `InterpretationDraft` contract;
- split preparation from food component;
- preserve raw user input separately from interpretation.

OPEN IMPLEMENTATION DECISION: exact database columns, model namespaces, and compatibility read strategy.

## Phase M2: Persisted Catalog And Resolver

Goal: replace static config references with curated versioned catalog data.

Conceptual work:

- introduce `FoodReference`, `FoodReferenceVersion`, `FoodAlias`, `FoodPortion`, and `FoodSource`;
- allow only active reviewed versions to feed official calculations;
- implement deterministic reference resolution;
- remove alias heuristics that let preparation terms compete with foods.

OPEN IMPLEMENTATION DECISION: initial catalog data source and editorial workflow.

## Phase M3: Structured Estimates And Registration

Goal: make estimates immutable and registration provenance-safe.

Conceptual work:

- introduce `NutritionEstimate`;
- persist formula, reference version, quantity, confidence, assumptions, and result;
- validate estimates before registration;
- make registration accept validated estimate identifiers only;
- persist calculation snapshots on meal components.

OPEN IMPLEMENTATION DECISION: estimate ID format, validation thresholds, and transaction boundaries.

## Phase M4: Corrections, Pending Operations, And Non-Blocking Conversation

Goal: replace prompt-only continuation with persisted workflow state.

Conceptual work:

- implement pending operation records;
- implement clarification, confirmation, correction, removal, deletion, and repeat workflows;
- make valid components complete while ambiguous components remain pending;
- ensure the assistant only confirms successful correction after persistence.

OPEN IMPLEMENTATION DECISION: pending-operation resume UX and cancellation semantics.

## Phase M5: Templates, Label Evidence, Governance, And Audit

Goal: complete recurrence, private user references, label evidence, promotion governance, and audit trails.

Conceptual work:

- introduce meal templates and versions;
- introduce user food references;
- introduce nutrition label evidence;
- introduce explicit review before global catalog promotion;
- emit nutrition audit events for writes, corrections, quarantine, and catalog-version use.

OPEN IMPLEMENTATION DECISION: audit event payload schema and label-image retention policy.
