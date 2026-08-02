<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Import\ApprovedCatalogImportArtifactsLoader;
use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportExecutionInput;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleCommandFingerprint;
use App\Nutrition\Application\Catalog\Persistence\ApplyApprovedLegacyCatalogImport;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

const M245_SOURCE_SHA = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';
const M245_MANIFEST_SHA = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';
const M245_RESOLUTION_SHA = '8eb9db29c044712134c4597220bdb7e61b19f186395c3dcc289cfe31c0054a5d';
const M245_APPROVAL_SHA = '9207cb1f556f0e5a9216e9ee9f651f446994c05ebf2cf7bd8893d4754d5ac105';
const M245_PLAN_SHA = '3bb9c7348f6f7386b1cd7667af7cd26527dcf429481410d00684d8dae48a0afb';

/** @return array<string, mixed> */
function approvedImportOptionsM245(int $actorId = 1, array $overrides = []): array
{
    return [...[
        '--source' => 'config/nutrition.php',
        '--expected-source-sha256' => M245_SOURCE_SHA,
        '--manifest' => base_path('resources/catalog-import/approved/legacy_config_nutrition_v1/candidate-manifest-'.M245_MANIFEST_SHA.'.json'),
        '--expected-manifest-sha256' => M245_MANIFEST_SHA,
        '--resolution' => base_path('resources/catalog-import/review/legacy_config_nutrition_v1/reviewed-resolution-'.M245_RESOLUTION_SHA.'.json'),
        '--expected-resolution-sha256' => M245_RESOLUTION_SHA,
        '--approval' => base_path('resources/catalog-import/approval/legacy_config_nutrition_v1/resolution-approval-'.M245_APPROVAL_SHA.'.json'),
        '--expected-approval-sha256' => M245_APPROVAL_SHA,
        '--apply-plan' => base_path('resources/catalog-import/apply-plan/legacy_config_nutrition_v1/apply-plan-'.M245_PLAN_SHA.'.json'),
        '--expected-apply-plan-sha256' => M245_PLAN_SHA,
        '--actor-id' => (string) $actorId,
        '--actor-reference' => 'audit:catalog-import:m245',
        '--reason' => 'Apply the formally approved first controlled legacy catalog batch.',
        '--occurred-at' => '2026-08-02T20:30:00.123456Z',
        '--execute' => true,
    ], ...$overrides];
}

/** @param list<string> $queries @return array<string, int> */
function statementProfileM245(array $queries): array
{
    $profile = [];

    foreach ($queries as $query) {
        preg_match('/^\s*([a-z]+)/i', $query, $matches);
        $statement = strtolower($matches[1] ?? 'unknown');
        $profile[$statement] = ($profile[$statement] ?? 0) + 1;
    }

    ksort($profile);

    return $profile;
}

/** @return list<string> */
function fixedEventUuidSequenceM245(): array
{
    return array_map(
        fn (int $index): string => sprintf('018f1f2e-7b2a-7c4d-8e9f-%012x', $index),
        range(1, 30),
    );
}

