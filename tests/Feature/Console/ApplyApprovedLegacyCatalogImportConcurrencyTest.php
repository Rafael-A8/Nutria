<?php

use App\Models\User;
use App\Nutrition\Application\Catalog\Import\ApprovedCatalogImportArtifactsLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportGraphState;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportExecutionInput;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportLifecycleIdempotencyInput;
use App\Nutrition\Application\Catalog\Lifecycle\CatalogLifecycleCommandFingerprint;
use App\Nutrition\Application\Catalog\Persistence\ApplyApprovedLegacyCatalogImport;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Infrastructure\Catalog\Eloquent\CatalogLifecycleEvent;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use App\Nutrition\Infrastructure\Catalog\Import\ApprovedCatalogImportGraphInspector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

const M246_SOURCE_SHA = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';
const M246_MANIFEST_SHA = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';
const M246_RESOLUTION_SHA = '8eb9db29c044712134c4597220bdb7e61b19f186395c3dcc289cfe31c0054a5d';
const M246_APPROVAL_SHA = '9207cb1f556f0e5a9216e9ee9f651f446994c05ebf2cf7bd8893d4754d5ac105';
const M246_PLAN_SHA = '3bb9c7348f6f7386b1cd7667af7cd26527dcf429481410d00684d8dae48a0afb';

/** @return array<string, string> */
function approvedImportRawOptionsM246(
    int $actorId,
    string $actorReference,
    string $reason,
    string $occurredAt,
): array {
    return [
        'source' => 'config/nutrition.php',
        'expected-source-sha256' => M246_SOURCE_SHA,
        'manifest' => base_path('resources/catalog-import/approved/legacy_config_nutrition_v1/candidate-manifest-'.M246_MANIFEST_SHA.'.json'),
        'expected-manifest-sha256' => M246_MANIFEST_SHA,
        'resolution' => base_path('resources/catalog-import/review/legacy_config_nutrition_v1/reviewed-resolution-'.M246_RESOLUTION_SHA.'.json'),
        'expected-resolution-sha256' => M246_RESOLUTION_SHA,
        'approval' => base_path('resources/catalog-import/approval/legacy_config_nutrition_v1/resolution-approval-'.M246_APPROVAL_SHA.'.json'),
        'expected-approval-sha256' => M246_APPROVAL_SHA,
        'apply-plan' => base_path('resources/catalog-import/apply-plan/legacy_config_nutrition_v1/apply-plan-'.M246_PLAN_SHA.'.json'),
        'expected-apply-plan-sha256' => M246_PLAN_SHA,
        'actor-id' => (string) $actorId,
        'actor-reference' => $actorReference,
        'reason' => $reason,
        'occurred-at' => $occurredAt,
    ];
}

/** @return list<string> */
function fixedEventUuidSequenceM246(): array
{
    return array_map(
        fn (int $index): string => sprintf('018f2460-0000-7000-8000-%012x', $index),
        range(1, 30),
    );
}

/**
 * @param  array<string, string>  $rawOptions
 * @param  list<string>  $eventUuids
 */
