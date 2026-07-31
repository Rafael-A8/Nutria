<?php

use App\Console\Commands\PrepareLegacyCatalogImportReviewCommand;
use App\Nutrition\Infrastructure\Catalog\Import\LegacyCatalogImportReviewPreparer;
use App\Nutrition\Infrastructure\Catalog\Import\ReadOnlyCatalogImportPreflight;

function catalogImportReviewProductionFilesM244a(): array
{
    $root = dirname(__DIR__, 5);

    return [
        $root.'/app/Console/Commands/PrepareLegacyCatalogImportReviewCommand.php',
        ...array_values(array_filter(
            glob($root.'/app/Nutrition/Application/Catalog/Import/*Review*.php') ?: [],
            is_file(...),
        )),
        ...array_values(array_filter(
            glob($root.'/app/Nutrition/Application/Catalog/Import/Enums/*Review*.php') ?: [],
            is_file(...),
        )),
        $root.'/app/Nutrition/Application/Catalog/Import/ApprovedLegacyNutritionReviewManifestValidator.php',
        $root.'/app/Nutrition/Application/Catalog/Import/CatalogImportPreflightReport.php',
        $root.'/app/Nutrition/Application/Catalog/Import/ValueObjects/CatalogImportPreflightResult.php',
        $root.'/app/Nutrition/Application/Catalog/Import/ValueObjects/CatalogImportResolutionSchema.php',
        $root.'/app/Nutrition/Application/Catalog/Import/ValueObjects/CatalogImportReviewPreparationResult.php',
        $root.'/app/Nutrition/Infrastructure/Catalog/Import/LegacyCatalogImportReviewPreparer.php',
        $root.'/app/Nutrition/Infrastructure/Catalog/Import/ReadOnlyCatalogImportPreflight.php',
    ];
}

it('keeps the command final thin and narrowly scoped', function () {
    $reflection = new ReflectionClass(PrepareLegacyCatalogImportReviewCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($reflection->isFinal())->toBeTrue()
        ->and($source)->not->toMatch('/FoodReference::|FoodAlias::|FoodSource::|DB::|transaction|lockForUpdate/i')
        ->and($source)->not->toMatch('/Lifecycle|Supersession|Resolver|Http::|Laravel\\\\Ai|embedding|memory/i');
});

it('isolates read-only catalog access inside infrastructure', function () {
    $preparer = new ReflectionClass(LegacyCatalogImportReviewPreparer::class);
    $preflight = new ReflectionClass(ReadOnlyCatalogImportPreflight::class);
    $applicationFiles = array_filter(
        catalogImportReviewProductionFilesM244a(),
        fn (string $file): bool => str_contains($file, '/Application/'),
    );
    $applicationSource = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        $applicationFiles,
    ));

    expect($preparer->isFinal())->toBeTrue()
        ->and($preflight->isFinal())->toBeTrue()
        ->and($applicationSource)->not->toMatch('/Eloquent|Infrastructure\\\\|DB::|::query\s*\(/i');
});

it('contains no persistence workflow external authority or generic framework behavior', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        catalogImportReviewProductionFilesM244a(),
    ));

    expect($source)
        ->not->toMatch('/->(insert|update|delete|upsert|save|create)\s*\(|DB::(insert|update|delete|statement|unprepared)/i')
        ->not->toMatch('/lockForUpdate|transaction\s*\(|LifecycleService|EventStore|SupersessionService/i')
        ->not->toMatch('/FoodCatalogCandidateRepository|MealComponentResolver|runtime resolver/i')
        ->not->toMatch('/Http::|curl_|\bAI\b|\bLLM\b|\bRAG\b|embedding|memory|history/i')
        ->not->toMatch('/interface .*Repository|Generic.*Review|WorkflowEngine|IdentityResolutionEngine/i');
});

it('does not modify inherited M2.4.2 or M2.4.3 production contracts', function () {
    $projectRoot = dirname(__DIR__, 5);
    $inheritedFiles = [
        $projectRoot.'/app/Console/Commands/PlanLegacyCatalogImportCommand.php',
        $projectRoot.'/app/Nutrition/Application/Catalog/Import/CanonicalCatalogImportJson.php',
        $projectRoot.'/app/Nutrition/Application/Catalog/Import/CatalogImportDeterministicIdentity.php',
        $projectRoot.'/app/Nutrition/Application/Catalog/Import/LegacyNutritionCandidateManifestGenerator.php',
        $projectRoot.'/app/Nutrition/Application/Catalog/Import/ValueObjects/CatalogImportIdentityResolution.php',
        $projectRoot.'/app/Nutrition/Application/Catalog/Import/ValueObjects/CatalogImportManifestSchema.php',
    ];
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        $inheritedFiles,
    ));

    expect($source)->not->toContain(
        'nutria.catalog-import-resolution/1',
        'ReadOnlyCatalogImportPreflight',
        'CatalogImportReviewEligibilityValidator',
    );
});
