<?php

use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\CatalogImportSemanticGraphFingerprint;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportAliasIdentity;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportCandidateDecision;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportChecksums;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportIdentityResolution;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportIssueSet;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportLifecycleIdempotencyInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreparationDecision;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSelection;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSemanticGraph;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ConceptualStableKey;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogSourceLinkSemantics;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionPlanningResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedLegacyNutritionSource;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;

/** @return list<string> */
function catalogImportFilesM242(): array
{
    $root = dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Import';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('keeps every concrete import value object final readonly', function () {
    foreach ([
        CanonicalManifestChecksum::class,
        CatalogImportAliasIdentity::class,
        CatalogImportCandidateDecision::class,
        CatalogImportChecksums::class,
        CatalogImportIdentityResolution::class,
        CatalogImportIssueSet::class,
        CatalogImportLifecycleIdempotencyInput::class,
        CatalogImportManifestSchema::class,
        CatalogImportPreparationDecision::class,
        CatalogImportSelection::class,
        CatalogImportSemanticGraph::class,
        ConceptualStableKey::class,
        LegacyCatalogArtifactDescriptor::class,
        LegacyCatalogSourceLinkSemantics::class,
        LegacyNutritionPlanningResult::class,
        LoadedLegacyNutritionSource::class,
        SourceArtifactChecksum::class,
    ] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }
});

it('keeps deterministic import components final and stateless', function () {
    foreach ([
        CanonicalCatalogImportJson::class,
        CatalogImportDeterministicIdentity::class,
        CatalogImportSemanticGraphFingerprint::class,
    ] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getProperties())->toBe([]);
    }
});

it('keeps import contracts framework-free and isolated from excluded domains', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        catalogImportFilesM242(),
    ));

    expect($source)
        ->not->toMatch('/Illuminate|Laravel\\\\|Eloquent|App\\\\Models|Infrastructure\\\\|Facades|auth\s*\(|DB::|\bSchema::|::query\s*\(|->query\s*\(/i')
        ->not->toMatch('/CatalogLifecycleCommandFingerprint|LifecycleService|EventStore|SupersessionService|lockForUpdate|transaction\s*\(/i')
        ->not->toMatch('/Laravel\\\\Ai|App\\\\Ai|\bLLM\b|\bRAG\b|embedding|memory|history/i')
        ->not->toMatch('/NutritionEstimate|\bmeal_items\b|\bmeals\b|MealService|EstimateMeal/i')
        ->not->toMatch('/file_get_contents\s*\([^)]*nutrition|require\s*\([^)]*nutrition|include\s*\([^)]*nutrition/i')
        ->not->toMatch('/\bconfig\s*\(\s*[\'"]nutrition/i');
});

it('does not contain superseded M2.4.1a UUID inputs or results', function () {
    $files = [
        ...catalogImportFilesM242(),
        __DIR__.'/CatalogImportContractsTest.php',
        __DIR__.'/CatalogImportDeterminismTest.php',
        __FILE__,
    ];
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        $files,
    ));

    $supersededValues = [
        '15:leite '.'condensado',
        '1314d5d1-7924-58e9-'.'84a8-eb3093c88ecb',
        '92351422-76e1-5871-'.'8768-2c75bc0b42c3',
        'd99f3084-c9cc-5743-'.'8fd2-7b0698400538',
        '742b15f3-0248-5bf5-'.'a854-20b309d43602',
        'e3722480-ddcf-530b-'.'af71-368863c94743',
    ];

    expect($source)->not->toContain(...$supersededValues);
});

it('keeps mutable artifact evidence out of protocol constants', function () {
    expect((new ReflectionClass(LegacyCatalogArtifactDescriptor::class))->getConstants())
        ->toBe(['ARTIFACT_ID' => 'legacy_config_nutrition_v1']);
});

it('keeps M2.4.3 extraction isolated from persistence lifecycle runtime and nutrition authorities', function () {
    $productionFiles = [
        dirname(__DIR__, 5).'/app/Console/Commands/PlanLegacyCatalogImportCommand.php',
        ...catalogImportFilesM242(),
    ];
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        $productionFiles,
    ));

    expect($source)
        ->not->toMatch('/App\\\\Models|Eloquent|Infrastructure\\\\|FoodCatalogCandidateRepository|MealComponentResolver/i')
        ->not->toMatch('/DB::|Database\\\\|::query\s*\(|->query\s*\(|transaction\s*\(|lockForUpdate/i')
        ->not->toMatch('/LifecycleService|EventStore|SupersessionService|CatalogLifecycleCommandFingerprint/i')
        ->not->toMatch('/NutritionEstimate|MealService|EstimateMeal|meal_items|\bmeals\b/i')
        ->not->toMatch('/Laravel\\\\Ai|App\\\\Ai|\bLLM\b|\bRAG\b|embedding|memory|history/i')
        ->not->toMatch('/Http::|curl_|file_get_contents\s*\(\s*[\'"]https?:|eval\s*\(/i');
});

it('keeps the M2.4.3 command orchestration free of database and lifecycle calls', function () {
    $commandSource = file_get_contents(
        dirname(__DIR__, 5).'/app/Console/Commands/PlanLegacyCatalogImportCommand.php',
    ) ?: '';

    expect($commandSource)
        ->not->toMatch('/DB::|Database|Eloquent|Model|transaction|query|migrate|seed|tinker/i')
        ->not->toMatch('/Lifecycle|EventStore|Supersession|Policy|FoodSource|FoodReference|FoodAlias|FoodPortion/i')
        ->not->toMatch('/Meal|Estimate|Resolver|Laravel\\\\Ai|App\\\\Ai|\bRAG\b|embedding|memory|history/i');
});