/** @return array<string, mixed> */
function trackedApplyPlanM245(): array
{
    return json_decode(
        file_get_contents(base_path('resources/catalog-import/apply-plan/legacy_config_nutrition_v1/apply-plan-'.M245_PLAN_SHA.'.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @return list<string> */
function approvedSubjectPublicIdsM245(): array
{
    return [
        'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
        '8e502950-ca1d-557d-8c26-c734b795f238',
        '0d3adf20-be25-5439-b89b-98e86e78c995',
        '6e360841-51b8-5deb-a201-38debd0f03dc',
        '3fb0ee74-8421-5be3-84ce-db9a3aae95bb',
        'bb86f7e2-e776-51d9-b503-d9430455035a',
        '9d04432d-a814-5f9e-af1f-0165c6fc8dcf',
        'c649dff7-5a65-55a2-83f8-7820356bae16',
        '050bfbe4-dc5c-5909-9c03-439a511104e9',
        'c8a9ff8f-7cdf-578f-a372-109b36c5d880',
    ];
}

/** @return list<array<string, mixed>> */
function approvedEventSnapshotM245(): array
{
    return CatalogLifecycleEvent::query()
        ->whereIn('subject_public_id', approvedSubjectPublicIdsM245())
        ->orderBy('id')
        ->get()
        ->map(fn (CatalogLifecycleEvent $event): array => $event->getRawOriginal())
        ->all();
}

/** @return array<string, list<array<string, mixed>>> */
function catalogRowSnapshotM245(): array
{
    return [
        'sources' => FoodSource::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
        'references' => FoodReference::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
        'versions' => FoodReferenceVersion::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
        'links' => FoodReferenceVersionSource::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
        'aliases' => FoodAlias::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
    ];
}

it('fails missing execute and malformed execution inputs before database access', function (array $overrides, string $outcome) {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $options = approvedImportOptionsM245(overrides: $overrides);

    $this->artisan('catalog:apply-approved-legacy-import', $options)
        ->expectsOutput($outcome)
        ->assertFailed();

    expect($queries)->toBe([]);
})->with([
    'execute absent' => [['--execute' => false], 'artifact_invalid'],
    'actor id absent' => [['--actor-id' => ''], 'actor_invalid'],
    'actor reference blank' => [['--actor-reference' => ' '], 'actor_invalid'],
    'actor reference syntax' => [['--actor-reference' => 'audit actor'], 'actor_invalid'],
    'reason blank' => [['--reason' => ' '], 'actor_invalid'],
    'occurred at offset' => [['--occurred-at' => '2026-08-02T17:30:00.123456-03:00'], 'actor_invalid'],
    'occurred at missing micros' => [['--occurred-at' => '2026-08-02T20:30:00Z'], 'actor_invalid'],
]);

it('rejects every artifact checksum mismatch before database access', function (string $option, string $outcome) {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245(overrides: [
        $option => str_repeat('0', 64),
    ]))->expectsOutput($outcome)->assertFailed();

    expect($queries)->toBe([]);
})->with([
    'source' => ['--expected-source-sha256', 'source_drift'],
    'manifest' => ['--expected-manifest-sha256', 'artifact_invalid'],
    'resolution' => ['--expected-resolution-sha256', 'artifact_invalid'],
    'approval' => ['--expected-approval-sha256', 'artifact_invalid'],
    'apply plan' => ['--expected-apply-plan-sha256', 'artifact_invalid'],
]);

it('finishes artifact validation before validating execution actor syntax', function () {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245(overrides: [
        '--expected-source-sha256' => str_repeat('0', 64),
        '--actor-reference' => 'invalid actor reference',
    ]))->expectsOutput('source_drift')->assertFailed();

    expect($queries)->toBe([]);
});

it('rejects noncanonical unsupported and cross-bound apply plans before database access', function () {
    $mutations = [
        'noncanonical bytes' => fn (array $plan): string => CanonicalCatalogImportJson::serializeSemanticGraph($plan)."\n",
        'unsupported schema' => function (array $plan): string {
            $plan['schema'] = 'nutria.catalog-import-apply-plan/999';

            return CanonicalCatalogImportJson::serializeSemanticGraph($plan);
        },
        'cross-document binding' => function (array $plan): string {
            $plan['candidate_manifest_sha256'] = str_repeat('1', 64);

            return CanonicalCatalogImportJson::serializeSemanticGraph($plan);
        },
    ];

    foreach ($mutations as $mutate) {
        $bytes = $mutate(trackedApplyPlanM245());
        $path = tempnam(sys_get_temp_dir(), 'm245-plan-');
        file_put_contents($path, $bytes);
        $queries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        try {
            $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245(overrides: [
                '--apply-plan' => $path,
                '--expected-apply-plan-sha256' => hash('sha256', $bytes),
            ]))->expectsOutput('artifact_invalid')->assertFailed();
        } finally {
            unlink($path);
        }

        expect($queries)->toBe([]);
    }
});

it('rejects a nonexistent execution actor before catalog writes', function () {
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245(999999999))
        ->expectsOutput('actor_invalid')
        ->assertFailed();

    expect(statementProfileM245($queries))->toBe(['select' => 1])
        ->and(FoodSource::query()->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
});

it('applies the exact approved graph once and replays it without mutations', function () {
    $actor = User::factory()->create(['id' => 245001]);
    Str::createUuidsUsingSequence(fixedEventUuidSequenceM245());
    $firstApplyQueries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$firstApplyQueries): void {
        $firstApplyQueries[] = $query->sql;
    });

    try {
        $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
            ->expectsOutput('applied')
            ->assertSuccessful();
    } finally {
        Str::createUuidsNormally();
        Event::forget(QueryExecuted::class);
    }

    $source = FoodSource::query()->sole();
    $references = FoodReference::query()->orderBy('public_id')->get();
    $versions = FoodReferenceVersion::query()->orderBy('public_id')->get();
    $links = FoodReferenceVersionSource::query()->orderBy('source_record_key')->get();
    $aliases = FoodAlias::query()->orderBy('public_id')->get();
    $events = CatalogLifecycleEvent::query()->orderBy('id')->get();

    expect($source->public_id)->toBe('ead17ec3-6176-5f48-b25c-6f4ce3ce9907')
        ->and($source->authority_status)->toBe(FoodSourceAuthorityStatus::Untrusted)
        ->and($source->archived_at)->toBeNull()
        ->and($source->created_by_user_id)->toBe($actor->id)
        ->and($actor->id)->toBe(245001)
        ->and($references)->toHaveCount(3)
        ->and($references->pluck('stable_key', 'public_id')->all())->toBe([
            '0d3adf20-be25-5439-b89b-98e86e78c995' => 'doce-de-leite',
            '6e360841-51b8-5deb-a201-38debd0f03dc' => 'leite-condensado',
            '8e502950-ca1d-557d-8c26-c734b795f238' => 'creme-de-leite',
        ])
        ->and($references->every(fn (FoodReference $reference): bool => $reference->is_generic && $reference->archived_at === null))->toBeTrue()
        ->and($versions)->toHaveCount(3)
        ->and($versions->pluck('energy_kcal', 'canonical_name')->sortKeys()->all())->toBe([
            'creme de leite' => '221.0000',
            'doce de leite' => '306.0000',
            'leite condensado' => '313.0000',
        ])
        ->and($versions->every(fn (FoodReferenceVersion $version): bool => $version->review_status === CatalogReviewStatus::Draft
            && $version->published_at === null && $version->activated_at === null))->toBeTrue()
        ->and($links)->toHaveCount(3)
        ->and($links->every(fn (FoodReferenceVersionSource $link): bool => $link->role === FoodReferenceVersionSourceRole::Primary))->toBeTrue()
        ->and($links->pluck('source_record_key')->all())->toBe(['creme de leite', 'doce de leite', 'leite condensado'])
        ->and($links->every(fn (FoodReferenceVersionSource $link): bool => $link->created_by_user_id === $actor->id))->toBeTrue()
        ->and($aliases)->toHaveCount(3)
        ->and($aliases->pluck('normalized_alias')->sort()->values()->all())->toBe(['creme de leite', 'doce de leite', 'leite condensado'])
        ->and($aliases->pluck('lineage_id', 'public_id')->all())->toBe([
            '050bfbe4-dc5c-5909-9c03-439a511104e9' => 'ace8e351-2de6-5bb9-a12e-3933cbbae45e',
            'c649dff7-5a65-55a2-83f8-7820356bae16' => '93b0b1c3-6a5d-540f-8c34-38b00be3d0e6',
            'c8a9ff8f-7cdf-578f-a372-109b36c5d880' => '63036985-520e-5cbb-8970-74fe9cb41d18',
        ])
        ->and($aliases->every(fn (FoodAlias $alias): bool => $alias->review_status === CatalogReviewStatus::Draft
            && $alias->published_at === null && $alias->activated_at === null))->toBeTrue()
        ->and(FoodAlias::query()->whereIn('normalized_alias', ['doce leite', 'leite condesado'])->count())->toBe(0)
        ->and(FoodPortion::query()->count())->toBe(0)
        ->and($events)->toHaveCount(10)
        ->and($events->pluck('public_id')->all())->toBe(array_values(array_filter(
            fixedEventUuidSequenceM245(),
            fn (string $uuid, int $index): bool => ($index + 1) % 3 === 0,
            ARRAY_FILTER_USE_BOTH,
        )))
        ->and($events->every(fn (CatalogLifecycleEvent $event): bool => $event->outcome === CatalogLifecycleOutcome::Succeeded
            && $event->actor_user_id === $actor->id
            && $event->actor_reference === 'audit:catalog-import:m245'
            && $event->reason === 'Apply the formally approved first controlled legacy catalog batch.'
            && $event->occurred_at->format('Y-m-d\TH:i:s.u\Z') === '2026-08-02T20:30:00.123456Z'))->toBeTrue()
        ->and($events->where('event_type', CatalogLifecycleOperation::CreateSource))->toHaveCount(1)
        ->and($events->where('event_type', CatalogLifecycleOperation::CreateReference))->toHaveCount(3)
        ->and($events->where('event_type', CatalogLifecycleOperation::CreateDraft))->toHaveCount(6)
        ->and($events->map(fn (CatalogLifecycleEvent $event): array => [
            $event->public_id,
            $event->subject_public_id,
            $event->idempotency_key,
        ])->all())->toBe([
            ['018f1f2e-7b2a-7c4d-8e9f-000000000003', 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907', '747b1d86-2483-5dd0-97a6-20df94f4394a'],
            ['018f1f2e-7b2a-7c4d-8e9f-000000000006', '0d3adf20-be25-5439-b89b-98e86e78c995', '6741a8d4-f9fc-5219-9b54-12544b5fdbee'],
            ['018f1f2e-7b2a-7c4d-8e9f-000000000009', '6e360841-51b8-5deb-a201-38debd0f03dc', '66e6218f-9fc0-5127-9fe7-57b43d98d82b'],
            ['018f1f2e-7b2a-7c4d-8e9f-00000000000c', '8e502950-ca1d-557d-8c26-c734b795f238', '440c04ec-8420-5605-91be-b717126a76e0'],
            ['018f1f2e-7b2a-7c4d-8e9f-00000000000f', 'bb86f7e2-e776-51d9-b503-d9430455035a', '619a1844-8e97-5df9-9b04-74b198d9e2fc'],
            ['018f1f2e-7b2a-7c4d-8e9f-000000000012', '9d04432d-a814-5f9e-af1f-0165c6fc8dcf', 'db0f4eb1-922e-54c9-887a-ecae9be441f4'],
            ['018f1f2e-7b2a-7c4d-8e9f-000000000015', '3fb0ee74-8421-5be3-84ce-db9a3aae95bb', 'c1935368-27c9-5aa2-b66d-7a6438da14eb'],
            ['018f1f2e-7b2a-7c4d-8e9f-000000000018', '050bfbe4-dc5c-5909-9c03-439a511104e9', 'a402efe8-35a4-56b1-9614-5b0b49e5f31a'],
            ['018f1f2e-7b2a-7c4d-8e9f-00000000001b', 'c8a9ff8f-7cdf-578f-a372-109b36c5d880', '5f1a8b8c-e537-535d-90dd-0e5c02208275'],
            ['018f1f2e-7b2a-7c4d-8e9f-00000000001e', 'c649dff7-5a65-55a2-83f8-7820356bae16', '3b3664bc-f117-54da-9b9d-8d4b03021385'],
        ])
        ->and($events->pluck('actor_reference')->all())->not->toContain('human-reviewer:project-owner');

    $beforeReplay = [
        'source' => $source->updated_at->format('Y-m-d H:i:s.uP'),
        'references' => $references->pluck('updated_at', 'public_id')->map->format('Y-m-d H:i:s.uP')->all(),
        'versions' => $versions->pluck('updated_at', 'public_id')->map->format('Y-m-d H:i:s.uP')->all(),
        'links' => $links->pluck('updated_at', 'id')->map->format('Y-m-d H:i:s.uP')->all(),
        'aliases' => $aliases->pluck('updated_at', 'public_id')->map->format('Y-m-d H:i:s.uP')->all(),
    ];
    $replayQueries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$replayQueries): void {
        $replayQueries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('no_op_replay')
        ->assertSuccessful();
    Event::forget(QueryExecuted::class);

    expect([
        FoodSource::query()->sole()->updated_at->format('Y-m-d H:i:s.uP'),
        FoodReference::query()->orderBy('public_id')->get()->pluck('updated_at', 'public_id')->map->format('Y-m-d H:i:s.uP')->all(),
        FoodReferenceVersion::query()->orderBy('public_id')->get()->pluck('updated_at', 'public_id')->map->format('Y-m-d H:i:s.uP')->all(),
        FoodReferenceVersionSource::query()->orderBy('source_record_key')->get()->pluck('updated_at', 'id')->map->format('Y-m-d H:i:s.uP')->all(),
        FoodAlias::query()->orderBy('public_id')->get()->pluck('updated_at', 'public_id')->map->format('Y-m-d H:i:s.uP')->all(),
    ])->toBe([
        $beforeReplay['source'],
        $beforeReplay['references'],
        $beforeReplay['versions'],
        $beforeReplay['links'],
        $beforeReplay['aliases'],
    ])->and(CatalogLifecycleEvent::query()->count())->toBe(10)
        ->and(count($replayQueries))->toBe(21)
        ->and(statementProfileM245($replayQueries))->toBe(['select' => 21]);

    expect(count($firstApplyQueries))->toBe(67)
        ->and(statementProfileM245($firstApplyQueries))->toBe(['insert' => 23, 'select' => 44]);

    $rawOptions = [];

    foreach (approvedImportOptionsM245($actor->id) as $name => $value) {
        if ($name !== '--execute') {
            $rawOptions[ltrim($name, '-')] = $value;
        }
    }

    $input = ApprovedCatalogImportExecutionInput::fromCommandOptions($rawOptions, true);
    $artifacts = app(ApprovedCatalogImportArtifactsLoader::class)->loadCommandOptions($rawOptions);
    $result = app(ApplyApprovedLegacyCatalogImport::class)->execute($artifacts, $input);

    expect($result->outcome)->toBe(ApprovedCatalogImportOutcome::NoOpReplay)
        ->and($result->graphFingerprints)->toBe([
            'creme de leite' => '7ceab6ca39d30805fda6b7d82406f97376fbd810b8ece2636e94e87fb583b2d1',
            'doce de leite' => 'cd6351ecdeb28f9332b042b4a58477c2c05b26298d48b69fc1b5a110789cf14b',
            'leite condensado' => '7caff5c6fb1ac7ba1cdf887e367261f26a76582d0b67c55938805b1fac1598d9',
        ]);
});

