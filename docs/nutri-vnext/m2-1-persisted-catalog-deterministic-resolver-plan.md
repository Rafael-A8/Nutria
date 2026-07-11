# M2.1 — Persisted Catalog and Deterministic Resolver Plan

## 1. Repository Findings

### Confirmed repository facts

- The approved canonical domain is `Meal -> MealEntry -> MealComponent`. `MealEntry` preserves the original report; `MealComponent` owns food resolution and future calculation.
- ADR-002, ADR-007, ADR-008, and ADR-010 confirm that:
  - reusable nutrition values come only from a curated, persisted, versioned catalog;
  - only active, reviewed catalog versions may support official calculations;
  - preparation does not compete with food;
  - generic aliases require clarification or an explicit generic reference;
  - “longest alias wins” is prohibited;
  - history, memories, RAG, and embeddings are not nutrition authorities;
  - estimates and registration snapshots belong to M3.
- The existing pure domain under `app/Nutrition/Domain` has no Laravel/Eloquent dependencies.
- `MealComponentDraft` already provides the essential resolver input: `originalText`, `interpretedFoodText`, `ComponentRole`, optional component-owned `Quantity`, and zero or more `Preparation` values.
- `Quantity` enforces important invariants:
  - an `EntryTotal` cannot belong to a component;
  - vague quantities cannot have numeric or gram values;
  - non-exact gram conversion requires `QuantitySource::DeterministicallyConverted`;
  - package fractions and sized/household quantities retain their original structure.
- `LegacyMealParserInterpretationAdapter` consumes legacy parser arrays and emits `InterpretationDraft`. It currently creates one `MealEntryDraft`, maps parser items to `MealComponentDraft`, preserves explicit grams, maps other `quantity_text` values to `QuantityType::Vague`, sets every role to `Unknown`, and does not reconstruct preparation values.
- The adapter therefore supplies canonical draft objects, but its role and preparation semantics remain intentionally incomplete. The resolver must tolerate `Unknown` roles and empty preparation lists without reading raw parser arrays.
- The project uses Laravel 13, Pest 4, PostgreSQL 17 with pgvector in Sail, while `config/database.php` and `.env.example` default to SQLite.
- Sail was not running during inspection, so no live database query was possible. Models and migrations were inspected directly.

### Architectural recommendation

M2 should add a parallel catalog/resolver boundary under `App\Nutrition` without changing existing drafts or the legacy runtime. Keep catalog and resolver contracts framework-free, orchestration in the application layer, Eloquent/query code in infrastructure, and config parsing in a legacy infrastructure adapter.

## 2. Current Config and Legacy Behavior Findings

### Current config

The schema in `config/nutrition.php` is:

```text
estimation
├── preparation_retention_factor
├── measurements
│   ├── grams_per_tablespoon
│   ├── grams_per_tablespoon_fat
│   └── grams_per_teaspoon
└── references[canonical PHP array key]
    ├── aliases[]
    ├── calories_per_100g OR default_calories
    ├── default_grams
    ├── source?
    ├── confidence?
    ├── high_variation?
    ├── variation_note?
    └── is_cooking_fat?
```

Confirmed inventory:

- 106 references.
- 90 use `calories_per_100g`.
- 16 use `default_calories`.
- All 106 have `default_grams`.
- Only 11 declare `source`.
- Ten claim `taco`; one uses `app_estimate`.
- 95 records have no explicit source.
- Eight are marked `high_variation`.
- Four are marked `is_cooking_fat`: butter, olive oil, oil, and margarine.

### Implicit assumptions

- The PHP array key is simultaneously identity and display name.
- `default_grams` is treated as an automatic consumable portion.
- A counted unit is converted by multiplying `default_grams`.
- Millilitres are treated numerically like grams.
- Spoon conversions are global, except for a cooking-fat tablespoon value.
- Cooking-fat retention is globally assumed to be 30%.
- `default_calories` mixes serving-based energy with per-100-gram density.
- Preparation is embedded in names such as `peito de frango grelhado`, `ovo frito`, `batata frita`, and `abobrinha refogada`.
- Substring containment and alias length determine legacy lookup.
- Missing provenance is implicitly tolerated.
- Integer calories hide source precision and rounding policy.

### Alias/config risks

Confirmed generic aliases mapped to specific foods include:

- `carne` → `carne bovina magra grelhada`;
- `peixe` → `peixe grelhado`;
- `pão` → `pão francês`;
- `queijo` → `queijo mussarela`;
- `leite` → `leite integral`;
- `iogurte` → `iogurte natural`;
- `farinha` → `farinha de mandioca`;
- `massa` → `macarrão cozido`;
- `bolo` → `bolo simples`;
- `suco natural` → `suco de laranja natural`.

Other confirmed risks:

