<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait WithoutAutomaticRefreshDatabaseForCatalogM232
{
    protected function setUpTraits()
    {
        unset($this->traitsUsedByTest[RefreshDatabase::class]);

        return parent::setUpTraits();
    }
}

pest()->use(WithoutAutomaticRefreshDatabaseForCatalogM232::class);

/**
 * PostgreSQL-only vocabulary, ownership, lifecycle, and positive-number CHECK constraints are
 * intentionally not asserted here. SQLite compatibility covers portable constraints and all
 * required partial unique indexes without loading the legacy pgvector migration.
 */
it('runs and rolls back only the six catalog migrations on isolated SQLite', function () {
    $originalDefaultConnection = config('database.default');
    $originalConnections = config('database.connections');
    $connectionName = 'catalog_m232_sqlite';
    $migrations = [];
    $catalogTables = [
        'food_sources',
        'food_references',
        'food_reference_versions',
        'food_reference_version_sources',
        'food_aliases',
        'food_portions',
    ];
    $migrationFiles = [
        database_path('migrations/2026_07_11_191709_create_food_sources_table.php'),
        database_path('migrations/2026_07_11_191724_create_food_references_table.php'),
        database_path('migrations/2026_07_11_191729_create_food_reference_versions_table.php'),
        database_path('migrations/2026_07_11_191733_create_food_reference_version_sources_table.php'),
        database_path('migrations/2026_07_11_191737_create_food_aliases_table.php'),
        database_path('migrations/2026_07_11_191745_create_food_portions_table.php'),
    ];

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

        expect((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1)
            ->and(collect($migrationFiles)->contains(
                fn (string $file): bool => str_contains($file, 'enable_pgvector_extension'),
            ))->toBeFalse();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });

        foreach ($migrationFiles as $migrationFile) {
            $migration = require $migrationFile;
            $migration->up();
            $migrations[] = $migration;
        }

        foreach ($catalogTables as $catalogTable) {
            expect(Schema::hasTable($catalogTable))->toBeTrue();
        }

        $requiredColumns = [
            'food_sources' => ['id', 'public_id', 'visibility', 'owner_user_id', 'kind', 'authority_status', 'title', 'publisher', 'edition', 'source_uri', 'citation', 'license', 'checksum_algorithm', 'checksum', 'retrieved_at', 'metadata', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
            'food_references' => ['id', 'public_id', 'stable_key', 'visibility', 'owner_user_id', 'is_generic', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
            'food_reference_versions' => ['id', 'public_id', 'food_reference_id', 'version_number', 'canonical_name', 'normalized_canonical_name', 'locale', 'classification', 'preparation_key', 'energy_basis_grams', 'energy_kcal', 'nutrient_values', 'provenance', 'review_status', 'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason', 'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id', 'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason', 'supersedes_food_reference_version_id', 'created_by_user_id', 'created_at', 'updated_at'],
            'food_reference_version_sources' => ['id', 'food_reference_version_id', 'food_source_id', 'role', 'source_record_key', 'evidence_metadata', 'created_by_user_id', 'created_at', 'updated_at'],
            'food_aliases' => ['id', 'public_id', 'lineage_id', 'food_reference_id', 'revision_number', 'supersedes_food_alias_id', 'display_alias', 'normalized_alias', 'locale', 'alias_kind', 'food_source_id', 'source_record_key', 'provenance', 'review_status', 'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason', 'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id', 'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
            'food_portions' => ['id', 'public_id', 'lineage_id', 'food_reference_id', 'revision_number', 'supersedes_food_portion_id', 'portion_key', 'display_label', 'normalized_label', 'locale', 'portion_type', 'unit_code', 'unit_quantity', 'gram_weight', 'preparation_key', 'size_label', 'food_source_id', 'source_record_key', 'provenance', 'review_status', 'submitted_at', 'submitted_by_user_id', 'reviewed_at', 'reviewed_by_user_id', 'review_reason', 'published_at', 'published_by_user_id', 'activated_at', 'activated_by_user_id', 'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason', 'archived_at', 'archived_by_user_id', 'archive_reason', 'created_by_user_id', 'created_at', 'updated_at'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            expect(Schema::getColumnListing($table))->toContain(...$columns);
        }

        $requiredPartialIndexes = [
            'food_references' => ['food_references_global_stable_key_unique', 'food_references_private_stable_key_unique'],
            'food_reference_versions' => ['food_reference_versions_one_active_unique'],
            'food_reference_version_sources' => ['food_reference_version_sources_one_primary_unique'],
            'food_aliases' => ['food_aliases_one_active_key_unique'],
            'food_portions' => ['food_portions_one_active_key_unique'],
        ];

        foreach ($requiredPartialIndexes as $table => $indexNames) {
            $actualIndexNames = array_column(Schema::getIndexes($table), 'name');
            expect($actualIndexNames)->toContain(...$indexNames);
        }

        $insertSource = fn (array $values = []): int => DB::table('food_sources')->insertGetId(array_merge([
            'public_id' => '00000000-0000-4000-8000-000000000001',
            'visibility' => 'global',
            'owner_user_id' => null,
            'kind' => 'curated_dataset',
            'authority_status' => 'eligible',
            'title' => 'SQLite source',
        ], $values));
        $insertReference = fn (array $values = []): int => DB::table('food_references')->insertGetId(array_merge([
            'public_id' => '00000000-0000-4000-8000-000000000101',
            'stable_key' => 'sqlite-reference',
            'visibility' => 'global',
            'owner_user_id' => null,
        ], $values));
        $insertVersion = fn (int $referenceId, array $values = []): int => DB::table('food_reference_versions')->insertGetId(array_merge([
            'public_id' => '00000000-0000-4000-8000-000000000201',
            'food_reference_id' => $referenceId,
            'version_number' => 1,
            'canonical_name' => 'SQLite food',
            'normalized_canonical_name' => 'sqlite food',
            'locale' => 'pt-BR',
            'classification' => 'food',
        ], $values));
        $assertRejected = function (Closure $operation): void {
            expect($operation)->toThrow(QueryException::class);
        };

        $sourceId = $insertSource();
        $assertRejected(fn () => $insertSource(['title' => 'Duplicate public ID']));

        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        $globalReferenceId = $insertReference();
        $assertRejected(fn () => $insertReference([
            'public_id' => '00000000-0000-4000-8000-000000000102',
            'stable_key' => 'sqlite-reference',
        ]));
        $privateReferenceId = $insertReference([
            'public_id' => '00000000-0000-4000-8000-000000000103',
            'stable_key' => 'private-key',
            'visibility' => 'private',
            'owner_user_id' => 1,
        ]);
        $insertReference([
            'public_id' => '00000000-0000-4000-8000-000000000104',
            'stable_key' => 'private-key',
            'visibility' => 'private',
            'owner_user_id' => 2,
        ]);
        $assertRejected(fn () => $insertReference([
            'public_id' => '00000000-0000-4000-8000-000000000105',
            'stable_key' => 'private-key',
            'visibility' => 'private',
            'owner_user_id' => 1,
        ]));

        $versionId = $insertVersion($globalReferenceId, ['activated_at' => '2026-01-01 00:00:00']);
        $assertRejected(fn () => $insertVersion($globalReferenceId, [
            'public_id' => '00000000-0000-4000-8000-000000000202',
            'version_number' => 1,
        ]));
        $assertRejected(fn () => $insertVersion($globalReferenceId, [
            'public_id' => '00000000-0000-4000-8000-000000000203',
            'version_number' => 2,
            'activated_at' => '2026-01-02 00:00:00',
        ]));
        $insertVersion($globalReferenceId, [
            'public_id' => '00000000-0000-4000-8000-000000000204',
            'version_number' => 2,
        ]);

        DB::table('food_reference_version_sources')->insert([
            'food_reference_version_id' => $versionId,
            'food_source_id' => $sourceId,
            'role' => 'primary',
        ]);
        $supportingSourceId = $insertSource([
            'public_id' => '00000000-0000-4000-8000-000000000002',
            'title' => 'Supporting source',
        ]);
        $assertRejected(fn () => DB::table('food_reference_version_sources')->insert([
            'food_reference_version_id' => $versionId,
            'food_source_id' => $supportingSourceId,
            'role' => 'primary',
        ]));
        DB::table('food_reference_version_sources')->insert([
            'food_reference_version_id' => $versionId,
            'food_source_id' => $supportingSourceId,
            'role' => 'supporting',
        ]);
        $assertRejected(fn () => DB::table('food_reference_version_sources')->insert([
            'food_reference_version_id' => $versionId,
            'food_source_id' => $supportingSourceId,
            'role' => 'supporting',
        ]));

        $alias = [
            'public_id' => '00000000-0000-4000-8000-000000000301',
            'lineage_id' => '00000000-0000-4000-8000-000000000311',
            'food_reference_id' => $globalReferenceId,
            'revision_number' => 1,
            'display_alias' => 'Comida SQLite',
            'normalized_alias' => 'comida sqlite',
            'locale' => 'pt-BR',
            'alias_kind' => 'common',
            'activated_at' => '2026-01-01 00:00:00',
        ];
        DB::table('food_aliases')->insert($alias);
        $assertRejected(fn () => DB::table('food_aliases')->insert(array_merge($alias, [
            'public_id' => '00000000-0000-4000-8000-000000000302',
            'lineage_id' => '00000000-0000-4000-8000-000000000312',
        ])));
        DB::table('food_aliases')->insert(array_merge($alias, [
            'public_id' => '00000000-0000-4000-8000-000000000303',
            'lineage_id' => '00000000-0000-4000-8000-000000000313',
            'food_reference_id' => $privateReferenceId,
        ]));
        DB::table('food_aliases')->insert(array_merge($alias, [
            'public_id' => '00000000-0000-4000-8000-000000000304',
            'revision_number' => 2,
            'activated_at' => null,
        ]));
        $assertRejected(fn () => DB::table('food_aliases')->insert(array_merge($alias, [
            'public_id' => '00000000-0000-4000-8000-000000000305',
            'activated_at' => null,
        ])));

        $portion = [
            'public_id' => '00000000-0000-4000-8000-000000000401',
            'lineage_id' => '00000000-0000-4000-8000-000000000411',
            'food_reference_id' => $globalReferenceId,
            'revision_number' => 1,
            'portion_key' => 'cup',
            'display_label' => 'Xícara',
            'normalized_label' => 'xicara',
            'locale' => 'pt-BR',
            'portion_type' => 'household',
            'unit_code' => 'cup',
            'unit_quantity' => 1,
            'gram_weight' => 100,
            'activated_at' => '2026-01-01 00:00:00',
        ];
        DB::table('food_portions')->insert($portion);
        $assertRejected(fn () => DB::table('food_portions')->insert(array_merge($portion, [
            'public_id' => '00000000-0000-4000-8000-000000000402',
            'lineage_id' => '00000000-0000-4000-8000-000000000412',
        ])));
        DB::table('food_portions')->insert(array_merge($portion, [
            'public_id' => '00000000-0000-4000-8000-000000000403',
            'revision_number' => 2,
            'activated_at' => null,
        ]));
        $assertRejected(fn () => DB::table('food_portions')->insert(array_merge($portion, [
            'public_id' => '00000000-0000-4000-8000-000000000404',
            'activated_at' => null,
        ])));

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $migrations = [];

        foreach ($catalogTables as $catalogTable) {
            expect(Schema::hasTable($catalogTable))->toBeFalse();
        }
    } finally {
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        DB::purge($connectionName);
        config()->set('database.default', $originalDefaultConnection);
        DB::setDefaultConnection($originalDefaultConnection);
        config()->set('database.connections', $originalConnections);
    }
});