it('replays an exact graph independently from the new execution context', function (
    bool $useDifferentActor,
    array $overrides,
) {
    $originalActor = User::factory()->create(['id' => 245001]);
    $replayActor = $useDifferentActor
        ? User::factory()->create(['id' => 245002])
        : $originalActor;
    Str::createUuidsUsingSequence(fixedEventUuidSequenceM245());

    try {
        $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($originalActor->id))
            ->expectsOutput('applied')
            ->assertSuccessful();
    } finally {
        Str::createUuidsNormally();
    }

    $beforeRows = catalogRowSnapshotM245();
    $beforeEvents = approvedEventSnapshotM245();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245(
        $replayActor->id,
        $overrides,
    ))->expectsOutput('no_op_replay')->assertSuccessful();
    Event::forget(QueryExecuted::class);

    $afterEvents = CatalogLifecycleEvent::query()
        ->whereIn('subject_public_id', approvedSubjectPublicIdsM245())
        ->orderBy('id')
        ->get();
    $replayOccurredAt = $overrides['--occurred-at'] ?? '2026-08-02T20:30:00.123456Z';

    expect(catalogRowSnapshotM245())->toBe($beforeRows)
        ->and(approvedEventSnapshotM245())->toBe($beforeEvents)
        ->and($afterEvents)->toHaveCount(10)
        ->and($afterEvents->every(fn (CatalogLifecycleEvent $event): bool => $event->actor_user_id === 245001
            && $event->actor_reference === 'audit:catalog-import:m245'
            && $event->reason === 'Apply the formally approved first controlled legacy catalog batch.'
            && $event->occurred_at->format('Y-m-d\TH:i:s.u\Z') === '2026-08-02T20:30:00.123456Z'))->toBeTrue()
        ->and(CatalogLifecycleEvent::query()->where('actor_user_id', 245002)->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->where('actor_reference', 'audit:catalog-import:m245-replay')->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->where('reason', 'Verify replay of the approved catalog import.')->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->where('occurred_at', $replayOccurredAt)->count())->toBe(
            $replayOccurredAt === '2026-08-02T20:30:00.123456Z' ? 10 : 0,
        )
        ->and(CatalogLifecycleEvent::query()->count())->toBe(10)
        ->and(count($queries))->toBe(21)
        ->and(statementProfileM245($queries))->toBe(['select' => 21]);
})->with([
    'different actor ID' => [true, []],
    'different actor reference' => [false, [
        '--actor-reference' => 'audit:catalog-import:m245-replay',
    ]],
    'different reason' => [false, [
        '--reason' => 'Verify replay of the approved catalog import.',
    ]],
    'different occurred-at' => [false, [
        '--occurred-at' => '2026-08-03T01:15:30.654321Z',
    ]],
    'all execution fields changed' => [true, [
        '--actor-reference' => 'audit:catalog-import:m245-replay',
        '--reason' => 'Verify replay of the approved catalog import.',
        '--occurred-at' => '2026-08-03T01:15:30.654321Z',
    ]],
]);

