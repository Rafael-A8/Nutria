<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

const APPROVED_SOURCE_SHA256_M243 = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';

function commandOutputDirectoryM243(): string
{
    $directory = storage_path('framework/testing/m243-'.bin2hex(random_bytes(8)));
    mkdir($directory, 0777, true);

    return $directory;
}

function removeCommandOutputDirectoryM243(string $path): void
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
function successfulCommandOptionsM243(string $directory, string $reportPath = ''): array
{
    return [
        '--source' => 'config/nutrition.php',
        '--expected-source-sha256' => APPROVED_SOURCE_SHA256_M243,
        '--manifest-output' => $directory.'/manifest.json',
        '--report' => $reportPath === '' ? $directory.'/report.json' : $reportPath,
    ];
}

it('generates the canonical manifest and separate summary report successfully', function () {
    $directory = commandOutputDirectoryM243();

    try {
        $exitCode = Artisan::call('catalog:plan-legacy-import', successfulCommandOptionsM243($directory));
        $manifestBytes = file_get_contents($directory.'/manifest.json');
        $report = json_decode(
            file_get_contents($directory.'/report.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($exitCode)->toBe(0)
            ->and($manifestBytes)->toBeString()
            ->and(hash('sha256', $manifestBytes))->toBe($report['manifest_checksum']['digest'])
            ->and($report['candidate_counts']['total'])->toBe(106)
            ->and($report['output_status'])->toBe('written')
            ->and(Artisan::output())->not->toContain($manifestBytes);
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});

it('writes only the summary report to stdout when requested', function () {
    $directory = commandOutputDirectoryM243();

    try {
        $exitCode = Artisan::call(
            'catalog:plan-legacy-import',
            successfulCommandOptionsM243($directory, '-'),
        );
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(is_file($directory.'/manifest.json'))->toBeTrue()
            ->and($report['source_path'])->toBe('config/nutrition.php')
            ->and($report['manifest_checksum']['digest'])
            ->toBe(hash_file('sha256', $directory.'/manifest.json'))
            ->and($output)->not->toContain('"records"', '"legacy_payload_fields"');
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});

it('fails for every missing required option without creating output', function (string $missingOption) {
    $directory = commandOutputDirectoryM243();

    try {
        $options = successfulCommandOptionsM243($directory);
        unset($options[$missingOption]);

        expect(Artisan::call('catalog:plan-legacy-import', $options))->toBe(1)
            ->and(is_file($directory.'/manifest.json'))->toBeFalse()
            ->and(is_file($directory.'/report.json'))->toBeFalse();
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
})->with([
    '--source',
    '--expected-source-sha256',
    '--manifest-output',
    '--report',
]);

it('rejects malformed checksum options without creating output', function (string $checksum) {
    $directory = commandOutputDirectoryM243();

    try {
        $options = successfulCommandOptionsM243($directory);
        $options['--expected-source-sha256'] = $checksum;

        expect(Artisan::call('catalog:plan-legacy-import', $options))->toBe(1)
            ->and(is_file($directory.'/manifest.json'))->toBeFalse()
            ->and(is_file($directory.'/report.json'))->toBeFalse();
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
})->with([
    'short digest' => str_repeat('a', 63),
    'uppercase digest' => str_repeat('A', 64),
    'not hexadecimal' => str_repeat('z', 64),
]);

it('leaves no output after checksum mismatch', function () {
    $directory = commandOutputDirectoryM243();

    try {
        $options = successfulCommandOptionsM243($directory);
        $options['--expected-source-sha256'] = str_repeat('0', 64);

        expect(Artisan::call('catalog:plan-legacy-import', $options))->toBe(1)
            ->and(is_file($directory.'/manifest.json'))->toBeFalse()
            ->and(is_file($directory.'/report.json'))->toBeFalse()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});

it('leaves no partial output after source extraction rejection', function () {
    $directory = commandOutputDirectoryM243();

    try {
        $options = successfulCommandOptionsM243($directory);
        $options['--source'] = '../config/nutrition.php';

        expect(Artisan::call('catalog:plan-legacy-import', $options))->toBe(1)
            ->and(is_file($directory.'/manifest.json'))->toBeFalse()
            ->and(is_file($directory.'/report.json'))->toBeFalse()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});

it('does not overwrite an existing manifest or report destination', function (string $existingDestination) {
    $directory = commandOutputDirectoryM243();

    try {
        $path = $directory."/{$existingDestination}.json";
        file_put_contents($path, 'preserve me');
        $options = successfulCommandOptionsM243($directory);

        expect(Artisan::call('catalog:plan-legacy-import', $options))->toBe(1)
            ->and(file_get_contents($path))->toBe('preserve me')
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);

        if ($existingDestination === 'manifest') {
            expect(is_file($directory.'/report.json'))->toBeFalse();
        }
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
})->with([
    'manifest',
    'report',
]);

it('publishes both outputs atomically and removes every temporary file', function () {
    $directory = commandOutputDirectoryM243();

    try {
        expect(Artisan::call(
            'catalog:plan-legacy-import',
            successfulCommandOptionsM243($directory),
        ))->toBe(0)
            ->and(is_file($directory.'/manifest.json'))->toBeTrue()
            ->and(is_file($directory.'/report.json'))->toBeTrue()
            ->and(glob($directory.'/.nutria-catalog-import-*') ?: [])->toBe([]);
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});

it('ignores cached nutrition configuration as a source authority', function () {
    $directory = commandOutputDirectoryM243();
    config()->set('nutrition', [
        'estimation' => [
            'references' => [
                'cached fake' => ['aliases' => ['cached fake']],
            ],
        ],
    ]);

    try {
        expect(Artisan::call(
            'catalog:plan-legacy-import',
            successfulCommandOptionsM243($directory),
        ))->toBe(0);

        $manifest = json_decode(
            file_get_contents($directory.'/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($manifest['records'])->toHaveCount(106)
            ->and(array_column($manifest['records'], 'source_record_key'))
            ->not->toContain('cached fake');
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});

it('performs no database query while planning and writing outputs', function () {
    $directory = commandOutputDirectoryM243();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    try {
        expect(Artisan::call(
            'catalog:plan-legacy-import',
            successfulCommandOptionsM243($directory),
        ))->toBe(0)
            ->and($queries)->toBe([]);
    } finally {
        removeCommandOutputDirectoryM243($directory);
    }
});
