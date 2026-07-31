<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

const APPROVED_MANIFEST_SHA256_COMMAND_M244A = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';

function commandOutputDirectoryM244a(): string
{
    $directory = storage_path('framework/testing/m244a-'.bin2hex(random_bytes(8)));
    mkdir($directory, 0777, true);

    return $directory;
}

function removeCommandOutputDirectoryM244a(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/** @return array<string, string> */
function successfulCommandOptionsM244a(string $directory, string $preflightReport = ''): array
{
    return [
        '--manifest' => storage_path('app/m243-run-1-manifest.json'),
        '--expected-manifest-sha256' => APPROVED_MANIFEST_SHA256_COMMAND_M244A,
        '--resolution-output' => $directory.'/resolution.json',
        '--preflight-report' => $preflightReport === '' ? $directory.'/preflight.json' : $preflightReport,
    ];
}

it('writes the deterministic unresolved resolution template and separate read-only preflight report', function () {
    $directory = commandOutputDirectoryM244a();

    try {
        $exitCode = Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory),
        );
        $resolutionBytes = file_get_contents($directory.'/resolution.json');
        $resolution = json_decode($resolutionBytes, true, flags: JSON_THROW_ON_ERROR);
        $preflight = json_decode(
            file_get_contents($directory.'/preflight.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($exitCode)->toBe(0)
            ->and(hash('sha256', $resolutionBytes))->toBe($preflight['resolution_template_sha256'])
            ->and($resolution['review_entries'])->toHaveCount(106)
            ->and($resolution['summary'])->toBe([
                'approved_apply_plan_records' => 0,
                'resolved_candidate_identities' => 0,
                'review_entries' => 106,
                'selected_candidates' => 0,
                'unresolved_candidate_identities' => 106,
            ])
            ->and($preflight['read_only'])->toBeTrue()
            ->and($preflight['sql_verification']['write_statements'])->toBe(0)
            ->and($preflight['sql_verification']['ddl_statements'])->toBe(0)
            ->and(Artisan::output())->not->toContain($resolutionBytes);
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
});

it('produces byte-identical templates and checksums across repeated runs', function () {
    $firstDirectory = commandOutputDirectoryM244a();
    $secondDirectory = commandOutputDirectoryM244a();

    try {
        expect(Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($firstDirectory),
        ))->toBe(0)
            ->and(Artisan::call(
                'catalog:prepare-legacy-import-review',
                successfulCommandOptionsM244a($secondDirectory),
            ))->toBe(0);

        $firstResolution = file_get_contents($firstDirectory.'/resolution.json');
        $secondResolution = file_get_contents($secondDirectory.'/resolution.json');
        $firstPreflight = file_get_contents($firstDirectory.'/preflight.json');
        $secondPreflight = file_get_contents($secondDirectory.'/preflight.json');

        expect($secondResolution)->toBe($firstResolution)
            ->and(hash('sha256', $secondResolution))->toBe(hash('sha256', $firstResolution))
            ->and($secondPreflight)->toBe($firstPreflight);
    } finally {
        removeCommandOutputDirectoryM244a($firstDirectory);
        removeCommandOutputDirectoryM244a($secondDirectory);
    }
});

it('writes only the operational preflight report to stdout', function () {
    $directory = commandOutputDirectoryM244a();

    try {
        $exitCode = Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory, '-'),
        );
        $output = Artisan::output();
        $preflight = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(is_file($directory.'/resolution.json'))->toBeTrue()
            ->and($preflight['schema'])->toBe('nutria.catalog-import-preflight/1')
            ->and($preflight['read_only'])->toBeTrue()
            ->and($output)->not->toContain('"review_entries"', '"raw_aliases"');
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
});

it('fails for every missing required option without creating output', function (string $missingOption) {
    $directory = commandOutputDirectoryM244a();

    try {
        $options = successfulCommandOptionsM244a($directory);
        unset($options[$missingOption]);

        expect(Artisan::call('catalog:prepare-legacy-import-review', $options))->toBe(1)
            ->and(is_file($directory.'/resolution.json'))->toBeFalse()
            ->and(is_file($directory.'/preflight.json'))->toBeFalse();
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
})->with([
    '--manifest',
    '--expected-manifest-sha256',
    '--resolution-output',
    '--preflight-report',
]);

it('rejects checksum failure before every database query and leaves no output', function () {
    $directory = commandOutputDirectoryM244a();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    try {
        $options = successfulCommandOptionsM244a($directory);
        $options['--expected-manifest-sha256'] = str_repeat('0', 64);

        expect(Artisan::call('catalog:prepare-legacy-import-review', $options))->toBe(1)
            ->and($queries)->toBe([])
            ->and(is_file($directory.'/resolution.json'))->toBeFalse()
            ->and(is_file($directory.'/preflight.json'))->toBeFalse()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
});

it('preserves existing destinations and removes temporary files', function (string $destination) {
    $directory = commandOutputDirectoryM244a();

    try {
        $path = $directory."/{$destination}.json";
        file_put_contents($path, 'preserve me');

        expect(Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory),
        ))->toBe(1)
            ->and(file_get_contents($path))->toBe('preserve me')
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
})->with(['resolution', 'preflight']);

it('leaves no partial output when catalog preflight fails', function () {
    $directory = commandOutputDirectoryM244a();
    $originalDefaultConnection = config('database.default');
    config()->set('database.default', 'missing-m244a-connection');

    try {
        expect(Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory),
        ))->toBe(1)
            ->and(is_file($directory.'/resolution.json'))->toBeFalse()
            ->and(is_file($directory.'/preflight.json'))->toBeFalse()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        config()->set('database.default', $originalDefaultConnection);
        removeCommandOutputDirectoryM244a($directory);
    }
});

it('publishes both file outputs atomically without residual temporary files', function () {
    $directory = commandOutputDirectoryM244a();

    try {
        expect(Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory),
        ))->toBe(0)
            ->and(is_file($directory.'/resolution.json'))->toBeTrue()
            ->and(is_file($directory.'/preflight.json'))->toBeTrue()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
});

it('executes only select statements during a representative command run', function () {
    $directory = commandOutputDirectoryM244a();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    try {
        expect(Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory),
        ))->toBe(0)
            ->and($queries)->not->toBe([]);

        foreach ($queries as $query) {
            expect($query)->toMatch('/^\s*select\b/i')
                ->and($query)->not->toMatch(
                    '/\b(insert|update|delete|upsert|merge|truncate|alter|create|drop|lock)\b/i',
                );
        }
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
});

it('excludes timestamps actors absolute paths internal IDs and child persistence identities', function () {
    $directory = commandOutputDirectoryM244a();

    try {
        expect(Artisan::call(
            'catalog:prepare-legacy-import-review',
            successfulCommandOptionsM244a($directory),
        ))->toBe(0);
        $resolutionBytes = file_get_contents($directory.'/resolution.json');

        expect($resolutionBytes)->not->toContain(
            base_path(),
            'generated_at',
            'executed_at',
            'actor_id',
            'database_id',
            'internal_id',
            'alias_lineage_id',
            'reference_version_public_id',
        );
    } finally {
        removeCommandOutputDirectoryM244a($directory);
    }
});