it('replays the exact approved graph alongside unrelated catalog rows and lifecycle events', function () {
    $originalActor = User::factory()->create(['id' => 245001]);
    $unrelatedActor = User::factory()->create(['id' => 245003]);
    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($originalActor->id))
        ->expectsOutput('applied')
        ->assertSuccessful();
    $approvedEvents = approvedEventSnapshotM245();

    $unrelatedSource = FoodSource::factory()->eligible()->create([
        'public_id' => '10000000-0000-7000-8000-000000000001',
        'title' => 'Unrelated catalog source',
        'created_by_user_id' => $unrelatedActor->id,
    ]);
    $unrelatedReference = FoodReference::factory()->generic()->create([
        'public_id' => '10000000-0000-7000-8000-000000000002',
        'stable_key' => 'unrelated-catalog-item',
        'created_by_user_id' => $unrelatedActor->id,
    ]);
    $unrelatedVersion = FoodReferenceVersion::factory()->withNutrition()->create([
        'public_id' => '10000000-0000-7000-8000-000000000003',
        'food_reference_id' => $unrelatedReference->id,
        'canonical_name' => 'unrelated catalog item',
        'normalized_canonical_name' => 'unrelated catalog item',
        'created_by_user_id' => $unrelatedActor->id,
    ]);
    FoodReferenceVersionSource::factory()->primary()->create([
        'food_reference_version_id' => $unrelatedVersion->id,
        'food_source_id' => $unrelatedSource->id,
        'source_record_key' => 'unrelated catalog item',
        'created_by_user_id' => $unrelatedActor->id,
    ]);
    FoodAlias::factory()->create([
        'public_id' => '10000000-0000-7000-8000-000000000004',
        'lineage_id' => '10000000-0000-5000-8000-000000000005',
        'food_reference_id' => $unrelatedReference->id,
        'display_alias' => 'unrelated catalog alias',
        'normalized_alias' => 'unrelated catalog alias',
        'food_source_id' => $unrelatedSource->id,
        'source_record_key' => 'unrelated catalog item',
        'created_by_user_id' => $unrelatedActor->id,
    ]);
    $unrelatedOccurredAt = new DateTimeImmutable('2026-08-02T23:00:00.000000Z');
    $unrelatedIdempotencyKey = '10000000-0000-5000-8000-000000000007';
    $unrelatedCommand = new CatalogLifecycleCommand(
        subjectType: CatalogLifecycleSubjectType::Source,
        subjectId: $unrelatedSource->public_id,
        operation: CatalogLifecycleOperation::CreateSource,
        actorId: (string) $unrelatedActor->id,
        reason: 'Create unrelated catalog fixture.',
        idempotencyKey: $unrelatedIdempotencyKey,
        occurredAt: $unrelatedOccurredAt,
    );
    CatalogLifecycleEvent::factory()->forActor($unrelatedActor, 'audit:unrelated-catalog')->create([
        'public_id' => '10000000-0000-7000-8000-000000000006',
        'subject_type' => CatalogLifecycleSubjectType::Source,
        'subject_id' => $unrelatedSource->id,
        'subject_public_id' => $unrelatedSource->public_id,
        'event_type' => CatalogLifecycleOperation::CreateSource,
        'outcome' => CatalogLifecycleOutcome::Succeeded,
        'reason_code' => CatalogLifecycleReason::SourceCreated,
        'reason' => 'Create unrelated catalog fixture.',
        'previous_state' => null,
        'next_state' => CatalogLifecycleState::Available,
        'occurred_at' => $unrelatedOccurredAt,
        'idempotency_key' => $unrelatedIdempotencyKey,
        'command_fingerprint' => CatalogLifecycleCommandFingerprint::forCommand(
            $unrelatedCommand,
            'audit:unrelated-catalog',
        ),
    ]);
    $beforeRows = catalogRowSnapshotM245();
    $beforeEvents = CatalogLifecycleEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($originalActor->id))
        ->expectsOutput('no_op_replay')
        ->assertSuccessful();
    Event::forget(QueryExecuted::class);

    expect(catalogRowSnapshotM245())->toBe($beforeRows)
        ->and(CatalogLifecycleEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeEvents)
        ->and(approvedEventSnapshotM245())->toBe($approvedEvents)
        ->and([
            FoodSource::query()->count(),
            FoodReference::query()->count(),
            FoodReferenceVersion::query()->count(),
            FoodReferenceVersionSource::query()->count(),
            FoodAlias::query()->count(),
            CatalogLifecycleEvent::query()->count(),
        ])->toBe([2, 4, 4, 4, 4, 11])
        ->and(count($queries))->toBe(21)
        ->and(statementProfileM245($queries))->toBe(['select' => 21]);
});

