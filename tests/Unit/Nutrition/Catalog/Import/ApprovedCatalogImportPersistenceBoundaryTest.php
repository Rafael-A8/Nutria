<?php

use App\Console\Commands\ApplyApprovedLegacyCatalogImportCommand;
use App\Nutrition\Application\Catalog\Import\ApprovedCatalogImportApplyPlanLoader;
use App\Nutrition\Application\Catalog\Import\ApprovedCatalogImportArtifactsLoader;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportApplyResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportExecutionInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportGraphInspection;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedApprovedCatalogImportArtifacts;
use App\Nutrition\Application\Catalog\Persistence\ApplyApprovedLegacyCatalogImport;
use App\Nutrition\Infrastructure\Catalog\Import\ApprovedCatalogImportGraphInspector;
use App\Nutrition\Infrastructure\Catalog\Import\ApprovedCatalogImportTransactionalGraphWriter;

function sourceForM245(string $relativePath): string
{
    return file_get_contents(dirname(__DIR__, 5).'/'.$relativePath);
}

it('exposes every required typed operational outcome', function () {
    expect(array_column(ApprovedCatalogImportOutcome::cases(), 'value'))->toBe([
        'applied',
        'no_op_replay',
        'artifact_invalid',
        'source_drift',
        'actor_invalid',
        'catalog_drift',
        'catalog_conflict',
        'persistence_failed',
        'post_write_verification_failed',
    ]);
});

it('keeps the command use case inspector and writer narrow and final', function () {
    foreach ([
        ApplyApprovedLegacyCatalogImportCommand::class,
        ApplyApprovedLegacyCatalogImport::class,
        ApprovedCatalogImportApplyPlanLoader::class,
        ApprovedCatalogImportArtifactsLoader::class,
        ApprovedCatalogImportGraphInspector::class,
        ApprovedCatalogImportTransactionalGraphWriter::class,
    ] as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeTrue();
    }

    foreach ([
        ApprovedCatalogImportApplyResult::class,
        ApprovedCatalogImportExecutionInput::class,
        ApprovedCatalogImportGraphInspection::class,
        LoadedApprovedCatalogImportArtifacts::class,
    ] as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }
});

it('uses one outer transaction attempt and no alternate mutation mechanism', function () {
    $useCase = sourceForM245('app/Nutrition/Application/Catalog/Persistence/ApplyApprovedLegacyCatalogImport.php');
    $writer = sourceForM245('app/Nutrition/Infrastructure/Catalog/Import/ApprovedCatalogImportTransactionalGraphWriter.php');
    $command = sourceForM245('app/Console/Commands/ApplyApprovedLegacyCatalogImportCommand.php');
    $combined = $useCase.$writer.$command;

    expect($useCase)->toContain('transaction(function ()', 'attempts: 1')
        ->not->toContain('attempts: 2', 'attempts: 3', 'lockForUpdate', 'advisory')
        ->and($writer)->toContain(
            'FoodSourceLifecyclePolicy',
            'FoodReferenceLifecyclePolicy',
            'FoodReferenceVersionLifecyclePolicy',
            'FoodAliasLifecyclePolicy',
            'CatalogImportDeterministicIdentity::lifecycleIdempotencyKey',
            'CatalogLifecycleRootEventFactory',
        )
        ->and($combined)->not->toMatch('/\b(update|delete|upsert|merge|truncate)\s*\(/i')
        ->and($combined)->not->toContain(
            'Seeder',
            'Observer',
            'ShouldQueue',
            'Http::',
            'AI',
            'Embedding',
            'Meal',
            'Resolver',
            'FoodPortion::query()->create',
        );
});

it('keeps replay scoped to persisted approved identities while retaining strict post-write counts', function () {
    $inspector = sourceForM245('app/Nutrition/Infrastructure/Catalog/Import/ApprovedCatalogImportGraphInspector.php');
    $useCase = sourceForM245('app/Nutrition/Application/Catalog/Persistence/ApplyApprovedLegacyCatalogImport.php');

    expect($inspector)
        ->toContain(
            'return $this->inspectGraph($artifacts, false);',
            'return $this->inspectGraph($artifacts, true);',
            "->whereIn('subject_public_id', \$subjectPublicIds)",
        )
        ->not->toContain('ApprovedCatalogImportExecutionInput')
        ->and($useCase)->toContain('$this->inspector->inspectPostWrite($artifacts)');
});

it('does not modify forbidden catalog contracts or approved artifacts', function () {
    $status = shell_exec('git status --short --untracked-files=all');

    expect($status)->not->toMatch('/database\/migrations\//')
        ->not->toMatch('/database\/seeders\//')
        ->not->toMatch('/app\/Nutrition\/Infrastructure\/Catalog\/Eloquent\/Food/')
        ->not->toMatch('/app\/Nutrition\/Domain\/Catalog\/Lifecycle\//')
        ->not->toMatch('/resources\/catalog-import\/.*\.json/');
});
