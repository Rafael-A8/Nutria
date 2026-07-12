<?php

use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleCommandFingerprint;
use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/** @return list<string> */
function lifecyclePersistenceFilesForM2343(): array
{
    return [
        dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle/Contracts/CatalogLifecycleEventStore.php',
        dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle/Exceptions/CatalogLifecycleEventPersistenceException.php',
        dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle/Exceptions/CatalogLifecycleIdempotencyConflictException.php',
        dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle/ValueObjects/CatalogLifecycleEventDraft.php',
        dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle/ValueObjects/CatalogLifecycleStoredEvent.php',
        dirname(__DIR__, 5).'/app/Nutrition/Application/Catalog/Lifecycle/CatalogLifecycleCommandFingerprint.php',
        dirname(__DIR__, 5).'/app/Nutrition/Infrastructure/Catalog/Eloquent/CatalogLifecycleEvent.php',
        dirname(__DIR__, 5).'/app/Nutrition/Infrastructure/Catalog/Eloquent/EloquentCatalogLifecycleEventStore.php',
        dirname(__DIR__, 5).'/database/migrations/2026_07_12_154003_create_catalog_lifecycle_events_table.php',
        dirname(__DIR__, 5).'/database/factories/Nutrition/Catalog/CatalogLifecycleEventFactory.php',
    ];
}

it('fingerprints commands deterministically with canonical semantic fields', function () {
    $command = new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::ReferenceVersion,
        'reference-version-public-id',
        CatalogLifecycleOperation::Approve,
        'actor-external-id',
        'Editorial approval.',
        '018f1f2e-7b2a-7c4d-8e9f-0123456789ab',
        new DateTimeImmutable('2026-07-12T09:00:00.123456-03:00'),
    );
    $sameInstant = new CatalogLifecycleCommand(
        CatalogLifecycleSubjectType::ReferenceVersion,
        'reference-version-public-id',
        CatalogLifecycleOperation::Approve,
        'actor-external-id',
        'Editorial approval.',
        '018f1f2e-7b2a-7c4d-8e9f-0123456789ab',
        new DateTimeImmutable('2026-07-12T12:00:00.123456+00:00'),
    );

    $fingerprint = CatalogLifecycleCommandFingerprint::forCommand($command, 'audit:actor:stable');

    expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and(CatalogLifecycleCommandFingerprint::forCommand($command, 'audit:actor:stable'))->toBe($fingerprint)
        ->and(CatalogLifecycleCommandFingerprint::forCommand($sameInstant, 'audit:actor:stable'))->toBe($fingerprint)
        ->and(CatalogLifecycleCommandFingerprint::forCommand($command, 'audit:actor:different'))->not->toBe($fingerprint);
});

it('keeps drafts typed ordered readonly and root-derived explicit', function () {
    $reflection = new ReflectionClass(CatalogLifecycleEventDraft::class);
    $reasons = [CatalogLifecycleReason::IncompleteContent, CatalogLifecycleReason::ProvenanceIncomplete];
    $common = [
        'subjectType' => CatalogLifecycleSubjectType::Alias,
        'subjectInternalId' => 11,
        'subjectPublicId' => '018f1f2e-7b2a-7c4d-8e9f-1123456789ab',
        'operation' => CatalogLifecycleOperation::SubmitForReview,
        'outcome' => CatalogLifecycleOutcome::ValidationFailed,
        'reasonCode' => CatalogLifecycleReason::IncompleteContent,
        'reason' => null,
        'previousState' => CatalogLifecycleState::Draft,
        'nextState' => CatalogLifecycleState::Draft,
        'eligibilityReasons' => $reasons,
        'actorUserId' => null,
        'actorReference' => 'audit:actor:stable',
        'metadata' => ['ordered' => [2, 1]],
        'occurredAt' => new DateTimeImmutable('2026-07-12T12:00:00.123456+00:00'),
        'correlationId' => '018f1f2e-7b2a-7c4d-8e9f-2123456789ab',
        'transactionId' => '018f1f2e-7b2a-7c4d-8e9f-3123456789ab',
    ];
    $derived = CatalogLifecycleEventDraft::derived(...$common);
    $root = CatalogLifecycleEventDraft::root(...[
        ...$common,
        'idempotencyKey' => '018f1f2e-7b2a-7c4d-8e9f-4123456789ab',
        'commandFingerprint' => hash('sha256', 'boundary-command'),
    ]);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($derived->isRoot())->toBeFalse()
        ->and($root->isRoot())->toBeTrue()
        ->and($root->eligibilityReasons)->toBe($reasons)
        ->and($root->metadata)->toBe(['ordered' => [2, 1]]);
});