- Tilapia aliases are attached to generic `peixe grelhado`.
- `lombo` and `bisteca` both map to `carne de porco assada`.
- `frango assado` maps to combined `coxa/sobrecoxa de frango cozida`.
- `coca`, `guaraná`, and generic `refrigerante` share one value.
- `amstel` and misspelled `amistel` map to generic beer.
- `fritas` is preparation-like and overly broad.
- `salada verde` is not necessarily alface.
- `filé de peixe`, `queijo branco`, `coco`, and `cuscuz` are ambiguous.
- Accent/no-accent variants normalize to the same key within a reference and must be deduplicated.

### Bootstrap classification

- Safe for import staging: structurally valid records with stable legacy keys, retained as unreviewed draft evidence.
- Not safe for immediate official activation: all 106 records, because even `taco` claims lack edition, row identifier, retrieval date, and reproducible citation.
- Requires normalization before publication: food/preparation combinations, `default_calories` records, aliases duplicated after normalization, brand aliases attached to generic foods, and combined food identities.
- Requires quarantine/editorial decision: arbitrary generic-to-specific aliases, tilapia under generic fish, combined `coxa/sobrecoxa`, `biscoito/bolacha`, high-variation composite dishes, preparation-like aliases, and unverifiable values.

### Current legacy runtime

- `MealMessageParsingService` reads the config, detects aliases by substring, resolves overlapping spans by position and length, extracts quantities, protects tilapia grams from butter, recognizes some preparation contexts, and asks clarification for an unallocated composite total.
- `MealEstimationService` resolves by longest contained alias, fills missing non-fat quantities using `default_grams`, converts spoons/units, calculates calories, applies 30% fat retention, and sends unknown foods to an LLM calorie fallback.
- M0.2 removed history from the active calorie-estimation path and added lineage logging.
- `MealService` persists flat `MealItem` rows, creates embeddings, retains user-scoped similar-item recall, and calculates history summaries from stored calories.
- The LLM calorie fallback remains a legacy risk to remove in M3.

### Legacy behavior disposition

| Behavior | Disposition |
|---|---|
| Config-backed production estimation | Preserve temporarily as legacy-only |
| Current parser and adapter | Preserve until interpretation cutover |
| Tilapia/butter containment | Preserve permanently |
| Event/history summaries | Preserve as event queries |
| Longest contained alias | Replace in M2 |
| Generic-to-specific resolution | Prohibit |
| Default portion assumption | Remove from resolver; reconsider in M3 |
| Global spoon/unit conversion | Replace with versioned portions |
| LLM calorie fallback | Legacy-only until M3; then prohibit |
| Meal-item embeddings | Recall only, never nutrition authority |
| Free scalar calories in registration | Legacy-only until M3 |
| Flat `meal_items` | Leave untouched in M2 |

## 3. Proposed Layer and Directory Structure

```text
app/Nutrition/
├── Domain/
│   ├── Drafts/                         # Existing; unchanged
│   ├── Enums/                          # Existing; unchanged
│   ├── ValueObjects/                   # Existing; unchanged
│   ├── Catalog/
│   │   ├── Contracts/
│   │   │   └── FoodCatalogCandidateRepository.php
│   │   ├── Enums/
│   │   │   ├── CatalogVisibility.php
│   │   │   ├── AliasSpecificity.php
│   │   │   ├── CatalogReviewStatus.php
│   │   │   └── PortionType.php
│   │   └── ValueObjects/
│   │       ├── FoodReferenceId.php
│   │       ├── FoodReferenceVersionId.php
│   │       ├── NormalizedFoodText.php
│   │       └── FoodResolutionCandidate.php
│   └── Resolution/
│       ├── Contracts/
│       │   └── MealComponentResolver.php
│       ├── Enums/
│       │   ├── FoodResolutionStatus.php
│       │   ├── FoodResolutionReason.php
│       │   └── FoodMatchKind.php
│       └── ValueObjects/
│           ├── FoodResolutionRequest.php
│           ├── FoodResolution.php
│           └── FoodResolutionTrace.php
├── Application/
│   └── Catalog/
│       ├── NormalizeFoodText.php
│       └── ResolveMealComponent.php
└── Infrastructure/
    ├── Catalog/
    │   ├── Eloquent/
    │   │   ├── Models/
    │   │   │   ├── FoodReference.php
    │   │   │   ├── FoodReferenceVersion.php
    │   │   │   ├── FoodAlias.php
    │   │   │   ├── FoodPortion.php
    │   │   │   └── FoodSource.php
    │   │   └── EloquentFoodCatalogCandidateRepository.php
    │   └── Import/
    │       ├── LegacyNutritionConfigReader.php
    │       ├── LegacyCatalogImportPlanner.php
    │       └── LegacyCatalogImportReport.php
    └── Legacy/
        └── LegacyMealParserInterpretationAdapter.php
```

