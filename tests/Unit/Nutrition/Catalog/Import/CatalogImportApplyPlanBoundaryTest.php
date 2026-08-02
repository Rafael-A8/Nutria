<?php

use App\Console\Commands\BuildLegacyCatalogImportApplyPlanCommand;
use App\Nutrition\Application\Catalog\Import\CatalogImportApplyPlanBuilder;
use App\Nutrition\Application\Catalog\Import\CatalogImportApprovalAttestationLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewedResolutionLoader;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSnapshot;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportResolutionApprovalSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportApproval;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;
use App\Nutrition\Infrastructure\Catalog\Import\LegacyCatalogImportApplyPlanPreparer;
use App\Nutrition\Infrastructure\Catalog\Import\ReadOnlyCatalogImportApplyPlanPreflight;

/** @return list<string> */
function applyPlanProductionFilesM244b(): array
{
    $root = dirname(__DIR__, 5);

    return [
        $root.'/app/Console/Commands/BuildLegacyCatalogImportApplyPlanCommand.php',
        ...glob($root.'/app/Nutrition/Application/Catalog/Import/*ApplyPlan*.php'),
        ...glob($root.'/app/Nutrition/Application/Catalog/Import/*Approval*.php'),
        ...glob($root.'/app/Nutrition/Application/Catalog/Import/*ReviewedResolution*.php'),
        ...glob($root.'/app/Nutrition/Application/Catalog/Import/ValueObjects/*ApplyPlan*.php'),
        ...glob($root.'/app/Nutrition/Application/Catalog/Import/ValueObjects/*Approval*.php'),
        $root.'/app/Nutrition/Application/Catalog/Import/Exceptions/LegacyNutritionApplyPlanException.php',
        $root.'/app/Nutrition/Infrastructure/Catalog/Import/LegacyCatalogImportApplyPlanPreparer.php',
        $root.'/app/Nutrition/Infrastructure/Catalog/Import/ReadOnlyCatalogImportApplyPlanPreflight.php',
    ];
}

it('keeps the command thin final and free of persistence behavior', function () {
    $reflection = new ReflectionClass(BuildLegacyCatalogImportApplyPlanCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($reflection->isFinal())->toBeTrue()
        ->and($source)->not->toMatch('/Food(Source|Reference|Alias)|DB::|::query\s*\(|transaction|lockForUpdate/i')
        ->and($source)->not->toMatch('/LifecycleService|SupersessionService|EventStore|Resolver/i');
});

it('keeps validation planning and value objects framework free while isolating Eloquent reads', function () {
    foreach ([
        CatalogImportApplyPlanBuilder::class,
        CatalogImportApprovalAttestationLoader::class,
        CatalogImportReviewedResolutionLoader::class,
    ] as $class) {
        $reflection = new ReflectionClass($class);
        $source = file_get_contents($reflection->getFileName());

        expect($reflection->isFinal())->toBeTrue()
            ->and($source)->not->toMatch('/Illuminate|Eloquent|Infrastructure\\|DB::|::query\s*\(/i');
    }

    expect((new ReflectionClass(LegacyCatalogImportApplyPlanPreparer::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(ReadOnlyCatalogImportApplyPlanPreflight::class))->isFinal())->toBeTrue();
});

it('keeps every new apply-plan value object final readonly', function () {
    foreach ([
        CatalogImportApplyPlanResult::class,
        CatalogImportApplyPlanSchema::class,
        CatalogImportApplyPlanSnapshot::class,
        CatalogImportResolutionApprovalSchema::class,
        LoadedCatalogImportApproval::class,
        LoadedCatalogImportReviewedResolution::class,
    ] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }
});

it('contains no write lifecycle supersession resolver AI HTTP or generic workflow implementation', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        applyPlanProductionFilesM244b(),
    ));

    expect($source)
        ->not->toMatch('/->(insert|update|delete|upsert|save|create)\s*\(|DB::(insert|update|delete|statement|unprepared)/i')
        ->not->toMatch('/lockForUpdate|transaction\s*\(|LifecycleService|SupersessionService|EventStore/i')
        ->not->toMatch('/MealComponentResolver|NutritionEstimate|MealService|\bmeals\b|meal_items/i')
        ->not->toMatch('/Http::|curl_|\bAI\b|\bLLM\b|\bRAG\b|embedding|vector search|memory|history/i')
        ->not->toMatch('/Generic.*(Importer|Approval|Repository|Workflow)|WorkflowEngine/i');
});

it('reuses the committed canonical serializer normalizer identities and source UUID', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        applyPlanProductionFilesM244b(),
    ));

    expect($source)->toContain(
        'CanonicalCatalogImportJson',
        'NormalizeFoodText',
        'CatalogImportDeterministicIdentity',
        'CatalogImportSemanticGraphFingerprint',
    )->and($source)->not->toMatch('/class .*Normalizer|class .*Canonical.*Serializer/i')
        ->and(CatalogImportApplyPlanBuilder::SOURCE_PUBLIC_ID)->toBe('ead17ec3-6176-5f48-b25c-6f4ce3ce9907');
});