function approvedImportProcessTaskM246(
    array $rawOptions,
    array $eventUuids,
    ?string $barrierPrefix = null,
    ?string $worker = null,
    ?string $waitForMarker = null,
): Closure {
    return static function () use ($barrierPrefix, $eventUuids, $rawOptions, $waitForMarker, $worker): array {
        $databaseName = (string) DB::selectOne('select current_database() as database')->database;

        if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || $databaseName !== 'testing') {
            throw new RuntimeException('M2.4.6 child process refused a non-testing PostgreSQL database.');
        }

        $artifacts = app(ApprovedCatalogImportArtifactsLoader::class)->loadCommandOptions($rawOptions);
        $input = ApprovedCatalogImportExecutionInput::fromCommandOptions($rawOptions, true);

        if ($barrierPrefix !== null && $worker !== null) {
            DB::table('cache')->insert([
                'key' => "{$barrierPrefix}:{$worker}",
                'value' => 'ready',
                'expiration' => time() + 60,
            ]);
            $deadline = microtime(true) + 10;

            while (DB::table('cache')->where('key', 'like', "{$barrierPrefix}:%")->count() < 2) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('M2.4.6 importer readiness barrier timed out.');
                }

                usleep(10_000);
            }
        }

        if ($waitForMarker !== null) {
            $deadline = microtime(true) + 10;

            while (! DB::table('cache')->where('key', $waitForMarker)->exists()) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('M2.4.6 competing-lock marker timed out.');
                }

                usleep(10_000);
            }
        }

        $queries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'time_ms' => $query->time];
        });
        Str::createUuidsUsingSequence($eventUuids);
        $startedAt = microtime(true);

        try {
            $result = app(ApplyApprovedLegacyCatalogImport::class)->execute($artifacts, $input);
        } finally {
            $finishedAt = microtime(true);
            Str::createUuidsNormally();
            Event::forget(QueryExecuted::class);
        }

        $profile = [];
        $advisoryTimeMs = null;

        foreach ($queries as $query) {
            preg_match('/^\s*([a-z]+)/i', $query['sql'], $matches);
            $statement = strtolower($matches[1] ?? 'unknown');
            $profile[$statement] = ($profile[$statement] ?? 0) + 1;

            if (str_contains($query['sql'], 'pg_advisory_xact_lock')) {
                $advisoryTimeMs = $query['time_ms'];
            }
        }

        ksort($profile);

        return [
            'actor_id' => $input->actorId,
            'actor_reference' => $input->actorReference,
            'reason' => $input->reason,
            'occurred_at' => $input->occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'outcome' => $result->outcome->value,
            'fingerprints' => $result->graphFingerprints,
            'profile' => $profile,
            'queries' => array_column($queries, 'sql'),
            'advisory_time_ms' => $advisoryTimeMs,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ];
    };
}

function competingSourceProcessTaskM246(int $actorId, string $marker): Closure
{
    return static function () use ($actorId, $marker): array {
        $databaseName = (string) DB::selectOne('select current_database() as database')->database;

        if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || $databaseName !== 'testing') {
            throw new RuntimeException('M2.4.6 competing process refused a non-testing PostgreSQL database.');
        }

        DB::select('select pg_advisory_lock(cast(? as integer), cast(? as integer))', [-1029611933, 1699491839]);

        try {
            DB::table('cache')->insert([
                'key' => $marker,
                'value' => 'lock-held',
                'expiration' => time() + 60,
            ]);
            usleep(750_000);
            FoodSource::factory()->create([
                'public_id' => 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
                'title' => 'Committed competing source conflict',
                'created_by_user_id' => $actorId,
            ]);
        } finally {
            DB::select('select pg_advisory_unlock(cast(? as integer), cast(? as integer))', [-1029611933, 1699491839]);
        }

        return ['outcome' => 'conflict_committed'];
    };
}

/** @param list<int> $actorIds */
function cleanupApprovedImportConcurrencyM246(array $actorIds): void
{
    DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS m246_delay_source_insert ON food_sources;
        DROP TRIGGER IF EXISTS m246_reject_alias_insert ON food_aliases;
        DROP FUNCTION IF EXISTS m246_delay_source_insert();
        DROP FUNCTION IF EXISTS m246_reject_alias_insert();
        SQL);
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

    User::query()->whereIn('id', $actorIds)->delete();

    if (DB::transactionLevel() === 0) {
        DB::beginTransaction();
    }
}

/** @return list<string> */
function approvedSubjectPublicIdsM246(): array
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