Layer responsibilities:

- `Domain/Catalog`: identifiers, candidates, enums, repository contract; no framework.
- `Domain/Resolution`: input/output semantics, trace, and reason codes.
- `Application/Catalog`: deterministic coordination and normalization.
- `Infrastructure/Catalog/Eloquent`: models and database queries.
- `Infrastructure/Catalog/Import`: legacy config parsing and controlled bootstrap.
- A single repository abstraction is justified at the database boundary; catalog administration does not need speculative repository layers.

## 4. Catalog Entity Design

### `FoodReference`

- Stable food-concept identity, not a nutrition measurement.
- Internal bigint PK plus opaque stable `public_id`.
- Holds visibility, owner linkage, active/archive state, and stable `is_generic` semantics.
- Display name, preparation, nutrition, aliases, and portions are versioned elsewhere.
- Has many immutable versions.
- Private references require an owner; global references require no owner.
- Archive instead of deleting once published or referenced.

### `FoodReferenceVersion`

- Immutable reviewed snapshot of catalog knowledge.
- Belongs to one `FoodReference` and primary `FoodSource`.
- Contains version number, canonical/normalized name, locale, classification, separate preparation state, energy density, source record key, provenance, review, and activation metadata.
- Nutritional values belong here.
- Initial official energy basis is `energy_kcal_per_100g`.
- `default_calories` is not a parallel nutritional authority.
- Published values are immutable; corrections create version `n+1`.
- Only one approved active version per reference is eligible.
- M3 references the exact version and snapshots the calculation inputs.

### `FoodAlias`

- Belongs to one reference version.
- Stores display alias, normalized key, locale, specificity, source, and provenance.
- Specificity is explicit and never inferred from length.
- A generic alias may only target an explicitly generic reference.
- Published aliases are immutable with their version.
- Duplicate normalized aliases within one version are forbidden.
- Cross-reference duplicates remain allowed so the resolver can return ambiguity.

### `FoodPortion`

- Belongs to one reference version.
- Represents a reviewed food-specific conversion between an explicit portion and grams.
- Stores type, label, normalized label, locale, count basis, gram basis, optional size/package basis, source, and review state.
- It never owns calories and never represents a vague quantity.
- Published portions are immutable.

### `FoodSource`

- Stable provenance identity for a dataset, publication, manufacturer label, or legacy artifact.
- Stores code, name/publisher, type, citation/URI, release version, license, checksum, retrieval date, and metadata.
- A legacy import source should be recorded as `legacy_config_nutrition_v1`; unsupported `taco` claims remain metadata until verified.
- Referenced sources are archived, never deleted.

## 5. Conceptual Database Schema

### `food_sources`

- `id` bigint PK;
- `public_id` unique UUID/ULID;
- unique `code`;
- `name`, `publisher`, `source_type`;
- nullable `dataset_version`, `source_uri`, `license`, `checksum`, `retrieved_at`, `provenance` JSON;
- `is_active`, `archived_at`, `created_by`, timestamps;
- deletion restricted while referenced.

### `food_references`

- `id`, unique `public_id`, `stable_key`;
- `visibility` (`global`/`private`);
- nullable `owner_user_id`;
- `is_generic`, `is_active`, `archived_at`;
- audit fields;
- checks: global requires null owner, private requires owner;
- unique global stable key and unique `(owner_user_id, stable_key)` for private scope.

Nullable uniqueness differs by database, so partial indexes or application validation may be needed.

### `food_reference_versions`

- `id`, unique `public_id`;
- `food_reference_id`, `version_number`;
- `canonical_name`, indexed `normalized_name`, `locale`;
- `classification`, nullable `preparation_type`;
- nullable decimal `energy_kcal_per_100g` while draft, mandatory for activation;
- `food_source_id`, nullable `source_record_key`;
- `review_status`, reviewer/review timestamp;
- activation/deactivation timestamps;
- nullable `supersedes_version_id`, provenance JSON, timestamps;
- unique `(food_reference_id, version_number)`;
- positive-energy check;
- resolver indexes over locale/name/status/activation;
- PostgreSQL partial unique index for one active approved version per reference;
- activation additionally protected by transaction and `lockForUpdate()`.

### `food_aliases`

- `id`, `food_reference_version_id`;
- `display_alias`, `normalized_alias`, `locale`, `specificity`;
- optional `food_source_id`, provenance, timestamps;
- unique `(food_reference_version_id, locale, normalized_alias)`;
- lookup index `(locale, normalized_alias, food_reference_version_id)`;
- no global alias uniqueness.

### `food_portions`

