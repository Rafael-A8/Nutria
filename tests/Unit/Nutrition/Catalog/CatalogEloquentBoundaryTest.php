<?php

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

function catalogEloquentModelClassesForM233(): array
{
    return [
        FoodSource::class,
        FoodReference::class,
        FoodReferenceVersion::class,
        FoodReferenceVersionSource::class,
        FoodAlias::class,
        FoodPortion::class,
        CatalogLifecycleEvent::class,
    ];
}

function catalogEloquentFilesForM233(string $directory): array
{
    return glob($directory.'/*.php') ?: [];
}

arch('catalog persistence enums remain framework free')
    ->expect([
        CatalogReviewStatus::class,
        FoodSourceKind::class,
        FoodSourceAuthorityStatus::class,
        FoodReferenceVersionSourceRole::class,
    ])->not->toUse([
        'Illuminate',
        'Laravel',
        'App\Models',
        'App\Ai',
        'App\Services',
        'App\Nutrition\Infrastructure',
    ]);

arch('catalog Eloquent records stay in infrastructure')
    ->expect(catalogEloquentModelClassesForM233())
    ->toExtend(Model::class)
    ->not->toUse([
        'Laravel\Ai',
        'App\Ai',
        'App\Services',
        'App\Nutrition\Application',
        'App\Nutrition\Domain\Resolution',
    ]);

it('keeps catalog models free of automatic UUID and lifecycle behavior', function (string $modelClass) {
    $reflection = new ReflectionClass($modelClass);
    $declaredMethods = collect($reflection->getMethods())
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $modelClass)
        ->map(fn (ReflectionMethod $method): string => mb_strtolower($method->getName()))
        ->values()
        ->all();

    expect(class_uses_recursive($modelClass))->not->toContain(HasUuids::class)
        ->and($declaredMethods)->not->toContain(
            'boot',
            'booted',
            'activate',
            'approve',
            'publish',
            'review',
            'withdraw',
            'supersede',
            'resolve',
            'calculatecalories',
        )
        ->and((new $modelClass)->getGlobalScopes())->toBe([]);
})->with(catalogEloquentModelClassesForM233());

it('keeps model source free of forbidden runtime wiring', function () {
    $source = implode("\n", array_map(
        function (string $modelClass): string {
            $file = (new ReflectionClass($modelClass))->getFileName();

            return file_get_contents($file) ?: '';
        },
        catalogEloquentModelClassesForM233(),
    ));

    expect($source)
        ->not->toMatch('/\bHasUuids\b|ObservedBy|::observe\s*\(|addGlobalScope|booted?\s*\(/')
        ->not->toMatch('/CatalogLifecycleCommand|LifecycleService|App\\\\Nutrition\\\\Application/')
        ->not->toMatch('/NormalizeFoodText|calculateCalories|NutritionEstimate/')
        ->not->toMatch('/embedding|\bRAG\b|Laravel\\\\Ai|App\\\\Ai|config\s*\(|nutrition\.php/i')
        ->not->toMatch('/MealItem|App\\\\Models\\\\Meal|Repository|Resolver|ServiceProvider|::bind\s*\(/');
});

it('keeps the lifecycle event store as a separate infrastructure adapter', function () {
    $reflection = new ReflectionClass(EloquentCatalogLifecycleEventStore::class);
    $source = file_get_contents($reflection->getFileName()) ?: '';

    expect($reflection->isSubclassOf(Model::class))->toBeFalse()
        ->and($reflection->implementsInterface(CatalogLifecycleEventStore::class))->toBeTrue()
        ->and($reflection->getNamespaceName())->toBe('App\Nutrition\Infrastructure\Catalog\Eloquent')
        ->and($source)->not->toMatch('/App\\\\Http|Controller|Route::|auth\s*\(|Auth::|App\\\\Ai|Laravel\\\\Ai|\bRAG\b/i')
        ->and($source)->not->toMatch('/MealItem|App\\\\Models\\\\Meal|NutritionEstimate|Resolver|ImportService|Legacy/i')
        ->and($source)->not->toMatch('/FoodSource|FoodReference|FoodReferenceVersion|FoodAlias|FoodPortion/')
        ->and($source)->not->toMatch('/DB::transaction|beginTransaction|commit\s*\(|rollBack\s*\(/i')
        ->and($source)->not->toMatch('/->update\s*\(|->save\s*\(|->delete\s*\(|increment\s*\(|decrement\s*\(/i')
        ->and($source)->not->toMatch('/ObservedBy|::observe\s*\(|addGlobalScope|booted?\s*\(/');
});

it('keeps factories explicit and isolated from runtime data sources', function () {
    $factoryDirectory = dirname(__DIR__, 4).'/database/factories/Nutrition/Catalog';
    $factorySources = collect(catalogEloquentFilesForM233($factoryDirectory))
        ->mapWithKeys(fn (string $file): array => [basename($file) => file_get_contents($file) ?: '']);

    foreach ([
        'FoodSourceFactory.php',
        'FoodReferenceFactory.php',
        'FoodReferenceVersionFactory.php',
        'FoodAliasFactory.php',
        'FoodPortionFactory.php',
    ] as $identityFactory) {
        expect($factorySources[$identityFactory])->toContain('Str::uuid7()');
    }

    expect($factorySources->implode("\n"))
        ->not->toMatch('/Seeder|DatabaseSeeder|config\s*\(|nutrition\.php/i')
        ->not->toMatch('/MealItem|App\\\\Models\\\\Meal|embedding|Laravel\\\\Ai|App\\\\Ai/i')
        ->not->toMatch('/LifecycleService|ApprovalService|PublicationService|ActivationService|ImportService/');
});