it('rejects invalid draft semantic combinations', function (array $overrides) {
    $arguments = array_replace([
        'subjectType' => CatalogLifecycleSubjectType::ReferenceVersion,
        'subjectInternalId' => 1,
        'subjectPublicId' => '018f1f2e-7b2a-7c4d-8e9f-1123456789ab',
        'operation' => CatalogLifecycleOperation::SubmitForReview,
        'outcome' => CatalogLifecycleOutcome::Succeeded,
        'reasonCode' => CatalogLifecycleReason::TransitionApplied,
        'reason' => null,
        'previousState' => CatalogLifecycleState::Draft,
        'nextState' => CatalogLifecycleState::PendingReview,
        'eligibilityReasons' => [],
        'actorUserId' => null,
        'actorReference' => 'audit:actor',
        'metadata' => [],
        'occurredAt' => new DateTimeImmutable('2026-07-12T12:00:00+00:00'),
        'idempotencyKey' => '018f1f2e-7b2a-7c4d-8e9f-2123456789ab',
        'commandFingerprint' => hash('sha256', 'command'),
        'correlationId' => '018f1f2e-7b2a-7c4d-8e9f-3123456789ab',
        'transactionId' => '018f1f2e-7b2a-7c4d-8e9f-4123456789ab',
    ], $overrides);

    expect(fn () => CatalogLifecycleEventDraft::root(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'nonpositive subject' => [['subjectInternalId' => 0]],
    'blank actor reference' => [['actorReference' => '  ']],
    'untrimmed editorial reason' => [['reason' => ' reason ']],
    'invalid fingerprint' => [['commandFingerprint' => str_repeat('A', 64)]],
    'no-op changed state' => [[
        'outcome' => CatalogLifecycleOutcome::NoOp,
        'reasonCode' => CatalogLifecycleReason::AlreadyActive,
        'previousState' => CatalogLifecycleState::Active,
        'nextState' => CatalogLifecycleState::Deactivated,
    ]],
    'validation missing eligibility' => [[
        'outcome' => CatalogLifecycleOutcome::ValidationFailed,
        'reasonCode' => CatalogLifecycleReason::IncompleteContent,
        'previousState' => CatalogLifecycleState::Draft,
        'nextState' => CatalogLifecycleState::Draft,
    ]],
    'duplicate eligibility' => [[
        'outcome' => CatalogLifecycleOutcome::ValidationFailed,
        'reasonCode' => CatalogLifecycleReason::IncompleteContent,
        'previousState' => CatalogLifecycleState::Draft,
        'nextState' => CatalogLifecycleState::Draft,
        'eligibilityReasons' => [CatalogLifecycleReason::IncompleteContent, CatalogLifecycleReason::IncompleteContent],
    ]],
]);

it('limits the application store contract to append-only operations', function () {
    $reflection = new ReflectionClass(CatalogLifecycleEventStore::class);
    $methods = collect($reflection->getMethods())->pluck('name')->sort()->values()->all();
    $source = file_get_contents($reflection->getFileName()) ?: '';

    expect($methods)->toBe(['appendDerived', 'findRootByIdempotencyKey', 'storeRoot'])
        ->and($source)->not->toMatch('/Eloquent|Illuminate|DB::|transaction|update|delete|save/i');
});

it('keeps application values and fingerprint framework free and nutrition isolated', function () {
    $files = [
        (new ReflectionClass(CatalogLifecycleCommandFingerprint::class))->getFileName(),
        (new ReflectionClass(CatalogLifecycleEventDraft::class))->getFileName(),
        (new ReflectionClass(CatalogLifecycleStoredEvent::class))->getFileName(),
    ];
    $source = implode("\n", array_map(fn (string $file): string => file_get_contents($file) ?: '', $files));

    expect($source)
        ->not->toMatch('/Illuminate|Laravel|Eloquent|App\\\\Models|Facades|auth\s*\(|DB::|::query\s*\(|->query\s*\(/i')
        ->not->toMatch('/\bAI\b|\bRAG\b|embedding|history|calorie|NutritionEstimate|\bmeal\b/i');
});

it('keeps the Eloquent store inside infrastructure without catalog workflow behavior', function () {
    $reflection = new ReflectionClass(EloquentCatalogLifecycleEventStore::class);
    $source = file_get_contents($reflection->getFileName()) ?: '';

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->implementsInterface(CatalogLifecycleEventStore::class))->toBeTrue()
        ->and($reflection->getNamespaceName())->toStartWith('App\Nutrition\Infrastructure')
        ->and($source)->not->toMatch('/FoodReference|FoodAlias|FoodPortion|FoodSource|lockForUpdate|authorize|auth\s*\(|policy|service provider/i')
        ->and($source)->not->toMatch('/calculate|resolve nutrition|\bAI\b|\bRAG\b|embedding|history|calorie|NutritionEstimate|\bmeal\b/i')
        ->and($source)->not->toMatch('/DB::transaction|beginTransaction|commit\s*\(|rollBack\s*\(/i');
});

it('keeps the model free of lifecycle mutation and automatic UUID hooks', function () {
    $reflection = new ReflectionClass(CatalogLifecycleEvent::class);
    $declaredMethods = collect($reflection->getMethods())
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === CatalogLifecycleEvent::class)
        ->pluck('name')
        ->all();

    expect(class_uses_recursive(CatalogLifecycleEvent::class))->not->toContain(HasUuids::class)
        ->and((new CatalogLifecycleEvent)->getGlobalScopes())->toBe([])
        ->and($declaredMethods)->not->toContain('boot', 'booted', 'updateEvent', 'deleteEvent', 'activate', 'approve', 'publish');
});

it('keeps the migration isolated reversible and append-only', function () {
    $migrationFile = dirname(__DIR__, 5).'/database/migrations/2026_07_12_154003_create_catalog_lifecycle_events_table.php';
    $source = file_get_contents($migrationFile) ?: '';

    expect(substr_count($source, "Schema::create('"))->toBe(1)
        ->and($source)->toContain("Schema::create('catalog_lifecycle_events'", 'fn_catalog_lifecycle_events_append_only', 'DROP FUNCTION IF EXISTS', 'Schema::dropIfExists')
        ->and($source)->not->toMatch('/Schema::table|food_references|food_reference_versions|food_aliases|food_portions|food_sources|meal_items|meals|insert\s*\(/i')
        ->and($source)->not->toMatch('/legacy|config\s*\(/i');
});

it('contains exactly the approved persistence implementation files before tests', function () {
    foreach (lifecyclePersistenceFilesForM2343() as $file) {
        expect(is_file($file))->toBeTrue();
    }

    expect(lifecyclePersistenceFilesForM2343())->toHaveCount(10);
});