- `id`, `food_reference_version_id`, `food_source_id`;
- `portion_type`, `display_name`, `normalized_name`, `locale`;
- positive `unit_count`;
- nullable positive `grams_per_unit`, `size_code`, `package_net_grams`;
- review/audit/provenance fields;
- checks specific to each portion type;
- lookup index over version, locale, type, and normalized name.

### Compatibility concerns

- SQLite and PostgreSQL differ on checks, nullable uniqueness, collations, and partial indexes.
- Accent-insensitive lookup must use application-generated normalized keys, not DB collation.
- Prefer string-backed PHP enums over database-specific enum types.
- Use decimals, not floats, for energy and conversion factors.
- Never edit existing meal migrations.
- Keep DDL and data import in separate operations.

## 6. Alias Policy

### Normalization

1. Validate UTF-8.
2. Apply Unicode NFKC where supported.
3. Trim and collapse whitespace.
4. Lowercase using UTF-8-aware behavior.
5. Fold accents for lookup.
6. Replace punctuation, slashes, underscores, and hyphens with spaces.
7. Collapse spaces again.
8. Preserve the original display alias separately.

Examples:

- `Feijão` and `feijao` → `feijao`;
- `grão-de-bico` → `grao de bico`;
- `PÃO   FRANCÊS` → `pao frances`.

Do not infer singular/plural through stemming in M2. Forms must be explicit aliases.

### Retrieval and selection

- Exact normalized canonical names and exact normalized aliases only.
- Substring matching is not authoritative.
- Alias length is never a selection rule.
- Merge canonical and alias results, deduplicating by version ID.
- Canonical/alias collisions remain ambiguous.
- Generic aliases cannot point to specific references.
- `carne`, `peixe`, `pão`, etc. either resolve an explicit generic reference, return multiple candidates, or require clarification.
- Inactive references/versions are excluded and traced.
- Locale matching is exact in the first resolver.
- Private records are owner-isolated.
- Private candidates do not automatically override global candidates.
- Use match kinds, not invented numeric confidence.

## 7. FoodPortion Policy

`FoodPortion` is a reviewed conversion rule, not a default calorie assumption.

| Quantity type | Future interaction |
|---|---|
| `Exact` | Grams pass through; other units need an explicit safe conversion |
| `HouseholdMeasure` | Exact reviewed label match × explicit amount |
| `SizedUnit` | Exact unit label and size code required |
| `PackageFraction` | Requires a reviewed package net weight |
| `Vague` | Never converted; clarification required |
| `EntryTotal` | Never handled by a component resolver |

Additional policies:

- Portions are food-specific by default.
- Generic vocabulary may describe labels, but cannot provide food grams alone.
- Locale-specific labels/aliases are versioned.
- A stored default serving must not silently fill a missing quantity.
- Evidence is expressed by source/review state rather than probability.
- Unknown measures, package sizes, sizes, or locales produce “no safe conversion”.
- Portion conversion happens after reference resolution and before M3 calculation, never during candidate selection.

## 8. Resolver Contract

Recommended input:

```php
final readonly class FoodResolutionRequest
{
    public function __construct(
        public MealComponentDraft $component,
        public string $locale,
        public int|string|null $catalogOwnerId = null,
        public ?string $entryOriginalText = null,
    ) {}
}
```

| Field | Retrieval | Compatibility | Final selection | Audit only |
|---|---:|---:|---:|---:|
| `interpretedFoodText` | Yes | No | Yes | Yes |
| locale | Yes | No | Yes | Yes |
| catalog owner | Yes | No | Yes | Yes |
| component role | No | Yes | Yes | Yes |
| preparations | No | Yes | Yes | Yes |
| quantity | No | Safety only | No | Yes |
| `originalText` | No | No | No | Yes |
| entry original text | No | No | No | Yes |

The complete `MealComponentDraft` must be passed. Quantity does not influence identity and is never copied.

### Candidate contract

A candidate contains:

- stable reference ID;
- exact version ID;
- canonical display name;
- normalized matched key;
- match kind and optional alias ID;
- visibility/owner;
- generic flag and classification;
- preparation compatibility metadata;
- active/reviewed state;
- source ID/code;
- deterministic filter/rejection reasons.

It must not contain calculated calories.

### Output contract

Use a validated tagged result with:

- `Resolved`;
- `Ambiguous`;
- `ClarificationRequired`;
- `Unresolved`;
- `InvalidInput`.

Suggested reason codes:

- `blank_food_text`;
- `no_exact_match`;
- `alias_collision`;
- `canonical_alias_collision`;
- `generic_alias_requires_clarification`;
- `explicit_generic_reference`;
- `role_incompatible`;
- `preparation_incompatible`;
- `multiple_compatible_candidates`;
- `inactive_reference`;
- `no_active_reviewed_version`;
- `private_scope_excluded`;
- `unique_compatible_exact_match`.