it('serializes concurrent first application into one apply and one exact replay', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('M2.4.6 concurrency requires independent PostgreSQL sessions.');
    }

    $databaseName = (string) DB::selectOne('select current_database() as database')->database;
    expect(app()->environment('testing'))->toBeTrue()
        ->and($databaseName)->toBe('testing');

    DB::commit();
    $actorIds = [246001, 246002];
    $actorA = User::factory()->create(['id' => $actorIds[0]]);
    $actorB = User::factory()->create(['id' => $actorIds[1]]);
    $contexts = [
        $actorA->id => [
            'reference' => 'audit:catalog-import:m246-a',
            'reason' => 'Concurrent approved catalog import attempt A.',
            'occurred_at' => '2026-08-03T10:00:00.111111Z',
        ],
        $actorB->id => [
            'reference' => 'audit:catalog-import:m246-b',
            'reason' => 'Concurrent approved catalog import attempt B.',
            'occurred_at' => '2026-08-03T10:00:00.222222Z',
        ],
    ];

    try {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION m246_delay_source_insert() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep(0.75);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER m246_delay_source_insert BEFORE INSERT ON food_sources
            FOR EACH ROW EXECUTE FUNCTION m246_delay_source_insert();
            SQL);
        $barrier = 'catalog-import:m246:'.Str::uuid7();
        $eventUuids = fixedEventUuidSequenceM246();
        $results = Concurrency::driver('process')->run([
            approvedImportProcessTaskM246(
                approvedImportRawOptionsM246(
                    $actorA->id,
                    $contexts[$actorA->id]['reference'],
                    $contexts[$actorA->id]['reason'],
                    $contexts[$actorA->id]['occurred_at'],
                ),
                $eventUuids,
                $barrier,
                'a',
            ),
            approvedImportProcessTaskM246(
                approvedImportRawOptionsM246(
                    $actorB->id,
                    $contexts[$actorB->id]['reference'],
                    $contexts[$actorB->id]['reason'],
                    $contexts[$actorB->id]['occurred_at'],
                ),
                $eventUuids,
                $barrier,
                'b',
            ),
        ], timeout: 30);
        DB::unprepared('DROP TRIGGER IF EXISTS m246_delay_source_insert ON food_sources; DROP FUNCTION IF EXISTS m246_delay_source_insert();');

        $winner = collect($results)->sole('outcome', 'applied');
        $loser = collect($results)->sole('outcome', 'no_op_replay');
        $winnerContext = $contexts[$winner['actor_id']];
        $loserContext = $contexts[$loser['actor_id']];
        $events = CatalogLifecycleEvent::query()->orderBy('id')->get();

        expect(collect($results)->pluck('outcome')->sort()->values()->all())
            ->toBe(['applied', 'no_op_replay'])
            ->and(collect($results)->where('outcome', 'applied'))->toHaveCount(1)
            ->and(collect($results)->where('outcome', 'no_op_replay'))->toHaveCount(1)
            ->and($winner['profile'])->toBe(['insert' => 23, 'select' => 45])
            ->and($loser['profile'])->toBe(['select' => 22])
            ->and(substr_count(implode('\n', $winner['queries']), 'pg_advisory_xact_lock'))->toBe(1)
            ->and(substr_count(implode('\n', $loser['queries']), 'pg_advisory_xact_lock'))->toBe(1)
            ->and($loser['advisory_time_ms'])->toBeGreaterThanOrEqual(500.0)
            ->and($loser['finished_at'])->toBeGreaterThan($winner['finished_at'])
            ->and($winner['queries'][0])->toContain('users')
            ->and($winner['queries'][1])->toContain('pg_advisory_xact_lock')
            ->and($loser['queries'][0])->toContain('users')
            ->and($loser['queries'][1])->toContain('pg_advisory_xact_lock');

        expect([
            FoodSource::query()->count(),
            FoodReference::query()->count(),
            FoodReferenceVersion::query()->count(),
            FoodReferenceVersionSource::query()->count(),
            FoodAlias::query()->count(),
            CatalogLifecycleEvent::query()->count(),
            FoodPortion::query()->count(),
        ])->toBe([1, 3, 3, 3, 3, 10, 0])
            ->and(FoodSource::query()->pluck('public_id')->all())->toBe(['ead17ec3-6176-5f48-b25c-6f4ce3ce9907'])
            ->and(collect([
                ...FoodReference::query()->pluck('public_id')->all(),
                ...FoodReferenceVersion::query()->pluck('public_id')->all(),
                ...FoodAlias::query()->pluck('public_id')->all(),
            ])->sort()->values()->all())->toBe(collect(approvedSubjectPublicIdsM246())->reject(
                fn (string $publicId): bool => $publicId === 'ead17ec3-6176-5f48-b25c-6f4ce3ce9907',
            )->sort()->values()->all())
            ->and(FoodReference::query()->distinct('stable_key')->count('stable_key'))->toBe(3)
            ->and(FoodReferenceVersionSource::query()->get()
                ->map(fn (FoodReferenceVersionSource $link): string => "{$link->food_reference_version_id}:{$link->food_source_id}")
                ->unique()->count())->toBe(3)
            ->and(FoodAlias::query()->get()
                ->map(fn (FoodAlias $alias): string => "{$alias->lineage_id}:{$alias->revision_number}")
                ->unique()->count())->toBe(3)
            ->and(FoodReferenceVersion::query()->whereNotNull('published_at')->count())->toBe(0)
            ->and(FoodReferenceVersion::query()->whereNotNull('activated_at')->count())->toBe(0)
            ->and(FoodReferenceVersion::query()->whereNotNull('supersedes_food_reference_version_id')->count())->toBe(0)
            ->and(FoodAlias::query()->whereNotNull('published_at')->count())->toBe(0)
            ->and(FoodAlias::query()->whereNotNull('activated_at')->count())->toBe(0)
            ->and(FoodAlias::query()->whereNotNull('supersedes_food_alias_id')->count())->toBe(0);

        expect($events)->toHaveCount(10)
            ->and($events->pluck('public_id')->all())->toBe(array_values(array_filter(
                $eventUuids,
                fn (string $uuid, int $index): bool => ($index + 1) % 3 === 0,
                ARRAY_FILTER_USE_BOTH,
            )))
            ->and($events->where('event_type', CatalogLifecycleOperation::CreateSource))->toHaveCount(1)
            ->and($events->where('event_type', CatalogLifecycleOperation::CreateReference))->toHaveCount(3)
            ->and($events->where('event_type', CatalogLifecycleOperation::CreateDraft)
                ->where('subject_type', CatalogLifecycleSubjectType::ReferenceVersion))->toHaveCount(3)
            ->and($events->where('event_type', CatalogLifecycleOperation::CreateDraft)
                ->where('subject_type', CatalogLifecycleSubjectType::Alias))->toHaveCount(3)
            ->and($events->every(fn (CatalogLifecycleEvent $event): bool => $event->actor_user_id === $winner['actor_id']
                && $event->actor_reference === $winnerContext['reference']
                && $event->reason === $winnerContext['reason']
                && $event->occurred_at->format('Y-m-d\TH:i:s.u\Z') === $winnerContext['occurred_at']))->toBeTrue()
            ->and(CatalogLifecycleEvent::query()->where('actor_user_id', $loser['actor_id'])->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->where('actor_reference', $loserContext['reference'])->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->where('reason', $loserContext['reason'])->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->where('occurred_at', $loserContext['occurred_at'])->count())->toBe(0);

        expect(FoodSource::query()->where('created_by_user_id', $winner['actor_id'])->count())->toBe(1)
            ->and(FoodReference::query()->where('created_by_user_id', $winner['actor_id'])->count())->toBe(3)
            ->and(FoodReferenceVersion::query()->where('created_by_user_id', $winner['actor_id'])->count())->toBe(3)
            ->and(FoodReferenceVersionSource::query()->where('created_by_user_id', $winner['actor_id'])->count())->toBe(3)
            ->and(FoodAlias::query()->where('created_by_user_id', $winner['actor_id'])->count())->toBe(3)
            ->and(FoodSource::query()->where('created_by_user_id', $loser['actor_id'])->count())->toBe(0);

        $winnerRawOptions = approvedImportRawOptionsM246(
            $winner['actor_id'],
            $winnerContext['reference'],
            $winnerContext['reason'],
            $winnerContext['occurred_at'],
        );
        $artifacts = app(ApprovedCatalogImportArtifactsLoader::class)->loadCommandOptions($winnerRawOptions);
        $inspection = app(ApprovedCatalogImportGraphInspector::class)->inspect($artifacts);
        $expectedFingerprints = [
            'creme de leite' => '7ceab6ca39d30805fda6b7d82406f97376fbd810b8ece2636e94e87fb583b2d1',
            'doce de leite' => 'cd6351ecdeb28f9332b042b4a58477c2c05b26298d48b69fc1b5a110789cf14b',
            'leite condensado' => '7caff5c6fb1ac7ba1cdf887e367261f26a76582d0b67c55938805b1fac1598d9',
        ];

        expect($inspection->state)->toBe(ApprovedCatalogImportGraphState::Exact)
            ->and($inspection->graphFingerprints)->toBe($expectedFingerprints)
            ->and($winner['fingerprints'])->toBe($expectedFingerprints)
            ->and($loser['fingerprints'])->toBe($expectedFingerprints);

        foreach ($events as $event) {
            $idempotencyKey = CatalogImportDeterministicIdentity::lifecycleIdempotencyKey(
                new CatalogImportLifecycleIdempotencyInput(
                    manifestChecksum: $artifacts->manifest->checksum,
                    subjectType: $event->subject_type,
                    subjectPublicId: $event->subject_public_id,
                    operation: $event->event_type,
                    actorId: (string) $event->actor_user_id,
                    actorReference: $event->actor_reference,
                    reason: $event->reason,
                    occurredAt: $event->occurred_at->toDateTimeImmutable(),
                ),
            );
            $command = new CatalogLifecycleCommand(
                subjectType: $event->subject_type,
                subjectId: $event->subject_public_id,
                operation: $event->event_type,
                actorId: (string) $event->actor_user_id,
                reason: $event->reason,
                idempotencyKey: $idempotencyKey,
                occurredAt: $event->occurred_at->toDateTimeImmutable(),
            );

            expect($event->idempotency_key)->toBe($idempotencyKey)
                ->and($event->command_fingerprint)->toBe(
                    CatalogLifecycleCommandFingerprint::forCommand($command, $event->actor_reference),
                );
        }
    } finally {
        cleanupApprovedImportConcurrencyM246($actorIds);
    }
});

