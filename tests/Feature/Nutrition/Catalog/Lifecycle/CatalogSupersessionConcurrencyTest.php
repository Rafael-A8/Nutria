<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleDerivedEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleProjectionStateResolver;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleReplayGuard;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleRootEventFactory;
use App\Nutrition\Application\Catalog\Lifecycle\FoodReferenceVersionSupersessionService;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\EloquentCatalogLifecycleEventStore;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function concurrentCreationTaskM2346(
    string $subjectPublicId,
    int $actorId,
    string $idempotencyKey,
    string $barrierPrefix,
    string $worker,
): Closure {
    return static function () use ($subjectPublicId, $actorId, $idempotencyKey, $barrierPrefix, $worker): array {
        DB::table('cache')->insert([
            'key' => "{$barrierPrefix}:{$worker}",
            'value' => 'ready',
            'expiration' => time() + 60,
        ]);
        $deadline = microtime(true) + 10;
        while (DB::table('cache')->where('key', 'like', "{$barrierPrefix}:%")->count() < 2) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('PostgreSQL creation barrier timed out.');
            }
            usleep(10_000);
        }

        $store = new EloquentCatalogLifecycleEventStore;
        $service = new FoodReferenceVersionSupersessionService(
            new FoodReferenceVersionLifecyclePolicy,
            $store,
            new CatalogLifecycleReplayGuard($store),
            new CatalogLifecycleRootEventFactory,
            new CatalogLifecycleDerivedEventFactory,
            new CatalogLifecycleProjectionStateResolver,
        );
        $result = $service->createSuccessor(
            new CatalogLifecycleCommand(
                CatalogLifecycleSubjectType::ReferenceVersion,
                $subjectPublicId,
                CatalogLifecycleOperation::CreateSuccessor,
                (string) $actorId,
                'Concurrent successor creation.',
                $idempotencyKey,
                new DateTimeImmutable('2026-07-13T13:00:00.123456-03:00'),
            ),
            new CatalogLifecycleExecutionContext($actorId, "audit:user:{$actorId}"),
        );

        return [
            'outcome' => $result->execution->lifecycleResult->outcome->value,
            'reason' => $result->execution->lifecycleResult->reason->value,
            'successor' => $result->successorPublicId,
            'replayed' => $result->wasReplayed(),
        ];
    };
}

function concurrentReplacementTaskM2346(
    string $subjectPublicId,
    int $actorId,
    string $idempotencyKey,
    string $barrierPrefix,
    string $worker,
): Closure {
    return static function () use ($subjectPublicId, $actorId, $idempotencyKey, $barrierPrefix, $worker): array {
        DB::table('cache')->insert([
            'key' => "{$barrierPrefix}:{$worker}",
            'value' => 'ready',
            'expiration' => time() + 60,
        ]);
        $deadline = microtime(true) + 10;
        while (DB::table('cache')->where('key', 'like', "{$barrierPrefix}:%")->count() < 2) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('PostgreSQL replacement barrier timed out.');
            }
            usleep(10_000);
        }

        $store = new EloquentCatalogLifecycleEventStore;
        $service = new FoodReferenceVersionSupersessionService(
            new FoodReferenceVersionLifecyclePolicy,
            $store,
            new CatalogLifecycleReplayGuard($store),
            new CatalogLifecycleRootEventFactory,
            new CatalogLifecycleDerivedEventFactory,
            new CatalogLifecycleProjectionStateResolver,
        );
        $result = $service->activateSuccessorReplacingCurrent(
            new CatalogLifecycleCommand(
                CatalogLifecycleSubjectType::ReferenceVersion,
                $subjectPublicId,
                CatalogLifecycleOperation::Activate,
                (string) $actorId,
                'Concurrent active replacement.',
                $idempotencyKey,
                new DateTimeImmutable('2026-07-13T13:30:00.123456-03:00'),
            ),
            new CatalogLifecycleExecutionContext($actorId, "audit:user:{$actorId}"),
        );

        return [
            'outcome' => $result->execution->lifecycleResult->outcome->value,
            'predecessor' => $result->deactivatedSubjectPublicId,
            'replayed' => $result->wasReplayed(),
        ];
    };
}