it('returns catalog drift for an absent approved graph on a changed initial snapshot', function () {
    $actor = User::factory()->create();
    FoodSource::factory()->create();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_drift')
        ->assertFailed();
    Event::forget(QueryExecuted::class);

    expect(FoodSource::query()->count())->toBe(1)
        ->and(FoodReference::query()->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0)
        ->and(count($queries))->toBe(12)
        ->and(statementProfileM245($queries))->toBe(['select' => 12]);
});

it('returns catalog conflict for a deterministic source with differing semantics without writes', function () {
    $actor = User::factory()->create();
    FoodSource::factory()->create([
        'public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
        'title' => 'Conflicting source',
    ]);
    $before = [
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ];
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_conflict')
        ->assertFailed();
    Event::forget(QueryExecuted::class);

    expect([
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ])->toBe($before)
        ->and(count($queries))->toBe(12)
        ->and(statementProfileM245($queries))->toBe(['select' => 12]);
});

it('returns catalog conflict for an approved stable key on another public UUID without writes', function () {
    $actor = User::factory()->create();
    FoodReference::factory()->create(['stable_key' => 'creme-de-leite']);
    $before = FoodReference::query()->count();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_conflict')
        ->assertFailed();
    Event::forget(QueryExecuted::class);

    expect(FoodReference::query()->count())->toBe($before)
        ->and(FoodSource::query()->count())->toBe(0)
        ->and(CatalogLifecycleEvent::query()->count())->toBe(0)
        ->and(count($queries))->toBe(13)
        ->and(statementProfileM245($queries))->toBe(['select' => 13]);
});