it('waits for a competing lock holder and rereads its committed source conflict', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('M2.4.6 conflict concurrency requires independent PostgreSQL sessions.');
    }

    expect(app()->environment('testing'))->toBeTrue()
        ->and((string) DB::selectOne('select current_database() as database')->database)->toBe('testing');
    DB::commit();
    $actorIds = [246011, 246012];
    User::factory()->create(['id' => $actorIds[0]]);
    User::factory()->create(['id' => $actorIds[1]]);
    $marker = 'catalog-import:m246:conflict:'.Str::uuid7();
    $importOptions = approvedImportRawOptionsM246(
        $actorIds[1],
        'audit:catalog-import:m246-conflict',
        'Reread committed competing source conflict.',
        '2026-08-03T11:00:00.333333Z',
    );

    try {
        $results = Concurrency::driver('process')->run([
            competingSourceProcessTaskM246($actorIds[0], $marker),
            approvedImportProcessTaskM246(
                $importOptions,
                fixedEventUuidSequenceM246(),
                waitForMarker: $marker,
            ),
        ], timeout: 30);
        $importer = $results[1];

        expect($results[0]['outcome'])->toBe('conflict_committed')
            ->and($importer['outcome'])->toBe('catalog_conflict')
            ->and($importer['profile'])->toBe(['select' => 13])
            ->and($importer['advisory_time_ms'])->toBeGreaterThanOrEqual(500.0)
            ->and(substr_count(implode('\n', $importer['queries']), 'pg_advisory_xact_lock'))->toBe(1)
            ->and(FoodSource::query()->count())->toBe(1)
            ->and(FoodSource::query()->sole()->title)->toBe('Committed competing source conflict')
            ->and(FoodSource::query()->sole()->created_by_user_id)->toBe($actorIds[0])
            ->and(FoodReference::query()->count())->toBe(0)
            ->and(FoodReferenceVersion::query()->count())->toBe(0)
            ->and(FoodReferenceVersionSource::query()->count())->toBe(0)
            ->and(FoodAlias::query()->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->count())->toBe(0);
    } finally {
        cleanupApprovedImportConcurrencyM246($actorIds);
    }
});

