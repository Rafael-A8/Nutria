<?php

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportPlanningException;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionSourceLoader;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;

function repositoryRootM243(): string
{
    return dirname(__DIR__, 5);
}

function temporaryLegacySourceRootM243(?string $sourceContents = null): string
{
    $temporaryRoot = sys_get_temp_dir().'/nutria-m243-'.bin2hex(random_bytes(8));
    mkdir($temporaryRoot.'/config', 0777, true);

    if ($sourceContents !== null) {
        file_put_contents($temporaryRoot.'/config/nutrition.php', $sourceContents);
    }

    return $temporaryRoot;
}

function removeTemporaryTreeM243(string $path): void
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

it('observes the exact approved legacy source bytes and checksum', function () {
    $sourcePath = repositoryRootM243().'/config/nutrition.php';
    $rawBytes = file_get_contents($sourcePath);

    expect($rawBytes)->toBeString()
        ->and(strlen($rawBytes))->toBe(25676)
        ->and(hash('sha256', $rawBytes))
        ->toBe('0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21');
});

it('loads exact raw bytes and the isolated returned array', function () {
    $expectedChecksum = new SourceArtifactChecksum(
        'sha256',
        '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21',
    );
    $loadedSource = (new LegacyNutritionSourceLoader)->load(
        repositoryRootM243(),
        'config/nutrition.php',
        $expectedChecksum,
    );

    expect($loadedSource->artifactPath)->toBe('config/nutrition.php')
        ->and($loadedSource->byteSize())->toBe(25676)
        ->and($loadedSource->checksum)->toBe($expectedChecksum)
        ->and($loadedSource->payload)->toHaveKeys(['estimation'])
        ->and($loadedSource->payload['estimation']['references'])->toHaveCount(106);
});

it('rejects checksum mismatch before evaluating source code', function () {
    $temporaryRoot = temporaryLegacySourceRootM243(
        "<?php\nthrow new RuntimeException('must not execute');\n",
    );

    try {
        expect(fn () => (new LegacyNutritionSourceLoader)->load(
            $temporaryRoot,
            'config/nutrition.php',
            SourceArtifactChecksum::fromRawBytes('different bytes'),
        ))->toThrow(
            LegacyNutritionImportPlanningException::class,
            'checksum does not match',
        );
    } finally {
        removeTemporaryTreeM243($temporaryRoot);
    }
});

it('rejects unreadable or unavailable approved sources', function () {
    $temporaryRoot = temporaryLegacySourceRootM243();

    try {
        expect(fn () => (new LegacyNutritionSourceLoader)->load(
            $temporaryRoot,
            'config/nutrition.php',
            SourceArtifactChecksum::fromRawBytes('irrelevant'),
        ))->toThrow(
            LegacyNutritionImportPlanningException::class,
            'source is unreadable',
        );
    } finally {
        removeTemporaryTreeM243($temporaryRoot);
    }
});

it('rejects every unsupported source path', function (string $sourcePath) {
    expect(fn () => (new LegacyNutritionSourceLoader)->load(
        repositoryRootM243(),
        $sourcePath,
        SourceArtifactChecksum::fromRawBytes('irrelevant'),
    ))->toThrow(
        LegacyNutritionImportPlanningException::class,
        'source path is unsupported',
    );
})->with([
    'traversal' => '../config/nutrition.php',
    'absolute path' => '/config/nutrition.php',
    'remote URL' => 'https://example.test/nutrition.php',
    'nearby config' => 'config/other.php',
    'backslash path' => 'config\\nutrition.php',
]);

it('rejects a legacy source that does not return an object array', function (string $returnExpression) {
    $sourceContents = "<?php\n\nreturn {$returnExpression};\n";
    $temporaryRoot = temporaryLegacySourceRootM243($sourceContents);

    try {
        expect(fn () => (new LegacyNutritionSourceLoader)->load(
            $temporaryRoot,
            'config/nutrition.php',
            SourceArtifactChecksum::fromRawBytes($sourceContents),
        ))->toThrow(
            LegacyNutritionImportPlanningException::class,
            'must return a PHP object array',
        );
    } finally {
        removeTemporaryTreeM243($temporaryRoot);
    }
})->with([
    'null' => 'null',
    'scalar' => "'nutrition'",
    'list' => '[1, 2, 3]',
]);

it('rejects any output emitted while loading the source', function () {
    $sourceContents = "<?php\n\necho 'unexpected';\n\nreturn ['estimation' => []];\n";
    $temporaryRoot = temporaryLegacySourceRootM243($sourceContents);

    try {
        expect(fn () => (new LegacyNutritionSourceLoader)->load(
            $temporaryRoot,
            'config/nutrition.php',
            SourceArtifactChecksum::fromRawBytes($sourceContents),
        ))->toThrow(
            LegacyNutritionImportPlanningException::class,
            'emitted output',
        );
    } finally {
        removeTemporaryTreeM243($temporaryRoot);
    }
});

it('does not access Laravel cached nutrition configuration', function () {
    $source = file_get_contents(
        repositoryRootM243().'/app/Nutrition/Application/Catalog/Import/LegacyNutritionSourceLoader.php',
    );

    expect($source)->not->toMatch('/\bconfig\s*\(/')
        ->and($source)->not->toContain('Config::', 'eval(');
});
