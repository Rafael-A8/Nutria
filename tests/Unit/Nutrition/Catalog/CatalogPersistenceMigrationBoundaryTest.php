<?php

function catalogMigrationFilesForM232(): array
{
    $migrationDirectory = dirname(__DIR__, 4).'/database/migrations';
    $files = glob("{$migrationDirectory}/*_create_food*_table.php") ?: [];

    return array_values(array_filter(
        $files,
        fn (string $file): bool => preg_match(
            '/_create_(food_sources|food_references|food_reference_versions|food_reference_version_sources|food_aliases|food_portions)_table\.php$/',
            $file,
        ) === 1,
    ));
}

it('limits M2.3.2 persistence to the six approved additive migrations', function () {
    $files = catalogMigrationFilesForM232();
    sort($files);

    expect($files)->toHaveCount(6)
        ->and(array_map(
            fn (string $file): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_create_|_table\.php$/', '', basename($file)),
            $files,
        ))->toBe([
            'food_sources',
            'food_references',
            'food_reference_versions',
            'food_reference_version_sources',
            'food_aliases',
            'food_portions',
        ]);
});

it('contains schema-only reversible migrations without data or runtime wiring', function () {
    $source = implode("\n", array_map(
        fn (string $file): string => file_get_contents($file) ?: '',
        catalogMigrationFilesForM232(),
    ));

    expect($source)
        ->not->toMatch('/DB::table\s*\([^)]*\)\s*->\s*insert/i')
        ->not->toMatch('/\b(insert|upsert|updateOrInsert)\s*\(/i')
        ->not->toMatch('/\b(call|seed|seeder)\b/i')
        ->not->toMatch('/config\s*\(\s*[\'\"]nutrition|config\/nutrition\.php/i')
        ->not->toMatch('/meal_items|\bmeals\b|meal history/i')
        ->not->toMatch('/embedding|vector|trigram|fuzzy|similarity/i')
        ->not->toMatch('/Laravel\\\\Ai|App\\\\Ai|App\\\\Models|App\\\\Services/i')
        ->not->toMatch('/Factory|Repository|Service|Command|Binding/i')
        ->toMatch('/public function down\(\): void/')
        ->not->toMatch('/Schema::table\s*\(/');
});

it('creates only the approved catalog tables', function () {
    $createdTables = [];

    foreach (catalogMigrationFilesForM232() as $file) {
        $source = file_get_contents($file) ?: '';
        preg_match_all('/Schema::create\(\'([^\']+)\'/', $source, $matches);
        array_push($createdTables, ...$matches[1]);
    }

    sort($createdTables);

    expect($createdTables)->toBe([
        'food_aliases',
        'food_portions',
        'food_reference_version_sources',
        'food_reference_versions',
        'food_references',
        'food_sources',
    ]);
});
