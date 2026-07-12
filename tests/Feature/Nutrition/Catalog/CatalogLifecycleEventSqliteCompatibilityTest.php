<?php

use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait WithoutAutomaticRefreshDatabaseForLifecycleM2343
{
    protected function setUpTraits()
    {
        unset($this->traitsUsedByTest[RefreshDatabase::class]);

        return parent::setUpTraits();
    }
}

pest()->use(WithoutAutomaticRefreshDatabaseForLifecycleM2343::class);

/**
 * PostgreSQL vocabulary, SQLSTATE, JSON-shape checks, and TRUNCATE protection are intentionally
 * not duplicated here. SQLite covers the portable schema, root uniqueness, and row triggers.
 */
it('runs and rolls back only the lifecycle event migration on isolated SQLite', function () {
    $originalDefaultConnection = config('database.default');
    $originalConnections = config('database.connections');
    $connectionName = 'catalog_lifecycle_m2343_sqlite';
    $migration = null;

    config()->set("database.connections.{$connectionName}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', $connectionName);
    DB::setDefaultConnection($connectionName);
    DB::purge($connectionName);

    try {
        DB::statement('PRAGMA foreign_keys = ON');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);

        $migration = require database_path('migrations/2026_07_12_154003_create_catalog_lifecycle_events_table.php');
        $migration->up();

        expect((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1)
            ->and(Schema::hasTable('catalog_lifecycle_events'))->toBeTrue()
            ->and(Schema::getColumnListing('catalog_lifecycle_events'))->toContain(
                'id', 'public_id', 'subject_type', 'subject_id', 'subject_public_id', 'event_type',
                'outcome', 'reason_code', 'reason', 'previous_state', 'next_state', 'eligibility_reasons',
                'actor_user_id', 'actor_reference', 'metadata', 'occurred_at', 'idempotency_key',
                'command_fingerprint', 'correlation_id', 'transaction_id', 'created_at',
            )->and(Schema::hasColumn('catalog_lifecycle_events', 'updated_at'))->toBeFalse();

        $rootKey = '018f1f2e-7b2a-7c4d-8e9f-0123456789ab';
        $base = [
            'public_id' => '018f1f2e-7b2a-7c4d-8e9f-1123456789ab',
            'subject_type' => 'reference_version',
            'subject_id' => 10,
            'subject_public_id' => '018f1f2e-7b2a-7c4d-8e9f-2123456789ab',
            'event_type' => 'submit_for_review',
            'outcome' => 'succeeded',
            'reason_code' => 'transition_applied',
            'previous_state' => 'draft',
            'next_state' => 'pending_review',
            'actor_user_id' => 1,
            'actor_reference' => 'audit:sqlite-actor',
            'occurred_at' => '2026-07-12 12:00:00.123456+00:00',
            'idempotency_key' => $rootKey,
            'command_fingerprint' => hash('sha256', 'sqlite-root'),
            'correlation_id' => '018f1f2e-7b2a-7c4d-8e9f-3123456789ab',
            'transaction_id' => '018f1f2e-7b2a-7c4d-8e9f-4123456789ab',
            'created_at' => '2026-07-12 12:00:01.123456+00:00',
        ];

        $rootEventId = DB::table('catalog_lifecycle_events')->insertGetId($base);
        expect(fn () => DB::table('catalog_lifecycle_events')->insert(array_replace($base, [
            'public_id' => '018f1f2e-7b2a-7c4d-8e9f-5123456789ab',
        ])))->toThrow(QueryException::class);

        $derivedEventIds = [];
        foreach ([6, 7] as $suffix) {
            $derivedEventIds[] = DB::table('catalog_lifecycle_events')->insertGetId(array_replace($base, [
                'public_id' => "018f1f2e-7b2a-7c4d-8e9f-{$suffix}123456789ab",
                'idempotency_key' => null,
                'command_fingerprint' => null,
            ]));
        }

        expect(DB::table('catalog_lifecycle_events')->whereNull('idempotency_key')->count())->toBe(2)
            ->and(fn () => DB::table('catalog_lifecycle_events')->where('idempotency_key', $rootKey)->update(['reason' => 'changed']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('catalog_lifecycle_events')->where('id', $rootEventId)->update(['actor_reference' => 'audit:changed']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('catalog_lifecycle_events')->where('id', $rootEventId)->update(['actor_user_id' => 2]))
            ->toThrow(QueryException::class);

        $beforeExplicitNullification = get_object_vars(DB::table('catalog_lifecycle_events')->find($rootEventId));

        expect(DB::table('catalog_lifecycle_events')->where('id', $rootEventId)->update(['actor_user_id' => null]))->toBe(1);

        $afterExplicitNullification = get_object_vars(DB::table('catalog_lifecycle_events')->find($rootEventId));
        $beforeExplicitNullificationWithoutActor = $beforeExplicitNullification;
        $afterExplicitNullificationWithoutActor = $afterExplicitNullification;
        unset($beforeExplicitNullificationWithoutActor['actor_user_id'], $afterExplicitNullificationWithoutActor['actor_user_id']);

        expect($afterExplicitNullification['actor_user_id'])->toBeNull()
            ->and($afterExplicitNullificationWithoutActor)->toBe($beforeExplicitNullificationWithoutActor)
            ->and(fn () => DB::table('catalog_lifecycle_events')->where('id', $rootEventId)->update(['actor_user_id' => 2]))
            ->toThrow(QueryException::class);

        $foreignKeyNullifiedEventId = $derivedEventIds[0];
        $beforeUserDeletion = get_object_vars(DB::table('catalog_lifecycle_events')->find($foreignKeyNullifiedEventId));

        DB::table('users')->where('id', 1)->delete();

        $afterUserDeletion = get_object_vars(DB::table('catalog_lifecycle_events')->find($foreignKeyNullifiedEventId));
        $beforeUserDeletionWithoutActor = $beforeUserDeletion;
        $afterUserDeletionWithoutActor = $afterUserDeletion;
        unset($beforeUserDeletionWithoutActor['actor_user_id'], $afterUserDeletionWithoutActor['actor_user_id']);

        expect(DB::table('catalog_lifecycle_events')->where('id', $foreignKeyNullifiedEventId)->exists())->toBeTrue()
            ->and($afterUserDeletion['actor_user_id'])->toBeNull()
            ->and($afterUserDeletion['actor_reference'])->toBe('audit:sqlite-actor')
            ->and($afterUserDeletionWithoutActor)->toBe($beforeUserDeletionWithoutActor)
            ->and(fn () => DB::table('catalog_lifecycle_events')->where('idempotency_key', $rootKey)->delete())
            ->toThrow(QueryException::class);

        expect(fn () => CatalogLifecycleEventDraft::root(
            subjectType: CatalogLifecycleSubjectType::ReferenceVersion,
            subjectInternalId: 10,
            subjectPublicId: $base['subject_public_id'],
            operation: CatalogLifecycleOperation::SubmitForReview,
            outcome: CatalogLifecycleOutcome::Succeeded,
            reasonCode: CatalogLifecycleReason::TransitionApplied,
            reason: null,
            previousState: CatalogLifecycleState::Draft,
            nextState: CatalogLifecycleState::PendingReview,
            eligibilityReasons: [],
            actorUserId: null,
            actorReference: 'audit:sqlite-actor',
            metadata: [],
            occurredAt: new DateTimeImmutable('2026-07-12T12:00:00.123456+00:00'),
            idempotencyKey: $rootKey,
            commandFingerprint: null,
            correlationId: $base['correlation_id'],
            transactionId: $base['transaction_id'],
        ))->toThrow(InvalidArgumentException::class);

        $migration->down();
        $migration = null;

        expect(Schema::hasTable('catalog_lifecycle_events'))->toBeFalse()
            ->and(DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', [
                'trg_catalog_lifecycle_events_block_update',
                'trg_catalog_lifecycle_events_block_delete',
            ])->count())->toBe(0);
    } finally {
        if ($migration !== null && Schema::hasTable('catalog_lifecycle_events')) {
            $migration->down();
        }

        DB::purge($connectionName);
        config()->set('database.default', $originalDefaultConnection);
        DB::setDefaultConnection($originalDefaultConnection);
        config()->set('database.connections', $originalConnections);
    }
});
