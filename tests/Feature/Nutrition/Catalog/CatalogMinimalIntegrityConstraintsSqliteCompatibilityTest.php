<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait WithoutAutomaticRefreshDatabaseForCatalogIntegrityConstraintsM2345
{
    protected function setUpTraits()
    {
        unset($this->traitsUsedByTest[RefreshDatabase::class]);

        return parent::setUpTraits();
    }
}

pest()->use(WithoutAutomaticRefreshDatabaseForCatalogIntegrityConstraintsM2345::class);

/**
 * PostgreSQL functions, SQLSTATEs, checks, and triggers are intentionally not reproduced on SQLite.
 */
it('runs and reverses the integrity constraint migration as a no-op on isolated SQLite', function () {
    $originalDefaultConnection = config('database.default');
    $originalConnections = config('database.connections');
    $connectionName = 'catalog_integrity_constraints_m2345_sqlite';
    $catalogMigrations = [];
    $integrityConstraintMigration = null;

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

        foreach ([
            '2026_07_11_191709_create_food_sources_table.php',
            '2026_07_11_191724_create_food_references_table.php',
            '2026_07_11_191729_create_food_reference_versions_table.php',
            '2026_07_11_191733_create_food_reference_version_sources_table.php',
            '2026_07_11_191737_create_food_aliases_table.php',
            '2026_07_11_191745_create_food_portions_table.php',
        ] as $filename) {
            $migration = require database_path("migrations/{$filename}");
            $migration->up();
            $catalogMigrations[] = $migration;
        }

        $integrityConstraintMigration = require database_path('migrations/2026_07_12_193052_add_minimal_catalog_integrity_constraints.php');
        $integrityConstraintMigration->up();

        expect(Schema::hasTable('food_sources'))->toBeTrue()
            ->and(Schema::hasTable('food_references'))->toBeTrue()
            ->and(Schema::hasTable('food_reference_versions'))->toBeTrue()
            ->and(Schema::hasTable('food_reference_version_sources'))->toBeTrue()
            ->and(Schema::hasTable('food_aliases'))->toBeTrue()
            ->and(Schema::hasTable('food_portions'))->toBeTrue()
            ->and(DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'trg_food_%')->count())->toBe(0);

        $integrityConstraintMigration->down();
        $integrityConstraintMigration = null;

        expect(Schema::hasTable('food_sources'))->toBeTrue()
            ->and(Schema::hasTable('food_references'))->toBeTrue()
            ->and(Schema::hasTable('food_reference_versions'))->toBeTrue()
            ->and(Schema::hasTable('food_reference_version_sources'))->toBeTrue()
            ->and(Schema::hasTable('food_aliases'))->toBeTrue()
            ->and(Schema::hasTable('food_portions'))->toBeTrue()
            ->and(DB::table('sqlite_master')->where('type', 'trigger')->count())->toBe(0);
    } finally {
        if ($integrityConstraintMigration !== null) {
            $integrityConstraintMigration->down();
        }

        foreach (array_reverse($catalogMigrations) as $migration) {
            $migration->down();
        }

        DB::purge($connectionName);
        config()->set('database.default', $originalDefaultConnection);
        DB::setDefaultConnection($originalDefaultConnection);
        config()->set('database.connections', $originalConnections);
    }
});