Do not use numeric confidence. Match kind and reason codes are auditable.

### Trace

Trace retains policy version, original/normalized food text, locale/scope, query kinds, candidate IDs, deduplication, filters/reasons, final result, and stable ordering tuple. Full user messages should not be logged by default.

### Prohibitions

The resolver must not mutate drafts, calculate calories, convert quantities, persist, call an LLM, use history/memory/RAG/embeddings as authority, treat preparation as food, inherit quantity, or select by alias length.

## 9. Deterministic Resolution Algorithm

1. Validate nonblank food text, locale, owner scope, and quantity ownership.
2. Normalize using the one shared policy and record transformations.
3. Query exact canonical names among eligible versions.
4. Query exact aliases among eligible versions.
5. Merge and deduplicate by reference-version ID while preserving match paths.
6. Filter visibility: global or matching private owner only.
7. Filter eligibility: active reference, approved active version, active source.
8. Detect catalog integrity errors such as multiple active versions rather than selecting arbitrarily.
9. Enforce alias specificity; generic alias on specific reference is invalid catalog data.
10. Apply role compatibility using a code-owned deterministic matrix.
11. Apply preparation compatibility without generating food candidates from preparation terms.
12. Produce:
    - no candidates → `Unresolved`;
    - all incompatible but clarifiable → `ClarificationRequired`;
    - multiple eligible → `Ambiguous`;
    - one explicit generic → `Resolved(explicit_generic_reference)`;
    - one specific → `Resolved(unique_compatible_exact_match)`.
13. Order output deterministically by a complete tuple such as visibility, match kind, public ID, version number, and alias ID. Ordering never turns ambiguity into resolution.

Database queries occur only in `FoodCatalogCandidateRepository`. The repository must use explicit ordering, select required columns, avoid hidden current-version global scopes, and produce the same candidate set regardless of insertion order.

Do not implement fuzzy matching in M2. Future fuzzy search may suggest candidates only; it can never silently authorize nutrition truth.

## 10. Initial Catalog Import Strategy

### Controlled workflow

1. `catalog:plan-legacy-import` produces a deterministic dry-run report.
2. After review, `catalog:apply-legacy-import --manifest=<approved manifest>` atomically applies the approved records.

Never import automatically from a migration or application boot.

### Responsibility separation

- Migration: schema only.
- Factory: test data only.
- Seeder: curated development/test orchestration only.
- Importer: validation, normalization, stable IDs, collision detection, transaction.
- Artisan command: dry-run/apply interface and report rendering.
- Legacy reader: only class that understands `config/nutrition.php`.

### Identity and idempotency

- Create source `legacy_config_nutrition_v1`.
- Compute/store input checksum.
- Use import key `legacy_config_nutrition_v1:<normalized legacy key>`.
- Derive/map deterministic public identifiers from a fixed namespace.
- Initial version is `1`.
- Same checksum/manifest is a no-op.
- Changed input requires a new plan and never mutates a published version.
- Concurrent apply requires transaction and lock.

### Dry-run classifications

- `ready_as_draft`;
- `requires_normalization`;
- `quarantined`;
- `rejected`.

The report includes alias duplicates/collisions, canonical/alias collisions, unsafe generic aliases, preparation embedded in food identity, missing provenance, serving-based energy, invalid numbers, combined food identities, checksum, and proposed IDs.

### Apply semantics

- Dry-run is mandatory.
- Apply requires the exact approved manifest/checksum.
- Approved set is atomic.
- Rejected/quarantined rows do not enter authoritative tables.
- Accepted legacy records enter as drafts only.
- Nothing is automatically approved or activated.
- Exclusion reports remain reproducible.
- Future curated datasets create new sources/versions and reuse stable identities only after reviewed mapping.

## 11. M2 versus M3 Boundary

| Concern | M2 | M3 | Reason |
|---|---:|---:|---|
| Persisted food catalog | Yes | Consume | Catalog authority |
| Catalog identity/versioning | Yes | Reference/snapshot | Required before estimates |
| Aliases | Yes | No | Resolution concern |
| Portions/conversion metadata | Yes | Consume | Curated knowledge |
| Sources/provenance | Yes | Validate/use | Governance |
| Deterministic candidate resolution | Yes | Consume | Maps draft to version |
| Quantity-to-gram conversion | Contract/metadata | Yes | Calculation workflow |
| Calorie formula | No | Yes | Estimate concern |
| Quantity-to-calorie computation | No | Yes | Estimate concern |
| `NutritionEstimate` | No | Yes | Explicitly deferred |
| Immutable estimate snapshot | No | Yes | Historical safety |
| Estimate validation | No | Yes | Requires estimate |
| Plausibility checks | No | Yes | Applies to result |
| Outlier quarantine | Import issues only | Estimate quarantine | Different authority |
| Estimate supersession | No | Yes/M4 | Estimate lifecycle |
| Meal registration workflow | No | Yes | Validated estimate boundary |
| Runtime cutover | No | Later M3 | Avoid dual authority |
| Legacy meal backfill | No | Later | Needs snapshot strategy |
| History/embedding prohibition | Boundary tests | Permanent | Architectural invariant |

