<?php

function catalogIntegrityConstraintMigrationFilesM2345(): array
{
    $migrationDirectory = dirname(__DIR__, 4).'/database/migrations';

    return glob("{$migrationDirectory}/*_add_minimal_catalog_integrity_constraints.php") ?: [];
}

function catalogIntegrityConstraintMigrationSourceM2345(): string
{
    $files = catalogIntegrityConstraintMigrationFilesM2345();

    return isset($files[0]) ? (file_get_contents($files[0]) ?: '') : '';
}

it('adds exactly one reversible integrity constraint migration and no second schema phase', function () {
    $files = catalogIntegrityConstraintMigrationFilesM2345();

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_add_minimal_catalog_integrity_constraints\.php$/')
        ->and(catalogIntegrityConstraintMigrationSourceM2345())
        ->toContain('public function up(): void', 'public function down(): void')
        ->not->toContain('Schema::create', 'Schema::drop', 'Schema::dropIfExists');
});

it('uses explicit table-specific functions without a dynamic trigger framework', function () {
    $source = catalogIntegrityConstraintMigrationSourceM2345();

    expect($source)->toContain(
        'fn_catalog_block_physical_delete',
        'fn_food_sources_guard_identity_and_used_content',
        'fn_food_references_guard_identity',
        'fn_food_reference_versions_guard_identity_and_frozen_content',
        'fn_food_aliases_guard_identity_and_frozen_content',
        'fn_food_portions_guard_identity_and_frozen_content',
        'fn_food_reference_version_sources_guard_frozen_parent',
        'IS DISTINCT FROM',
        "ERRCODE = 'N3402'",
    )->not->toMatch('/EXECUTE\s+format|information_schema|jsonb?_each|jsonb?_object|column_name|dynamic sql/i');
});

it('alters only the six approved catalog tables and leaves lifecycle events untouched', function () {
    $source = catalogIntegrityConstraintMigrationSourceM2345();
    preg_match_all('/\b(food_sources|food_references|food_reference_versions|food_reference_version_sources|food_aliases|food_portions)\b/', $source, $matches);
    $tables = array_values(array_unique($matches[1]));
    sort($tables);

    expect($tables)->toBe([
        'food_aliases',
        'food_portions',
        'food_reference_version_sources',
        'food_reference_versions',
        'food_references',
        'food_sources',
    ])->and($source)->not->toContain(
        'catalog_lifecycle_events',
        'fn_catalog_lifecycle_events_append_only',
        'trg_catalog_lifecycle_events',
    );
});

it('does not duplicate lifecycle authorization eligibility idempotency or audit policies', function () {
    $source = catalogIntegrityConstraintMigrationSourceM2345();

    expect($source)
        ->not->toMatch('/submit_for_review|return_to_draft|\bapprove\b|\breject\b|\bpublish\b|\bactivate\b|\breactivate\b|\bdeactivate\b|\bwithdraw\b|\barchive transition\b/i')
        ->not->toMatch('/authorization|authorize|permission|self[_ -]?approval|actor required|reason required/i')
        ->not->toMatch('/source[_ -]?(eligibility|eligible|authority|ownership)|owner compatibility|primary source/i')
        ->not->toMatch('/idempotency|command replay|event insert|INSERT\s+INTO\s+catalog_lifecycle_events/i')
        ->not->toMatch('/active replacement|replaceActive|create_successor|successor creation|nextVersion|nextRevision|allocate.*(version|revision|number)/i');
});

it('contains no unrelated nutrition runtime or legacy concerns', function () {
    $source = catalogIntegrityConstraintMigrationSourceM2345();

    expect($source)
        ->not->toMatch('/Laravel\\Ai|App\\Ai|\bRAG\b|embedding|vector store|semantic search/i')
        ->not->toMatch('/meal|history|calorie|estimate|NutritionEstimate|legacy|resolver|importer/i')
        ->not->toMatch('/controller|route|service provider|factory|seeder/i');
});

it('keeps the PostgreSQL boundary explicit and pending review intentionally unfrozen', function () {
    $source = catalogIntegrityConstraintMigrationSourceM2345();

    expect($source)->toContain("getDriverName() !== 'pgsql'", "OLD.review_status IN ('approved', 'rejected')")
        ->not->toMatch("/OLD\.review_status\s*(?:=|IN\s*\([^)]*)[^\n;]*pending_review/i");
});
