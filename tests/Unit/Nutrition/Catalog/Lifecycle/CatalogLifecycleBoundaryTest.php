<?php

use App\Nutrition\Domain\Catalog\Lifecycle\Contracts\CatalogLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodSourceLifecyclePolicy;

function lifecycleDomainFilesForM2342(): array
{
    $root = dirname(__DIR__, 5).'/app/Nutrition/Domain/Catalog/Lifecycle';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    $files = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

arch('M2.3.4.2 lifecycle domain remains framework and infrastructure free')
    ->expect('App\Nutrition\Domain\Catalog\Lifecycle')
    ->not->toUse([
        'Illuminate',
        'Laravel',
        'App\Models',
        'App\Services',
        'App\Ai',
        'App\Nutrition\Application',
        'App\Nutrition\Infrastructure',
    ]);

it('keeps all entity policies final and behind the shared contract', function (string $policy) {
    $reflection = new ReflectionClass($policy);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->implementsInterface(CatalogLifecyclePolicy::class))->toBeTrue()
        ->and($reflection->getMethod('evaluate')->getNumberOfParameters())->toBe(2);
})->with([
    FoodReferenceVersionLifecyclePolicy::class,
    FoodAliasLifecyclePolicy::class,
    FoodPortionLifecyclePolicy::class,
    FoodSourceLifecyclePolicy::class,
    FoodReferenceLifecyclePolicy::class,
]);

it('contains exactly the approved lifecycle domain files', function () {
    $relativeFiles = array_map(
        fn (string $file): string => substr($file, strpos($file, 'app/Nutrition/Domain/Catalog/Lifecycle/')),
        lifecycleDomainFilesForM2342(),
    );

    expect($relativeFiles)->toHaveCount(21)
        ->and($relativeFiles)->toBe([
            'app/Nutrition/Domain/Catalog/Lifecycle/Contracts/CatalogLifecyclePolicy.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Contracts/CatalogLifecycleSnapshot.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Enums/AliasKind.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Enums/CatalogLifecycleOperation.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Enums/CatalogLifecycleOutcome.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Enums/CatalogLifecycleReason.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Enums/CatalogLifecycleState.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Enums/CatalogLifecycleSubjectType.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Policies/FoodAliasLifecyclePolicy.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Policies/FoodPortionLifecyclePolicy.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Policies/FoodReferenceLifecyclePolicy.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Policies/FoodReferenceVersionLifecyclePolicy.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/Policies/FoodSourceLifecyclePolicy.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/CatalogEligibilityResult.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/CatalogLifecycleCommand.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/CatalogLifecycleResult.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/FoodAliasLifecycleSnapshot.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/FoodPortionLifecycleSnapshot.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/FoodReferenceLifecycleSnapshot.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/FoodReferenceVersionLifecycleSnapshot.php',
            'app/Nutrition/Domain/Catalog/Lifecycle/ValueObjects/FoodSourceLifecycleSnapshot.php',
        ]);
});

it('contains no persistence runtime or nutrition authority behavior', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        lifecycleDomainFilesForM2342(),
    ));

    expect($source)
        ->not->toMatch('/Eloquent|Model::|DB::|database|transaction|lockForUpdate|Schema::|migration/i')
        ->not->toMatch('/event writer|event store|catalog_lifecycle_events|idempotency lookup|repository implementation/i')
        ->not->toMatch('/auth\s*\(|app\s*\(|resolve\s*\(|config\s*\(|filesystem|queue/i')
        ->not->toMatch('/Laravel\\Ai|App\\Ai|\bRAG\b|embedding|history lookup/i')
        ->not->toMatch('/calculate|calorie|NutritionEstimate|meal mutation|MealItem|MealService/i')
        ->not->toMatch('/response message|translated message|ui text|human-facing/i');
});

it('uses no PHP language feature newer than PHP 8.3', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        lifecycleDomainFilesForM2342(),
    ));

    expect($source)
        ->not->toMatch('/public\(set\)|protected\(set\)|private\(set\)|#\[\s*\\\\Override|property hooks/i');
});