## 12. Legacy Coexistence and Future Cutover

### Safe additive work

- Pure contracts and value objects.
- Catalog tables/models in later M2 phases.
- Import tooling producing inactive drafts.
- Isolated resolver tests.
- Optional non-production diagnostics.

### Keep untouched in early M2

- `MealEstimationService`;
- `MealMessageParsingService`;
- `MealExtractionService`;
- `MealRegistrationGuardrailService`;
- `MealService`;
- AI tools and agent wiring;
- `config/nutrition.php`;
- `meals`/`meal_items`;
- embeddings and summaries;
- existing domain drafts.

### Prevent dual sources of truth

- Do not inject the catalog repository into the legacy estimator.
- Do not expose catalog values through legacy estimate tools.
- Do not activate official versions until governance is tested.
- Do not fall back from vNext resolver to config, history, or AI calories.
- Add architecture tests prohibiting dependencies on legacy services, `MealItem`, embeddings, and AI APIs.

### Future M3 flow

1. Accept `InterpretationDraft`.
2. Resolve each `MealComponentDraft`.
3. Convert only explicit safe quantities.
4. Create/validate `NutritionEstimate`.
5. Register by estimate ID.

No feature flag is needed for M2.2. Add one only when a real parallel M3 path exists.

## 13. Recommended M2 Subphase Breakdown

### Firm M2.2 decision: Option A

M2.2 creates only framework-free catalog/resolution contracts, normalization, and unit/architecture tests. It creates no migrations.

Why:

- 95 of 106 config records lack source metadata.
- Food/preparation identities still need review.
- Alias and generic-reference policies should become executable contracts before schema constraints are frozen.
- SQLite/PostgreSQL differences should be addressed after vocabulary stabilization.
- The change is small, reversible, and runtime-neutral.

### M2.2 includes

- normalized food text;
- reference/version identifiers;
- request, candidate, result, trace, statuses, and reasons;
- candidate repository interface;
- resolver interface;
- normalization, invariant, and architecture tests.

### M2.2 excludes

- migrations/models;
- database repository;
- resolver algorithm;
- config importer/data;
- bindings/wiring;
- portions/conversions;
- calories/estimates.

### Following subphases

- **M2.3:** persistence skeleton, migrations, models, factories, constraints, relationships, activation tests.
- **M2.4:** dry-run planner/report, importer command, idempotency, quarantine; data remains draft.
- **M2.5:** Eloquent repository, deterministic resolver, exact matching, ambiguity/clarification tests.
- **M2.6:** minimal review/activation boundary; no legacy estimator wiring.
- **M3:** conversions, estimates, validation, snapshots, registration, and cutover.

## 14. Minimum Test Matrix

| Scenario | M2.2 | Later | Type |
|---|---:|---:|---|
| Exact canonical match | Contract shape | M2.5 | Unit + DB integration |
| Exact specific alias | Contract shape | M2.5 | Unit + DB integration |
| Accent/case normalization | Yes | Reuse | Unit |
| Punctuation/whitespace | Yes | Reuse | Unit |
| Explicit plural alias | Policy | M2.5 | Unit |
| Alias collision | Result invariant | M2.5 | Unit + DB |
| Generic alias | Reason invariant | M2.5 | Unit |
| Explicit generic reference | Candidate/result | M2.5 | Unit + DB |
| Unknown food | Unresolved shape | M2.5 | Unit |
| Food plus preparation | Request separation | M2.5 | Unit |
| Food plus cooking fat | Independent requests | M2.5 | Unit |
| Cooking fat without quantity | No copied quantity | M2.5/M3 | Unit |
| Multiple components | Independent resolution | M2.5 | Unit |
| Known household portion | Deferred contract | M2.3/M3 | DB + integration |
| Unknown household portion | Deferred | M3 | Unit |
| Vague quantity | Unchanged | M2.2 | Unit |
| Package fraction | Unchanged | M2.3/M3 | Unit + integration |
| Private isolation | Context contract | M2.5 | DB |
| Private/global collision | Ambiguity | M2.5 | DB |
| Inactive alias/reference | Exclusion vocabulary | M2.5 | DB |
| Active reviewed version | Version ID contract | M2.3/M2.5 | DB |
| Multiple active versions | Integrity result | M2.3 | DB |
| Insertion-order determinism | Stable ordering | M2.5 | DB |
| Import idempotency | No | M2.4 | DB |
| Duplicate import | No | M2.4 | DB |
| Invalid catalog record | Report vocabulary | M2.4 | Unit + DB |
| Preparation alias quarantine | Policy vocabulary | M2.4 | Unit |
| Config checksum mismatch | No | M2.4 | Integration |
| Meal history prohibited | Architecture boundary | M2.5 | Architecture + unit |
| Embeddings prohibited | Architecture boundary | M2.5 | Architecture + unit |
| LLM dependency prohibited | Yes | Permanent | Architecture |
| Eloquent in pure domain prohibited | Yes | Permanent | Architecture |
| Calories in resolver prohibited | Yes | Permanent | Unit + architecture |
| Tilapia/butter regression | Existing tests | M2.5/M3 | Unit + integration |