it('returns catalog conflict for a partial exact source and reference graph', function () {
    $actor = User::factory()->create();
    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('applied')
        ->assertSuccessful();

    $mutableFixtureTables = [
        'catalog_lifecycle_events',
        'food_aliases',
        'food_reference_version_sources',
        'food_reference_versions',
        'food_references',
    ];

    foreach ($mutableFixtureTables as $table) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
    }

    try {
        CatalogLifecycleEvent::query()
            ->where('subject_public_id', '!=', 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907')
            ->delete();
        FoodAlias::query()->delete();
        FoodReferenceVersionSource::query()->delete();
        FoodReferenceVersion::query()->delete();
        FoodReference::query()->delete();
    } finally {
        foreach (array_reverse($mutableFixtureTables) as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
        }
    }
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_conflict')
        ->assertFailed();
    Event::forget(QueryExecuted::class);

    expect([
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ])->toBe([1, 0, 0, 0, 0, 1])
        ->and(statementProfileM245($queries))->not->toHaveKeys(['insert', 'update', 'delete', 'merge']);
});

it('does not resume a version whose approved primary source link is missing', function () {
    $actor = User::factory()->create();
    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('applied')
        ->assertSuccessful();
    FoodReferenceVersionSource::query()->where('source_record_key', 'creme de leite')->delete();
    $before = [
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ];
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_conflict')
        ->assertFailed();

    expect([
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ])->toBe($before)
        ->and(statementProfileM245($queries))->not->toHaveKeys(['insert', 'update', 'delete']);
});

