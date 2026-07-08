# M0.2 Legacy Nutrition Containment Report

## Scope

Implemented only M0.2 containment. No vNext domain tables, migrations, target architecture, correction workflows, label workflows, or `nutrition_v2_enabled` flag were added.

## Files Changed

- `app/Services/MealEstimationService.php`
- `app/Services/MealAmbiguityService.php`
- `app/Services/MealMessageParsingService.php`
- `app/Services/MealService.php`
- `app/Ai/Agents/NutritionistAgent.php`
- `tests/Feature/MealEstimationServiceTest.php`
- `tests/Feature/MealMessageParsingServiceTest.php`
- `tests/Feature/MealServiceTest.php`
- `tests/Feature/NutritionistAgentTest.php`
- `tests/Feature/EstimateMealToolTest.php`
- `tests/Feature/MealAmbiguityServiceTest.php`

## Risks Contained

1. Meal history is no longer a calorie source in `MealEstimationService`.
   - `estimateItem()` no longer calls semantic history lookup.
   - Historical `meal_items` no longer override configured reference calories.
   - Historical proportional scaling is no longer reachable from the estimation path.
   - Estimation tests now assert `MealService::findSimilarItems()` is never called.

2. Global meal-history reuse is disabled.
   - `MealService::findSimilarItems()` now returns only the current user's own historical items.
   - Other users' `meal_items` are not returned as fallback examples.
   - This method remains available only as user-scoped event recall for legacy tool usage.

3. `get_similar_items` is removed from nutrition-agent decisions.
   - The prompt no longer instructs the model to call `get_similar_items`.
   - `NutritionistAgent::tools()` no longer exposes `GetSimilarItemsTool`.
   - The legacy tool class was not deleted.

4. Cooking-fat ambiguity is stricter.
   - Cooking fats with no explicit or resolvable quantity now require clarification.
   - Cooking-fat `default_grams` no longer masks unknown quantity.
   - Explicit small preparation quantities still estimate through retention, e.g. `1 colher de cha de azeite`.

5. Tilapia-in-butter regression is contained.
   - Parser-level containment keeps `120g` on tilapia and leaves butter quantity unknown.
   - Flattened estimator input `description=tilapia grelhada na manteiga; quantity_grams=120` now requires clarification.
   - `estimate_meal` no longer returns registration-ready butter as `120g` / `860 kcal`.

6. Runtime lineage logging was added.
   - Each estimated item logs `source`, normalized description, calculation line, and reference key when available.
   - No history item id is logged because history is no longer reachable from estimation.
   - Full raw user messages are not logged by this lineage event.

## Behavior Changes

- Known foods estimate from `config('nutrition.estimation.references')` or structured AI fallback for unknown foods.
- `source=user_history` is no longer produced by `MealEstimationService`.
- Butter, oil, azeite, and similar cooking fats without quantity now block registration and ask for clarification.
- `120g de tilapia grelhada na manteiga` is parsed as:
  - `tilapia`, `quantity_grams=120`
  - `manteiga`, quantity unknown, context `usada no preparo`
- A single flattened item `tilapia grelhada na manteiga` with `quantity_grams=120` returns clarification instead of applying 120g to butter.

## Tests Added or Updated

- `MealEstimationServiceTest`
  - All mocked meal-history search expectations changed to `never()`.
  - Added cooking-fat unknown quantity clarification.
  - Added flattened tilapia/butter containment.
  - Replaced historical scaling test with no-history-source assertion.
  - Added explicit no-`findSimilarItems()` estimation test.
  - Added lineage logging assertion with no history item id.

- `MealMessageParsingServiceTest`
  - Added parser regression for `120g de tilapia grelhada na manteiga`.

- `MealServiceTest`
  - Added assertion that other users' similar items are not returned.

- `NutritionistAgentTest`
  - Added prompt assertion that `get_similar_items` is absent.
  - Added tool-list assertion that `GetSimilarItemsTool` is not exposed.

- `EstimateMealToolTest`
  - Added tool-level regression proving no registration-ready butter `120g` / `860 kcal` output.

- `MealAmbiguityServiceTest`
  - Added cooking-fat-without-quantity clarification assertion.

## Focused Test Command Run

```bash
./vendor/bin/sail artisan test --compact tests/Feature/MealEstimationServiceTest.php tests/Feature/MealMessageParsingServiceTest.php tests/Feature/MealServiceTest.php tests/Feature/NutritionistAgentTest.php tests/Feature/EstimateMealToolTest.php tests/Feature/MealAmbiguityServiceTest.php
```

Result: 63 passed, 441 assertions.

## Deferred to M1/M2/M3

- Canonical nutrition domain model.
- Deterministic ingredient decomposition.
- Calorie estimate lineage tables.
- Versioned food catalog.
- Correction workflow.
- Label/photo workflows.
- Audit tables and migration/backfill strategy.
- Full replacement of legacy prompt-driven nutrition flow.

## Unresolved Legacy Risks

- Unknown foods can still use structured AI fallback when the item and quantity are specific enough.
- Config references remain a flat legacy calorie table, not a governed food catalog.
- Cooking-fat containment is conservative and may ask for clarification in some mixed-food cases that a future deterministic parser could resolve.
- `GetSimilarItemsTool` still exists as legacy code, but it is no longer exposed to `NutritionistAgent`.
- `MealEstimationService` still accepts a `MealService` dependency for compatibility, though estimation no longer uses it.