it('replays from a different actor process and releases the lock after commit', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('M2.4.6 replay concurrency requires independent PostgreSQL sessions.');
    }

    expect(app()->environment('testing'))->toBeTrue()
        ->and((string) DB::selectOne('select current_database() as database')->database)->toBe('testing');
    DB::commit();
    $actorIds = [246021, 246022];
    User::factory()->create(['id' => $actorIds[0]]);
    User::factory()->create(['id' => $actorIds[1]]);

    try {
        $first = Concurrency::driver('process')->run([
            approvedImportProcessTaskM246(
                approvedImportRawOptionsM246(
                    $actorIds[0],
                    'audit:catalog-import:m246-original',
                    'Apply before independent process replay.',
                    '2026-08-03T12:00:00.444444Z',
                ),
                fixedEventUuidSequenceM246(),
            ),
        ], timeout: 30)[0];
        $beforeEvents = CatalogLifecycleEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        DB::beginTransaction();
        try {
            $commitReleased = (bool) DB::selectOne(
                'select pg_try_advisory_xact_lock(cast(? as integer), cast(? as integer)) as acquired',
                [-1029611933, 1699491839],
            )->acquired;
        } finally {
            DB::rollBack();
        }

        $replay = Concurrency::driver('process')->run([
            approvedImportProcessTaskM246(
                approvedImportRawOptionsM246(
                    $actorIds[1],
                    'audit:catalog-import:m246-replay',
                    'Replay from another independent process.',
                    '2026-08-03T12:30:00.555555Z',
                ),
                fixedEventUuidSequenceM246(),
            ),
        ], timeout: 30)[0];

        expect($first['outcome'])->toBe('applied')
            ->and($first['profile'])->toBe(['insert' => 23, 'select' => 45])
            ->and($commitReleased)->toBeTrue()
            ->and($replay['outcome'])->toBe('no_op_replay')
            ->and($replay['profile'])->toBe(['select' => 22])
            ->and(CatalogLifecycleEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeEvents)
            ->and(CatalogLifecycleEvent::query()->where('actor_user_id', $actorIds[1])->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->where('actor_reference', 'audit:catalog-import:m246-replay')->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->where('reason', 'Replay from another independent process.')->count())->toBe(0)
            ->and(CatalogLifecycleEvent::query()->where('occurred_at', '2026-08-03T12:30:00.555555Z')->count())->toBe(0);
    } finally {
        cleanupApprovedImportConcurrencyM246($actorIds);
    }
});