## 15. Risks and Open Decisions

| Question | Options | Recommendation | Trade-off | Blocks M2.2? |
|---|---|---|---|---:|
| Public identifier format? | UUIDv5, UUIDv7, ULID, opaque | Opaque in M2.2; UUIDv7 authored + UUIDv5 imported later | Two generation modes | No |
| Private/global storage? | Unified or separate | Unified visibility/owner table | Simpler resolver | No |
| Private alias precedence? | Always, never, explicit | Never automatic; collision is ambiguous | More clarification | No |
| Can generic references be official? | Never, always, curated only | Curated explicit generic only | Editorial burden | No |
| Preparation-specific energy? | Food identity, version field, relation | Separate version field initially | May need richer model later | No |
| One active version enforcement? | App, DB, both | Both | Portability work | No |
| Published alias mutation? | Mutable or new version | New version; reference emergency kill switch | More versions | No |
| Initial trusted source? | Config, verified TACO, external | Verified curated dataset | Delays activation | No |
| `default_calories` handling? | Derive, retain, quarantine | Quarantine pending review | Fewer imports | No |
| Fuzzy matching in M2? | Yes/no | No | Lower recall, much safer | No |
| Locale fallback? | Automatic or exact | Exact initially | More explicit aliases | No |
| Import issue storage? | Console, artifact, DB | Structured report; DB run/issues if needed | Extra schema | No |
| Automatic legacy activation? | All, allowlist, none | None | Editorial work | No |
| Generic spoon weights? | Yes, vocabulary, no | Generic vocabulary only; grams food-specific | More catalog data | No |
| Role compatibility source? | DB or code | Versioned code-owned matrix | Deployment required to change | No |
| Persist resolver traces? | Always, failures, later | Return now; persistence with audit schema | Defers storage | No |
| Production wiring in M2? | Flag or none | None | Runtime benefit delayed | No |

Approval required before M2.2: acceptance of Option A and the normalization/result vocabulary. Persistence decisions do not block M2.2.

## 16. Exact M2.2 Prompt

