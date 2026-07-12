<?php

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\FoodAliasLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodPortionLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodSourceLifecycleService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;

function serviceFilesM2344(): array
{
    $root = dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle';

    return collect(glob($root.'/*LifecycleService.php'))->sort()->values()->all();
}

it('contains exactly five final entity-specific lifecycle services under Application', function () {
    $services = [
        FoodAliasLifecycleService::class,
        FoodPortionLifecycleService::class,
        FoodReferenceLifecycleService::class,
        FoodReferenceVersionLifecycleService::class,
        FoodSourceLifecycleService::class,
    ];

    expect(serviceFilesM2344())->toHaveCount(5);

    foreach ($services as $service) {
        $reflection = new ReflectionClass($service);
        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getNamespaceName())->toBe('App\Nutrition\Application\Catalog\Lifecycle');
    }
});

it('uses the exact five constructor boundaries on every service', function (string $service, string $policy) {
    $parameters = (new ReflectionClass($service))->getConstructor()?->getParameters() ?? [];
    $types = array_map(fn (ReflectionParameter $parameter): string => $parameter->getType()?->getName() ?? '', $parameters);

    expect($types)->toBe([
        $policy,
        CatalogLifecycleEventStore::class,
        'App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard',
        'App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory',
        'App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver',
    ]);
})->with([
    [FoodReferenceVersionLifecycleService::class, 'App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy'],
    [FoodAliasLifecycleService::class, 'App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy'],
    [FoodPortionLifecycleService::class, 'App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy'],
    [FoodSourceLifecycleService::class, 'App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodSourceLifecyclePolicy'],
    [FoodReferenceLifecycleService::class, 'App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceLifecyclePolicy'],
]);

it('exposes only operation-specific public APIs', function (string $service, array $expectedMethods) {
    $methods = collect((new ReflectionClass($service))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $service)
        ->pluck('name')->sort()->values()->all();

    expect($methods)->toBe(collect(['__construct', ...$expectedMethods])->sort()->values()->all())
        ->not->toContain('execute');
})->with([
    [FoodReferenceVersionLifecycleService::class, ['submitForReview', 'returnToDraft', 'approve', 'reject', 'publish', 'activate', 'reactivate', 'deactivate', 'withdraw', 'archive']],
    [FoodAliasLifecycleService::class, ['submitForReview', 'returnToDraft', 'approve', 'reject', 'publish', 'activate', 'reactivate', 'deactivate', 'withdraw', 'archive']],
    [FoodPortionLifecycleService::class, ['submitForReview', 'returnToDraft', 'approve', 'reject', 'publish', 'activate', 'reactivate', 'deactivate', 'withdraw', 'archive']],
    [FoodSourceLifecycleService::class, ['changeAuthority', 'archive']],
    [FoodReferenceLifecycleService::class, ['archive']],
]);

it('owns transactions locks and event persistence without forbidden runtime concerns', function () {
    $source = implode("\n", array_map(fn (string $file): string => file_get_contents($file) ?: '', serviceFilesM2344()));

    expect($source)
        ->toContain('DB::transaction', 'attempts: 3', 'lockForUpdate', 'storeRoot')
        ->not->toContain('App\Models', 'Laravel\Ai', 'App\Ai')
        ->not->toMatch('/\bauth\s*\(|ServiceProvider|bind\s*\(|singleton\s*\(/i')
        ->not->toMatch('/nextVersion|nextRevision|allocate.*number|replaceActive/i')
        ->not->toMatch('/\bRAG\b|embedding|history|meal|calorie|NutritionEstimate|legacy config/i')
        ->not->toMatch('/resolver repositories|DeterministicFoodResolver|FoodResolver|importer/i');
});

it('keeps execution results readonly and free of Eloquent models', function () {
    $reflection = new ReflectionClass(CatalogLifecycleExecutionResult::class);
    $types = collect($reflection->getConstructor()?->getParameters())
        ->map(fn (ReflectionParameter $parameter): string => $parameter->getType()?->getName() ?? '')
        ->all();

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and(implode(' ', $types))->not->toContain('Eloquent', 'Model');
});
