<?php

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;

require_once dirname(__DIR__, 4).'/Support/CatalogImportM244bFixtures.php';

it('accepts partial editorial resolution and selects only the fully eligible candidate', function () {
    $bytes = canonicalDocumentBytesM244b(reviewedResolutionDocumentM244b());
    $path = temporaryDocumentM244b($bytes);

    try {
        $loaded = reviewedResolutionLoaderM244b()->load(
            $path,
            hash('sha256', $bytes),
            resolutionTemplatePathM244b(),
            approvedManifestM244b(),
        );

        expect($loaded->selectedEntries)->toHaveCount(1)
            ->and($loaded->selectedEntries[0]['source_record_key'])->toBe('abacate')
            ->and($loaded->eligibleEntryCount)->toBe(1)
            ->and($loaded->canonicalBytes)->toBe($bytes);
    } finally {
        unlink($path);
    }
});

it('rejects fact issue alias provenance candidate and checksum mutations fail closed', function (string $mutation) {
    $document = reviewedResolutionDocumentM244b();

    if ($mutation === 'fact') {
        $document['review_entries'][0]['canonical_name'] = 'mutated';
    } elseif ($mutation === 'issue') {
        $document['review_entries'][0]['issue_codes'] = [];
    } elseif ($mutation === 'alias') {
        $document['review_entries'][0]['alias_decisions'][0]['normalized_alias'] = 'mutated';
    } elseif ($mutation === 'provenance') {
        $document['review_entries'][0]['provenance']['source_ordinal'] = 999;
    } elseif ($mutation === 'candidate') {
        array_pop($document['review_entries']);
    }

    $bytes = canonicalDocumentBytesM244b($document);
    $path = temporaryDocumentM244b($bytes);

    try {
        expect(fn () => reviewedResolutionLoaderM244b()->load(
            $path,
            $mutation === 'checksum' ? str_repeat('0', 64) : hash('sha256', $bytes),
            resolutionTemplatePathM244b(),
            approvedManifestM244b(),
        ))->toThrow(LegacyNutritionApplyPlanException::class);
    } finally {
        unlink($path);
    }
})->with(['fact', 'issue', 'alias', 'provenance', 'candidate', 'checksum']);

it('rejects noncanonical bytes and selected unresolved decisions', function (string $case) {
    $document = $case === 'ineligible'
        ? reviewedResolutionDocumentM244b('abacate', ['stable_key' => null])
        : reviewedResolutionDocumentM244b();
    $bytes = $case === 'noncanonical'
        ? json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        : canonicalDocumentBytesM244b($document);
    $path = temporaryDocumentM244b($bytes);

    try {
        expect(fn () => reviewedResolutionLoaderM244b()->load(
            $path,
            hash('sha256', $bytes),
            resolutionTemplatePathM244b(),
            approvedManifestM244b(),
        ))->toThrow(LegacyNutritionApplyPlanException::class);
    } finally {
        unlink($path);
    }
})->with(['noncanonical', 'ineligible']);
