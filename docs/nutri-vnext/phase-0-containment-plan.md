# Phase 0 Containment Plan

Status: plan only. Do not implement in M0.1.

Goal for M0.2: stop the highest-risk legacy behaviors while leaving the target architecture implementation for later phases.

## Recommended M0.2 Scope

- Disable historical meal items as a calorie source.
- Disable global meal-history calorie reuse.
- Prevent new nutrition decisions from relying on meal-item embeddings.
- Preserve history queries only as event queries.
- Add the permanent regression for `120g de tilápia grelhada na manteiga`.
- Prove butter can no longer receive tilapia's 120g quantity.
- Add temporary runtime observability for estimate source, reference key, formula, and history item when applicable.
- Do not add `nutrition_v2_enabled` in M0.2 unless a parallel v2 runtime path is introduced. Current repository architecture has no second implementation to toggle.

## Containment Changes

| Change | Exact file and method | Current behavior | Proposed M0.2 behavior | Dependencies | Regression risk | Minimum tests | Rollback |
|---|---|---|---|---|---|---|---|
| Stop history as estimation source | `app/Services/MealEstimationService.php:179-230` in `estimateItem()`; `app/Services/MealEstimationService.php:671-675` in `bestHistoryMatch()` | `estimateItem()` computes `$historyMatch` and calls `estimateFromHistory()` before `estimateFromReference()` at `app/Services/MealEstimationService.php:217-222`. | Do not fetch or use `bestHistoryMatch()` for calorie estimation. Deterministic references must be evaluated without historical override. Unknown foods go to clarification or the existing low-confidence path until M3 replaces it. | `MealService::findSimilarItems()`, `MealAmbiguityService`, `tests/Feature/MealEstimationServiceTest.php:89-115`. | Repeated foods no longer inherit prior calories; some previously estimated foods may ask clarification. | Update the historical scaling test to assert no `user_history` source and that configured reference wins. Add a test where a misleading history item exists and a catalog reference still determines calories. | Revert the change in `estimateItem()` and restore the test expectation. |
| Stop global meal-history calorie reuse | `app/Services/MealService.php:47-73` in `findSimilarItems()` | Searches user items first, then global other-user meal items at `app/Services/MealService.php:65-73`. | For nutrition decisions, no caller should use this method. If the method remains for event recall, remove the global fallback or make it explicit event-search only and never return values to estimation. | `GetSimilarItemsTool`, `MealEstimationService::bestHistoryMatch()`, `tests/Feature/Ai/Tools/GetSimilarItemsToolTest.php:8-28`. | Loss of cross-user "base" behavior; fewer matches in old similar item tool. | Add a test proving other users' meal items are not returned for nutrition estimation. If the method remains, add a test that global fallback is disabled or behind event-only semantics. | Restore the global query block at `app/Services/MealService.php:65-73`. |
| Remove similar-history tool from nutrition agent decisions | `app/Ai/Agents/NutritionistAgent.php:168-178` in instructions; `app/Ai/Agents/NutritionistAgent.php:374-386` in `tools()` | Prompt instructs "Use `get_similar_items`" and the agent exposes `GetSimilarItemsTool`. | Remove the prompt instruction to call `get_similar_items` for estimation and remove `GetSimilarItemsTool` from the nutrition agent tool list. Keep the class only if needed for event-query compatibility tests. | `GetSimilarItemsTool`, `MealService::findSimilarItems()`, `NutritionistAgentTest` assertions around tool flow. | Agent loses a legacy helper; user-facing repeated meal handling may be weaker until templates/repeat workflow exist. | Agent instruction test must assert `get_similar_items` is not part of meal calculation guidance. Add a test that the tool list does not include `GetSimilarItemsTool` for the nutrition agent. | Re-add tool and prompt line. |
| Preserve history queries as event queries | `app/Ai/Tools/GetTodaySummaryTool.php:37-49`; `app/Ai/Tools/GetPeriodSummaryTool.php:42-77`; `app/Services/MealService.php:79-155` | Today and period summary tools report events and totals from persisted meals. | Keep these as query tools, but document and test that they are not calorie references for new estimates. Future vNext totals must use active validated components. | `MealService`, `SummaryService`, `NutritionistAgent` prompt today context. | Legacy summaries still reflect old flat calories until M3/M4 migration. | Existing summary tests should continue. Add an M0.2 assertion that estimation service does not call `findSimilarItems()` when generating new estimates. | Restore estimation history calls if rollback is needed. |
| Prevent cooking-fat default grams from masking unknown quantity | `app/Services/MealEstimationService.php:344-368` in `resolveQuantityGrams()`; `app/Services/MealEstimationService.php:179-230` in `estimateItem()` | Missing quantity falls back to `reference['default_grams']` at `app/Services/MealEstimationService.php:367`, including cooking fats. Ambiguity then receives a non-null quantity. | Do not apply default grams to cooking fats when the user did not give an explicit quantity or resolvable household measure. Preserve unknown quantity so ambiguity can require clarification. | `config/nutrition.php:423-428`, `MealAmbiguityService`. | Some previous low-impact default-fat estimates become clarification prompts. | Add service test for butter with unknown quantity requiring clarification. Add the tilapia/butter regression. | Restore default reference fallback for cooking fats. |
| Strengthen cooking-fat ambiguity | `app/Services/MealAmbiguityService.php:58-119` in `assess()` | Requires clarification for preparation fat only when it looks like preparation and quantity is null, or when quantity is >= 20g. | If an item is a cooking fat and quantity remains unknown, require clarification unless it is explicitly consumed with an explicit quantity. The target interpretation for butter in the regression is cooking fat with unknown quantity. | `MealEstimationService::resolveQuantityGrams()`, parser context. | More clarification prompts for oils/butter without quantities. | Tests for `manteiga` with no quantity, `azeite` with explicit teaspoon, and explicitly consumed sauce with quantity. | Revert the ambiguity rule. |
| Add tilapia/butter parser regression | `tests/Feature/MealMessageParsingServiceTest.php` | No test covers `120g de tilápia grelhada na manteiga`. Current tests cover butter in "2 colheres de sopa" context at `tests/Feature/MealMessageParsingServiceTest.php:5-24`. | Add a test asserting two interpreted items/components: tilapia/peixe primary food with 120g, and butter as cooking fat/preparation with no 120g quantity. | `MealMessageParsingService`, `config/nutrition.php:149-154`, `config/nutrition.php:423-428`. | Parser output description may be generic `tilápia` or `peixe`; test should focus on quantity ownership and butter not 120g. | Assert no parsed butter item has `quantity_grams` 120. Assert tilapia/fish item has `quantity_grams` 120. | Remove or mark test pending only if product rejects this invariant, which would conflict with approved M0.2 scope. |
| Add tilapia/butter estimation regression | `tests/Feature/MealEstimationServiceTest.php`; implementation points in `app/Services/MealEstimationService.php:179-230`, `app/Services/MealEstimationService.php:297-338`, `app/Services/MealEstimationService.php:344-368` | Current estimation can use reference default grams for butter or history before reference. | Add a test proving the forbidden result does not occur: butter is not estimated at 120g, and `120 * 717 / 100 = 860 kcal` is not produced. Expected result is clarification for butter unknown quantity or partial valid tilapia with pending butter once workflows support partial completion. | `MealAmbiguityService`, `config/nutrition.php`. | The current all-or-clarification estimator may return clarification for the whole meal. That is acceptable in M0.2 containment if it prevents wrong registration. | Assert status is `clarification_required` or no registered butter line exists; assert no calculation line contains `120 × 717/100`, `120 * 717 / 100`, or `860 kcal` for butter. | Revert service changes and test if rollback is required. |
| Add runtime observability | `app/Services/MealEstimationService.php:83-101` item loop; `app/Services/MealEstimationService.php:237-338`; `app/Services/MealEstimationService.php:262-290` | Result arrays include `source` and `calculation_line`, but no dedicated runtime log for source/reference/formula/history item. | Temporarily log one structured event per estimated item with source, normalized description, reference key when available, formula/calculation line, and history item ID only if history remains reachable. Avoid logging raw full user messages. | `Log`, estimate result arrays, reference lookup. | Log noise and possible sensitive food data exposure. | Add a test with `Log::spy()` or fake logger verifying source and formula fields for deterministic reference. Add a history-disabled test proving `history_item_id` is absent/null. | Remove log statements. |
| Keep `nutrition_v2_enabled` out of M0.2 | No current file. Candidate future location would be `config/nutrition.php`. | No v2 flag exists. | Do not introduce `nutrition_v2_enabled` for containment only. Add it later only if a real parallel legacy/vNext runtime branch exists. | Deployment and rollback process. | None for code; product may want a switch. | No test in M0.2. If a flag is later introduced, add config tests and route/service branch tests. | Not applicable. |