it('releases the transaction lock after a rolled-back partial write', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('M2.4.6 rollback concurrency requires independent PostgreSQL sessions.');
    }

    expect(app()->environment('testing'))->toBeTrue()
        ->and((string) DB::selectOne('select current_database() as database')->database)->toBe('testing');
    DB::commit();
    $actorIds = [246031];
    User::factory()->create(['id' => $actorIds[0]]);
    $rawOptions = approvedImportRawOptionsM246(
        $actorIds[0],
        'audit:catalog-import:m246-rollback',
        'Verify automatic advisory lock release after rollback.',
        '2026-08-03T13:00:00.666666Z',
    );

    try {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION m246_reject_alias_insert() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'forced M2.4.6 alias failure';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER m246_reject_alias_insert BEFORE INSERT ON food_aliases
            FOR EACH ROW EXECUTE FUNCTION m246_reject_alias_insert();
            SQL);
        $failed = Concurrency::driver('process')->run([
            approvedImportProcessTaskM246($rawOptions, fixedEventUuidSequenceM246()),
        ], timeout: 30)[0];

        expect($failed['outcome'])->toBe('persistence_failed')
            ->and([
                FoodSource::query()->count(),
                FoodReference::query()->count(),
                FoodReferenceVersion::query()->count(),
                FoodReferenceVersionSource::query()->count(),
                FoodAlias::query()->count(),
                CatalogLifecycleEvent::query()->count(),
            ])->toBe([0, 0, 0, 0, 0, 0]);

        DB::unprepared('DROP TRIGGER IF EXISTS m246_reject_alias_insert ON food_aliases; DROP FUNCTION IF EXISTS m246_reject_alias_insert();');
        $applied = Concurrency::driver('process')->run([
            approvedImportProcessTaskM246($rawOptions, fixedEventUuidSequenceM246()),
        ], timeout: 30)[0];

        expect($applied['outcome'])->toBe('applied')
            ->and($applied['profile'])->toBe(['insert' => 23, 'select' => 45])
            ->and([
                FoodSource::query()->count(),
                FoodReference::query()->count(),
                FoodReferenceVersion::query()->count(),
                FoodReferenceVersionSource::query()->count(),
                FoodAlias::query()->count(),
                CatalogLifecycleEvent::query()->count(),
            ])->toBe([1, 3, 3, 3, 3, 10]);
    } finally {
        cleanupApprovedImportConcurrencyM246($actorIds);
    }
});