it('treats differing alias semantics as a conflict without repair', function () {
    $actor = User::factory()->create();
    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('applied')
        ->assertSuccessful();
    $alias = FoodAlias::query()->where('normalized_alias', 'creme de leite')->sole();
    $alias->display_alias = 'creme leite conflitante';
    $alias->save();
    $updatedAt = $alias->updated_at->toImmutable();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_conflict')
        ->assertFailed();

    expect($alias->fresh()->display_alias)->toBe('creme leite conflitante')
        ->and($alias->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(CatalogLifecycleEvent::query()->count())->toBe(10)
        ->and(statementProfileM245($queries))->not->toHaveKeys(['insert', 'update', 'delete']);
});

it('requires every exact entity graph to retain its root lifecycle event', function () {
    $actor = User::factory()->create();
    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('applied')
        ->assertSuccessful();
    DB::statement('ALTER TABLE catalog_lifecycle_events DISABLE TRIGGER trg_catalog_lifecycle_events_block_update_delete');
    CatalogLifecycleEvent::query()->where('subject_public_id', 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907')->delete();
    DB::statement('ALTER TABLE catalog_lifecycle_events ENABLE TRIGGER trg_catalog_lifecycle_events_block_update_delete');
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('catalog_conflict')
        ->assertFailed();

    expect(CatalogLifecycleEvent::query()->count())->toBe(9)
        ->and(FoodSource::query()->count())->toBe(1)
        ->and(statementProfileM245($queries))->not->toHaveKeys(['insert', 'update', 'delete']);
});

it('rolls back early entities and root events after a later PostgreSQL insert failure', function () {
    $actor = User::factory()->create();
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION m245_reject_alias_insert() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'forced M2.4.5 alias failure';
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER m245_reject_alias_insert BEFORE INSERT ON food_aliases
        FOR EACH ROW EXECUTE FUNCTION m245_reject_alias_insert();
        SQL);

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('persistence_failed')
        ->assertFailed();

    expect([
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ])->toBe([0, 0, 0, 0, 0, 0]);
});

it('rolls back the complete graph when post-write semantic verification fails', function () {
    $actor = User::factory()->create();
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION m245_drift_alias_insert() RETURNS trigger AS $$
        BEGIN
            IF NEW.public_id = 'c649dff7-5a65-55a2-83f8-7820356bae16' THEN
                NEW.display_alias = 'semantic drift';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER m245_drift_alias_insert BEFORE INSERT ON food_aliases
        FOR EACH ROW EXECUTE FUNCTION m245_drift_alias_insert();
        SQL);

    $this->artisan('catalog:apply-approved-legacy-import', approvedImportOptionsM245($actor->id))
        ->expectsOutput('post_write_verification_failed')
        ->assertFailed();

    expect([
        FoodSource::query()->count(),
        FoodReference::query()->count(),
        FoodReferenceVersion::query()->count(),
        FoodReferenceVersionSource::query()->count(),
        FoodAlias::query()->count(),
        CatalogLifecycleEvent::query()->count(),
    ])->toBe([0, 0, 0, 0, 0, 0]);
});
