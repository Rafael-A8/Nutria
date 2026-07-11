<?php

use App\Nutrition\Application\Catalog\NormalizeFoodText;
use App\Nutrition\Domain\Catalog\Contracts\FoodCatalogCandidateRepository;
use App\Nutrition\Domain\Catalog\ValueObjects\FoodReferenceId;
use App\Nutrition\Domain\Catalog\ValueObjects\FoodReferenceVersionId;
use App\Nutrition\Domain\Catalog\ValueObjects\FoodResolutionCandidate;
use App\Nutrition\Domain\Catalog\ValueObjects\NormalizedFoodText;
use App\Nutrition\Domain\Drafts\MealComponentDraft;
use App\Nutrition\Domain\Resolution\Contracts\MealComponentResolver;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolution;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolutionRequest;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolutionTrace;

arch('M2.2 domain contracts remain framework and authority free')
    ->expect([
        'App\Nutrition\Domain\Catalog',
        'App\Nutrition\Domain\Resolution',
    ])->not->toUse([
        'Illuminate',
        'Laravel',
        'App\Models',
        'App\Services',
        'App\Ai',
        'App\Nutrition\Infrastructure',
    ]);

arch('M2.2 normalization avoids runtime authorities and facades')
    ->expect(NormalizeFoodText::class)
    ->not->toUse([
        'Illuminate\Support\Facades',
        'Laravel\Ai',
        'App\Models',
        'App\Services',
        'App\Ai',
        'App\Nutrition\Infrastructure',
    ]);

it('keeps all concrete M2.2 domain contracts final and readonly', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
})->with([
    FoodReferenceId::class,
    FoodReferenceVersionId::class,
    NormalizedFoodText::class,
    FoodResolutionCandidate::class,
    FoodResolutionRequest::class,
    FoodResolution::class,
    FoodResolutionTrace::class,
]);

it('keeps repository and resolver boundaries as interfaces', function (string $interface) {
    expect((new ReflectionClass($interface))->isInterface())->toBeTrue();
})->with([
    FoodCatalogCandidateRepository::class,
    MealComponentResolver::class,
]);

it('exposes only resolve on the meal component resolver contract', function () {
    $reflection = new ReflectionClass(MealComponentResolver::class);
    $method = $reflection->getMethod('resolve');

    expect(array_map(fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods()))
        ->toBe(['resolve'])
        ->and($method->getParameters())->toHaveCount(1)
        ->and($method->getParameters()[0]->getType()?->getName())->toBe(FoodResolutionRequest::class)
        ->and($method->getReturnType()?->getName())->toBe(FoodResolution::class);
});

it('keeps exact candidate retrieval and owner-scope exclusion explicit in the repository contract', function () {
    $reflection = new ReflectionClass(FoodCatalogCandidateRepository::class);

    expect(array_map(fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods()))
        ->toBe([
            'findExactCandidates',
            'hasExactMatchExcludedByOwnerScope',
        ])
        ->and($reflection->getMethod('findExactCandidates')->getReturnType()?->getName())->toBe('array')
        ->and($reflection->getMethod('hasExactMatchExcludedByOwnerScope')->getReturnType()?->getName())->toBe('bool');
});

it('requires a complete meal component draft at the request boundary', function () {
    $constructor = (new ReflectionClass(FoodResolutionRequest::class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->getParameters()[0]->getType()?->getName())->toBe(MealComponentDraft::class);
});

it('keeps calorie, conversion, confidence, history, and embedding data out of candidates and results', function (string $class) {
    $propertyNames = array_map(
        fn (ReflectionProperty $property): string => mb_strtolower($property->getName()),
        (new ReflectionClass($class))->getProperties(),
    );
    $forbiddenFragments = [
        'calorie',
        'energy',
        'formula',
        'gram',
        'estimate',
        'confidence',
        'history',
        'embedding',
        'similarity',
        'quantity',
    ];

    foreach ($forbiddenFragments as $forbiddenFragment) {
        expect(collect($propertyNames)->contains(
            fn (string $propertyName): bool => str_contains($propertyName, $forbiddenFragment),
        ))->toBeFalse();
    }
})->with([
    FoodResolutionCandidate::class,
    FoodResolution::class,
]);

it('does not encode food-specific cooking-fat rules in the new contracts', function () {
    $projectRoot = dirname(__DIR__, 4);
    $files = [
        ...(glob("{$projectRoot}/app/Nutrition/Domain/Catalog/**/*.php") ?: []),
        ...(glob("{$projectRoot}/app/Nutrition/Domain/Resolution/**/*.php") ?: []),
        "{$projectRoot}/app/Nutrition/Application/Catalog/NormalizeFoodText.php",
    ];
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        array_filter($files, is_file(...)),
    ));

    expect($source)->not->toMatch('/til[aá]pia|manteiga|butter/i');
});
