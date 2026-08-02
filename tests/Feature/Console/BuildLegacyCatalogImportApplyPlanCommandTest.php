<?php

use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

require_once dirname(__DIR__, 2).'/Support/CatalogImportM244bFixtures.php';

function commandDirectoryM244b(): string
{
    $directory = storage_path('framework/testing/m244b-'.bin2hex(random_bytes(6)));
    mkdir($directory, 0777, true);

    return $directory;
}

function removeCommandDirectoryM244b(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

/** @return array<string, string> */
function selectedCommandOptionsM244b(string $directory, string $report = ''): array
{
    $resolutionBytes = canonicalDocumentBytesM244b(reviewedResolutionDocumentM244b());
    $resolutionPath = $directory.'/reviewed-resolution.json';
    file_put_contents($resolutionPath, $resolutionBytes);
    $approvalBytes = canonicalDocumentBytesM244b(approvalDocumentM244b(hash('sha256', $resolutionBytes)));
    $approvalPath = $directory.'/approval.json';
    file_put_contents($approvalPath, $approvalBytes);

    return [
        '--manifest' => manifestPathM244b(),
        '--expected-manifest-sha256' => MANIFEST_SHA256_M244B,
        '--resolution' => $resolutionPath,
        '--expected-resolution-sha256' => hash('sha256', $resolutionBytes),
        '--approval' => $approvalPath,
        '--expected-approval-sha256' => hash('sha256', $approvalBytes),
        '--apply-plan-output' => $directory.'/apply-plan.json',
        '--report' => $report === '' ? $directory.'/report.json' : $report,
    ];
}

/** @param array<string, mixed> $plan */
function persistSyntheticPlanGraphM244b(array $plan, bool $complete = true): void
{
    $sourceSemantic = $plan['source_plan']['semantic_entity'];
    $source = FoodSource::factory()->create([
        ...array_diff_key($sourceSemantic, array_flip(['archived'])),
        'archived_at' => null,
    ]);
    $candidate = $plan['selected_candidate_plans'][0];
    $referenceSemantic = $candidate['reference_plan']['semantic_entity'];
    $reference = FoodReference::factory()->create([
        ...array_diff_key($referenceSemantic, array_flip(['archived'])),
        'archived_at' => null,
    ]);

    if (! $complete) {
        return;
    }

    $versionSemantic = $candidate['version_plan']['semantic_entity'];
    $version = FoodReferenceVersion::factory()->for($reference, 'reference')->create([
        ...array_diff_key($versionSemantic, array_flip(['reference_public_id', 'predecessor_public_id', 'lifecycle_state'])),
        'archived_at' => null,
        'supersedes_food_reference_version_id' => null,
    ]);
    $link = $candidate['source_link_plan']['semantic_entity'];
    FoodReferenceVersionSource::factory()
        ->for($version, 'version')
        ->for($source, 'source')
        ->create([
            'evidence_metadata' => $link['evidence_metadata'],
            'role' => $link['role'],
            'source_record_key' => $link['source_record_key'],
        ]);

    foreach ($candidate['alias_plans'] as $aliasPlan) {
        if ($aliasPlan['action'] === 'excluded') {
            continue;
        }

        $alias = $aliasPlan['semantic_entity'];
        FoodAlias::factory()
            ->for($reference, 'reference')
            ->for($source, 'source')
            ->create([
                ...array_diff_key($alias, array_flip([
                    'archived', 'reference_public_id', 'source_public_id', 'predecessor_public_id', 'lifecycle_state',
                ])),
                'archived_at' => null,
                'supersedes_food_alias_id' => null,
            ]);
    }
}

it('builds byte-identical synthetic plans twice using only select statements', function () {
    $firstDirectory = commandDirectoryM244b();
    $secondDirectory = commandDirectoryM244b();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    try {
        expect(Artisan::call('catalog:build-legacy-import-apply-plan', selectedCommandOptionsM244b($firstDirectory)))->toBe(0)
            ->and(Artisan::call('catalog:build-legacy-import-apply-plan', selectedCommandOptionsM244b($secondDirectory)))->toBe(0);
        $firstBytes = file_get_contents($firstDirectory.'/apply-plan.json');
        $secondBytes = file_get_contents($secondDirectory.'/apply-plan.json');
        $report = json_decode(file_get_contents($firstDirectory.'/report.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($secondBytes)->toBe($firstBytes)
            ->and(hash('sha256', $secondBytes))->toBe(hash('sha256', $firstBytes))
            ->and(hash('sha256', $firstBytes))->toBe('eeae094a0322fb73e9da57e35bf4060db79917a2b240ef35360e93c1c7193ff6')
            ->and($report['apply_plan_sha256'])->toBe(hash('sha256', $firstBytes))
            ->and($report['counts'])->toMatchArray([
                'selected_candidates' => 1,
                'planned_alias_lineages' => 1,
                'planned_alias_revisions' => 1,
                'planned_sources' => 1,
                'planned_references' => 1,
                'planned_versions' => 1,
                'planned_source_links' => 1,
                'conflicts' => 0,
            ])
            ->and($report['sql_verification']['statement_classes'])->toBe(['select'])
            ->and(count($queries))->toBe($report['sql_verification']['query_count'] * 2)
            ->and($queries)->not->toBe([]);

        foreach ($queries as $sql) {
            expect($sql)->toMatch('/^\s*select\b/i')
                ->and($sql)->not->toMatch('/\b(insert|update|delete|upsert|merge|truncate|alter|create|drop|lock)\b/i');
        }
    } finally {
        removeCommandDirectoryM244b($firstDirectory);
        removeCommandDirectoryM244b($secondDirectory);
    }
});

it('supports report stdout without exposing the canonical plan', function () {
    $directory = commandDirectoryM244b();

    try {
        $options = selectedCommandOptionsM244b($directory, '-');

        expect(Artisan::call('catalog:build-legacy-import-apply-plan', $options))->toBe(0)
            ->and(is_file($directory.'/apply-plan.json'))->toBeTrue();

        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($report['schema'])->toBe('nutria.catalog-import-apply-plan-report/1')
            ->and($output)->not->toContain('selected_candidate_plans', 'raw_variants');
    } finally {
        removeCommandDirectoryM244b($directory);
    }
});

it('reports a fully persisted synthetic graph as no-op using only fresh selects', function () {
    $plannedDirectory = commandDirectoryM244b();
    $replayDirectory = commandDirectoryM244b();

    try {
        expect(Artisan::call('catalog:build-legacy-import-apply-plan', selectedCommandOptionsM244b($plannedDirectory)))->toBe(0);
        $planned = json_decode(file_get_contents($plannedDirectory.'/apply-plan.json'), true, flags: JSON_THROW_ON_ERROR);
        persistSyntheticPlanGraphM244b($planned);
        $queries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        expect(Artisan::call('catalog:build-legacy-import-apply-plan', selectedCommandOptionsM244b($replayDirectory)))->toBe(0);
        $replay = json_decode(file_get_contents($replayDirectory.'/apply-plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $report = json_decode(file_get_contents($replayDirectory.'/report.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($replay['source_plan']['action'])->toBe('unchanged')
            ->and($replay['selected_candidate_plans'][0]['graph_outcome'])->toBe('no_op')
            ->and($report['counts']['no_op_graphs'])->toBe(1)
            ->and($report['counts']['planned_references'])->toBe(0)
            ->and($report['sql_verification']['query_count'])->toBe(count($queries))
            ->and($queries)->not->toBe([]);

        foreach ($queries as $sql) {
            expect($sql)->toMatch('/^\s*select\b/i');
        }
    } finally {
        removeCommandDirectoryM244b($plannedDirectory);
        removeCommandDirectoryM244b($replayDirectory);
    }
});

it('rejects a partial synthetic graph and publishes no partial output', function () {
    $plannedDirectory = commandDirectoryM244b();
    $conflictDirectory = commandDirectoryM244b();

    try {
        expect(Artisan::call('catalog:build-legacy-import-apply-plan', selectedCommandOptionsM244b($plannedDirectory)))->toBe(0);
        $planned = json_decode(file_get_contents($plannedDirectory.'/apply-plan.json'), true, flags: JSON_THROW_ON_ERROR);
        persistSyntheticPlanGraphM244b($planned, false);

        expect(Artisan::call('catalog:build-legacy-import-apply-plan', selectedCommandOptionsM244b($conflictDirectory)))->toBe(1)
            ->and(Artisan::output())->toContain('partial')
            ->and(is_file($conflictDirectory.'/apply-plan.json'))->toBeFalse()
            ->and(is_file($conflictDirectory.'/report.json'))->toBeFalse();
    } finally {
        removeCommandDirectoryM244b($plannedDirectory);
        removeCommandDirectoryM244b($conflictDirectory);
    }
});

it('refuses the real unresolved template before database access with no plan output', function () {
    $directory = commandDirectoryM244b();
    $approvalBytes = canonicalDocumentBytesM244b(approvalDocumentM244b(RESOLUTION_TEMPLATE_SHA256_M244B));
    $approvalPath = $directory.'/approval.json';
    file_put_contents($approvalPath, $approvalBytes);
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    try {
        $exitCode = Artisan::call('catalog:build-legacy-import-apply-plan', [
            '--manifest' => manifestPathM244b(),
            '--expected-manifest-sha256' => MANIFEST_SHA256_M244B,
            '--resolution' => resolutionTemplatePathM244b(),
            '--expected-resolution-sha256' => RESOLUTION_TEMPLATE_SHA256_M244B,
            '--approval' => $approvalPath,
            '--expected-approval-sha256' => hash('sha256', $approvalBytes),
            '--apply-plan-output' => $directory.'/apply-plan.json',
            '--report' => $directory.'/report.json',
        ]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('no_candidates_selected')
            ->and($queries)->toBe([])
            ->and(is_file($directory.'/apply-plan.json'))->toBeFalse()
            ->and(is_file($directory.'/report.json'))->toBeFalse();
    } finally {
        removeCommandDirectoryM244b($directory);
    }
});

it('fails missing options invalid documents and destination collisions without partial output', function (string $case) {
    $directory = commandDirectoryM244b();

    try {
        $options = selectedCommandOptionsM244b($directory);

        if ($case === 'missing') {
            unset($options['--approval']);
        } elseif ($case === 'manifest') {
            $options['--expected-manifest-sha256'] = str_repeat('0', 64);
        } elseif ($case === 'resolution') {
            $options['--expected-resolution-sha256'] = str_repeat('0', 64);
        } elseif ($case === 'approval') {
            $options['--expected-approval-sha256'] = str_repeat('0', 64);
        } else {
            file_put_contents($directory.'/apply-plan.json', 'preserve');
        }

        expect(Artisan::call('catalog:build-legacy-import-apply-plan', $options))->toBe(1)
            ->and(is_file($directory.'/report.json'))->toBeFalse()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);

        if ($case === 'collision') {
            expect(file_get_contents($directory.'/apply-plan.json'))->toBe('preserve');
        } else {
            expect(is_file($directory.'/apply-plan.json'))->toBeFalse();
        }
    } finally {
        removeCommandDirectoryM244b($directory);
    }
})->with(['missing', 'manifest', 'resolution', 'approval', 'collision']);