it('serializes real PostgreSQL successor and replacement races across independent processes', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Catalog supersession concurrency requires PostgreSQL independent sessions.');
    }

    DB::commit();
    $actor = User::factory()->create();

    $makePredecessor = function (bool $active = false) use ($actor): FoodReferenceVersion {
        $reference = FoodReference::factory()->create();
        $version = FoodReferenceVersion::factory()->withNutrition()->create([
            'food_reference_id' => $reference->id,
            'canonical_name' => 'Concurrent catalog food',
            'normalized_canonical_name' => 'concurrent catalog food',
            'classification' => 'test',
            'preparation_key' => 'any',
            'provenance' => ['source' => 'concurrency-test'],
            'created_by_user_id' => $actor->id,
        ]);
        $source = FoodSource::factory()->eligible()->create();
        FoodReferenceVersionSource::factory()->primary()->create([
            'food_reference_version_id' => $version->id,
            'food_source_id' => $source->id,
            'source_record_key' => 'concurrency:predecessor',
        ]);
        $version->forceFill([
            'review_status' => CatalogReviewStatus::Approved,
            'reviewed_at' => now(),
            'published_at' => $active ? now() : null,
            'activated_at' => $active ? now() : null,
        ])->save();

        return $version->refresh();
    };

    try {
        $differentKeysPredecessor = $makePredecessor();
        $differentBarrier = 'supersession:'.Str::uuid7();
        $differentResults = Concurrency::driver('process')->run([
            concurrentCreationTaskM2346($differentKeysPredecessor->public_id, $actor->id, (string) Str::uuid7(), $differentBarrier, 'one'),
            concurrentCreationTaskM2346($differentKeysPredecessor->public_id, $actor->id, (string) Str::uuid7(), $differentBarrier, 'two'),
        ], timeout: 30);
        $differentSuccessors = FoodReferenceVersion::query()
            ->where('supersedes_food_reference_version_id', $differentKeysPredecessor->id)
            ->get();

        expect(collect($differentResults)->where('outcome', CatalogLifecycleOutcome::Succeeded->value))->toHaveCount(1)
            ->and(collect($differentResults)->pluck('reason'))->toContain(CatalogLifecycleReason::SuccessorExists->value)
            ->and($differentSuccessors)->toHaveCount(1)
            ->and($differentSuccessors->first()->version_number)->toBe($differentKeysPredecessor->version_number + 1)
            ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::CreateDraft)
                ->where('subject_id', $differentSuccessors->first()->id)->count())->toBe(1);

        $sameKeyPredecessor = $makePredecessor();
        $sameKey = (string) Str::uuid7();
        $sameBarrier = 'supersession:'.Str::uuid7();
        $sameResults = Concurrency::driver('process')->run([
            concurrentCreationTaskM2346($sameKeyPredecessor->public_id, $actor->id, $sameKey, $sameBarrier, 'one'),
            concurrentCreationTaskM2346($sameKeyPredecessor->public_id, $actor->id, $sameKey, $sameBarrier, 'two'),
        ], timeout: 30);
        $sameSuccessors = FoodReferenceVersion::query()
            ->where('supersedes_food_reference_version_id', $sameKeyPredecessor->id)
            ->get();

        expect($sameSuccessors)->toHaveCount(1)
            ->and(collect($sameResults)->pluck('successor')->unique()->values()->all())->toBe([$sameSuccessors->first()->public_id])
            ->and(collect($sameResults)->where('replayed', true))->toHaveCount(1)
            ->and(CatalogLifecycleEvent::query()->where('idempotency_key', $sameKey)->count())->toBe(1)
            ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::CreateDraft)
                ->where('subject_id', $sameSuccessors->first()->id)->count())->toBe(1);

        $activePredecessor = $makePredecessor(active: true);
        $publishedSuccessor = FoodReferenceVersion::factory()->withNutrition()->create([
            'food_reference_id' => $activePredecessor->food_reference_id,
            'version_number' => $activePredecessor->version_number + 1,
            'supersedes_food_reference_version_id' => $activePredecessor->id,
            'canonical_name' => $activePredecessor->canonical_name,
            'normalized_canonical_name' => $activePredecessor->normalized_canonical_name,
            'locale' => $activePredecessor->locale,
            'classification' => $activePredecessor->classification,
            'preparation_key' => $activePredecessor->preparation_key,
            'provenance' => $activePredecessor->provenance,
            'created_by_user_id' => $actor->id,
        ]);
        $successorSource = FoodSource::factory()->eligible()->create();
        FoodReferenceVersionSource::factory()->primary()->create([
            'food_reference_version_id' => $publishedSuccessor->id,
            'food_source_id' => $successorSource->id,
            'source_record_key' => 'concurrency:successor',
        ]);
        $publishedSuccessor->forceFill([
            'review_status' => CatalogReviewStatus::Approved,
            'reviewed_at' => now(),
            'published_at' => now(),
        ])->save();
        $replacementKey = (string) Str::uuid7();
        $replacementBarrier = 'supersession:'.Str::uuid7();
        $replacementResults = Concurrency::driver('process')->run([
            concurrentReplacementTaskM2346($publishedSuccessor->public_id, $actor->id, $replacementKey, $replacementBarrier, 'one'),
            concurrentReplacementTaskM2346($publishedSuccessor->public_id, $actor->id, $replacementKey, $replacementBarrier, 'two'),
        ], timeout: 30);

        expect(collect($replacementResults)->where('outcome', CatalogLifecycleOutcome::Succeeded->value))->toHaveCount(2)
            ->and(collect($replacementResults)->where('replayed', true))->toHaveCount(1)
            ->and(collect($replacementResults)->pluck('predecessor')->unique()->values()->all())->toBe([$activePredecessor->public_id])
            ->and($activePredecessor->refresh()->deactivated_at)->not->toBeNull()
            ->and($publishedSuccessor->refresh()->activated_at)->not->toBeNull()
            ->and(FoodReferenceVersion::query()->where('food_reference_id', $activePredecessor->food_reference_id)
                ->whereNotNull('activated_at')->whereNull('deactivated_at')->count())->toBe(1)
            ->and(CatalogLifecycleEvent::query()->where('event_type', CatalogLifecycleOperation::Deactivate)
                ->where('subject_id', $activePredecessor->id)->count())->toBe(1);
    } finally {
        DB::statement('SET session_replication_role = replica');
        try {
            DB::statement(<<<'SQL'
TRUNCATE TABLE
    catalog_lifecycle_events,
    food_reference_version_sources,
    food_reference_versions,
    food_aliases,
    food_portions,
    food_sources,
    food_references,
    cache
RESTART IDENTITY CASCADE
SQL);
        } finally {
            DB::statement('SET session_replication_role = origin');
        }
        User::query()->whereKey($actor->id)->delete();
        DB::beginTransaction();
    }
});
