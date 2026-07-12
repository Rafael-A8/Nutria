<?php

use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
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
    ->expect('App\Nutrition\Infrastructure\Catalog\Eloquent')
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
    $modelDirectory = dirname(__DIR__, 4).'/app/Nutrition/Infrastructure/Catalog/Eloquent';
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        catalogEloquentFilesForM233($modelDirectory),
    ));

    expect($source)
        ->not->toMatch('/\bHasUuids\b|ObservedBy|::observe\s*\(|addGlobalScope|booted?\s*\(/')
        ->not->toMatch('/NormalizeFoodText|calculateCalories|NutritionEstimate/')
        ->not->toMatch('/embedding|\bRAG\b|Laravel\\\\Ai|App\\\\Ai|config\s*\(|nutrition\.php/i')
        ->not->toMatch('/MealItem|App\\\\Models\\\\Meal|Repository|Resolver|ServiceProvider|::bind\s*\(/');
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