```text
## M2.2 — Catalog and Resolution Contracts

You are implementing the smallest approved M2 subphase of Nutri vNext.

This task is limited to framework-free catalog/resolution contracts,
deterministic text normalization, and focused tests.

Read before changing code:

- AGENTS.md
- docs/nutri-vnext/architecture-target.md
- docs/nutri-vnext/migration-plan.md
- docs/nutri-vnext/master-test-matrix.md
- docs/nutri-vnext/adrs/ADR-002-canonical-catalog.md
- docs/nutri-vnext/adrs/ADR-007-interpretation-resolution.md
- docs/nutri-vnext/adrs/ADR-008-catalog-governance.md
- docs/nutri-vnext/adrs/ADR-010-persistence-audit.md
- app/Nutrition/Domain/**
- app/Nutrition/Infrastructure/Legacy/LegacyMealParserInterpretationAdapter.php
- tests/Unit/Nutrition/Domain/InterpretationDraftTest.php
- tests/Unit/Nutrition/Infrastructure/Legacy/LegacyMealParserInterpretationAdapterTest.php

Use the laravel-best-practices and pest-testing skills. Use Laravel Boost
search-docs before code changes if its tools are available.

### Approved scope

Create:

- app/Nutrition/Domain/Catalog/ValueObjects/FoodReferenceId.php
- app/Nutrition/Domain/Catalog/ValueObjects/FoodReferenceVersionId.php
- app/Nutrition/Domain/Catalog/ValueObjects/NormalizedFoodText.php
- app/Nutrition/Domain/Catalog/ValueObjects/FoodResolutionCandidate.php
- app/Nutrition/Domain/Catalog/Contracts/FoodCatalogCandidateRepository.php
- app/Nutrition/Domain/Resolution/Enums/FoodResolutionStatus.php
- app/Nutrition/Domain/Resolution/Enums/FoodResolutionReason.php
- app/Nutrition/Domain/Resolution/Enums/FoodMatchKind.php
- app/Nutrition/Domain/Resolution/ValueObjects/FoodResolutionRequest.php
- app/Nutrition/Domain/Resolution/ValueObjects/FoodResolution.php
- app/Nutrition/Domain/Resolution/ValueObjects/FoodResolutionTrace.php
- app/Nutrition/Domain/Resolution/Contracts/MealComponentResolver.php
- app/Nutrition/Application/Catalog/NormalizeFoodText.php
- tests/Unit/Nutrition/Catalog/NormalizeFoodTextTest.php
- tests/Unit/Nutrition/Catalog/FoodResolutionContractTest.php
- tests/Unit/Nutrition/Catalog/CatalogBoundaryTest.php

Adjust a filename only if PHP/project conventions require it, while preserving
responsibility and reporting the deviation.

### Required behavior

FoodResolutionRequest receives the complete MealComponentDraft, locale,
optional catalog owner ID, and optional MealEntry original text for audit.

FoodResolutionCandidate identifies stable reference/version, display name,
matched normalized text, match kind, generic/classification metadata,
visibility/owner, and provenance/source. It contains no calculated calories.

FoodResolution represents resolved, ambiguous, clarification_required,
unresolved, and invalid_input, validating its candidate cardinality.

Include reason codes:

- blank_food_text
- no_exact_match
- alias_collision
- canonical_alias_collision
- generic_alias_requires_clarification
- explicit_generic_reference
- role_incompatible
- preparation_incompatible
- multiple_compatible_candidates
- inactive_reference
- no_active_reviewed_version
- private_scope_excluded
- unique_compatible_exact_match

NormalizeFoodText must deterministically trim, lowercase, fold accents, replace
punctuation/slashes/underscores/hyphens with spaces, and collapse whitespace.
Do not singularize, pluralize, stem, substring-match, or fuzzy-match.

Use readonly/final value objects and explicit types. Do not introduce Laravel or
Eloquent dependencies under app/Nutrition/Domain.

### Invariants

- Meal -> MealEntry -> MealComponent remains canonical.
- MealEntry preserves original reporting.
- MealComponentDraft is resolver input; raw parser arrays are forbidden.
- Food and preparation stay separate.
- Quantity ownership stays explicit.
- Vague quantity is never converted.
- Cooking fat never inherits quantity.
- Longest alias wins is prohibited.
- Generic alias never silently selects a specific food.
- History, memory, RAG, and embeddings are not nutrition sources.
- Resolver does not calculate calories, call an LLM, or persist.

### Non-goals

Do not implement migrations, Eloquent models, factories, seeders, repository
implementations, database queries, config import, catalog data, resolver
algorithm, portions, conversions, NutritionEstimate, calories, formulas,
validation, registration, dependency bindings, flags, or runtime cutover.

### Files that must remain untouched

- config/nutrition.php
- app/Services/MealEstimationService.php
- app/Services/MealMessageParsingService.php
- app/Services/MealExtractionService.php
- app/Services/MealAmbiguityService.php
- app/Services/MealService.php
- app/Services/MealRegistrationGuardrailService.php
- app/Ai/**
- app/Models/Meal.php
- app/Models/MealItem.php
- existing database migrations
- app/Nutrition/Domain/Drafts/**
- app/Nutrition/Domain/Enums/**
- app/Nutrition/Domain/ValueObjects/**
- app/Nutrition/Infrastructure/Legacy/LegacyMealParserInterpretationAdapter.php

### Focused tests

Prove normalization, idempotency, no stemming, request retention of the exact
draft, valid result cardinality, stable ambiguous ordering, generic reason
representation, absence of calories, no quantity mutation/copying, and absence
of Eloquent/facade/models/AI/embedding/history/legacy dependencies.

Run through Sail:

- vendor/bin/sail artisan test --compact tests/Unit/Nutrition/Catalog
- vendor/bin/sail artisan test --compact tests/Unit/Nutrition/Domain/InterpretationDraftTest.php
- vendor/bin/sail artisan test --compact tests/Unit/Nutrition/Infrastructure/Legacy/LegacyMealParserInterpretationAdapterTest.php
- vendor/bin/sail bin pint --dirty --format agent

If Sail is unavailable, report the blocker; do not use host PHP.

### Prohibited commands

Do not run migrations, seeders, importers, builds, tinker, dependency changes,
destructive Git commands, or commits. Do not wire production runtime.

### Final report

Report files created, contract decisions, test/formatter results, deviations,
confirmation of excluded persistence/calorie/runtime work, and remaining M2.3
work.
```

---

M2.1 inspection confirmation: the planning inspection changed no files and ran no mutating commands. The only attempted Sail command was a read-only config inventory; it did not execute because Docker/Podman was stopped.