## Regression Details

Permanent input:

```text
120g de tilápia grelhada na manteiga
```

Forbidden:

```text
manteiga = 120g
120 * 717 / 100 = 860 kcal
```

Expected M0.2 containment behavior:

- tilapia is the primary food and owns 120g;
- grilled is preparation;
- butter is cooking fat/preparation with unknown quantity;
- the system asks for clarification or leaves butter pending;
- no registration occurs with butter at 120g;
- no historical meal item or meal-item embedding can decide the calories.

## Minimal M0.2 Test Set

- `MealMessageParsingServiceTest`: parser quantity ownership for tilapia/butter.
- `MealEstimationServiceTest`: no history source, no global history reuse, no butter 120g calculation, catalog reference wins over history.
- `NutritionistAgentTest`: `get_similar_items` is not part of estimation guidance/tool exposure.
- `MealServiceTest` or new focused test: global meal item fallback is disabled for nutrition decisions.
- `RegisterMealToolTest`: unchanged in M0.2 unless containment touches registration, but M3 must replace plain calorie lines.

## M0.2 Non-Goals

- Do not implement `MealEntry`, `MealComponent`, or `NutritionEstimate`.
- Do not create catalog tables.
- Do not build template, correction, or label evidence workflows.
- Do not delete legacy classes or migrations.
- Do not migrate or clean legacy test data.
