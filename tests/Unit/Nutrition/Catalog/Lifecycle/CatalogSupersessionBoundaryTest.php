<?php

use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleDerivedEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\FoodAliasSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodPortionSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogActiveReplacementResult;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogSuccessorCreationResult;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;

it('contains exactly three explicit entity supersession services with the frozen dependency boundary', function () {
    $directory = dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle';
    $files = glob($directory.'/*SupersessionService.php');
    sort($files);

    expect(array_map('basename', $files))->toBe([
        'FoodAliasSupersessionService.php',
        'FoodPortionSupersessionService.php',
        'FoodReferenceVersionSupersessionService.php',
    ]);

    $expectedPolicies = [
        FoodReferenceVersionSupersessionService::class => FoodReferenceVersionLifecyclePolicy::class,
        FoodAliasSupersessionService::class => FoodAliasLifecyclePolicy::class,
        FoodPortionSupersessionService::class => FoodPortionLifecyclePolicy::class,
    ];

    foreach ($expectedPolicies as $service => $policy) {
        $reflection = new ReflectionClass($service);
        $constructorTypes = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getType()->getName(),
            $reflection->getConstructor()->getParameters(),
        );
        $publicMethods = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $service && $method->getName() !== '__construct',
            ),
        );
        sort($publicMethods);

        expect($reflection->isFinal())->toBeTrue()
            ->and($constructorTypes)->toBe([
                $policy,
                CatalogLifecycleEventStore::class,
                CatalogLifecycleReplayGuard::class,
                CatalogLifecycleRootEventFactory::class,
                CatalogLifecycleDerivedEventFactory::class,
                CatalogLifecycleProjectionStateResolver::class,
            ])
            ->and($publicMethods)->toBe(['activateSuccessorReplacingCurrent', 'createSuccessor']);
    }
});

it('keeps transactions locking policies idempotency and correlated events explicit without forbidden concerns', function () {
    $services = [
        FoodReferenceVersionSupersessionService::class,
        FoodAliasSupersessionService::class,
        FoodPortionSupersessionService::class,
    ];
    $forbidden = [
        'auth(', 'CatalogSupersessionService', 'ReflectionClass', 'DB::table(', 'app(', 'resolve(',
        'NutritionEstimate', 'embedding', 'RAG', 'meal', 'history', 'calorie', 'authorization',
    ];

    foreach ($services as $service) {
        $source = file_get_contents((new ReflectionClass($service))->getFileName());

        expect(substr_count($source, 'DB::transaction'))->toBe(2)
            ->and(substr_count($source, 'attempts: 3'))->toBe(2)
            ->and($source)->toContain('lockForUpdate()', 'replayGuard->replay', 'policy->evaluate', 'storeRoot(', 'appendDerived(')
            ->and($source)->toContain('rootEventFactory->create', 'derivedEventFactory->create');

        foreach ($forbidden as $term) {
            expect($source)->not->toContain($term);
        }
    }
});

it('keeps result objects readonly model-free and outside infrastructure', function (string $resultClass) {
    $reflection = new ReflectionClass($resultClass);
    $source = file_get_contents($reflection->getFileName());

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->getNamespaceName())->toStartWith('App\\Nutrition\\Application\\Catalog\\Lifecycle\\ValueObjects')
        ->and($source)->not->toContain('Eloquent', 'Model');
})->with([
    CatalogSuccessorCreationResult::class,
    CatalogActiveReplacementResult::class,
]);

it('does not introduce a generic service provider binding or supersession migration', function () {
    $root = dirname(__DIR__, 5);
    $applicationSource = collect(glob($root.'/app/Nutrition/Application/Catalog/Lifecycle/*.php'))
        ->map(fn (string $file): string => file_get_contents($file))
        ->implode("\n");
    $providerSource = collect(glob($root.'/app/Providers/*.php'))
        ->map(fn (string $file): string => file_get_contents($file))
        ->implode("\n");
    $migrationNames = array_map('basename', glob($root.'/database/migrations/*.php'));

    expect($applicationSource)->not->toContain('class CatalogSupersessionService')
        ->and($providerSource)->not->toContain('SupersessionService')
        ->and(implode("\n", $migrationNames))->not->toContain('supersession');
});
