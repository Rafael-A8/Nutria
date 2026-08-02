<?php

use App\Nutrition\Application\Catalog\Import\CatalogImportApprovalAttestationLoader;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;

require_once dirname(__DIR__, 4).'/Support/CatalogImportM244bFixtures.php';

it('loads an exact canonical detached approval with explicit reviewer reason and UTC microseconds', function () {
    $resolutionBytes = canonicalDocumentBytesM244b(reviewedResolutionDocumentM244b());
    $resolutionPath = temporaryDocumentM244b($resolutionBytes);
    $approvalBytes = canonicalDocumentBytesM244b(approvalDocumentM244b(hash('sha256', $resolutionBytes)));
    $approvalPath = temporaryDocumentM244b($approvalBytes);

    try {
        $resolution = reviewedResolutionLoaderM244b()->load(
            $resolutionPath,
            hash('sha256', $resolutionBytes),
            resolutionTemplatePathM244b(),
            approvedManifestM244b(),
        );
        $approval = (new CatalogImportApprovalAttestationLoader)->load(
            $approvalPath,
            hash('sha256', $approvalBytes),
            approvedManifestM244b(),
            $resolution,
        );

        expect($approval->canonicalBytes)->toBe($approvalBytes)
            ->and($approval->document['approved_at'])->toBe('2026-08-02T12:34:56.123456Z');
    } finally {
        unlink($resolutionPath);
        unlink($approvalPath);
    }
});

it('rejects unsupported schemas bindings blank attestations timestamps and checksums', function (string $case) {
    $resolutionBytes = canonicalDocumentBytesM244b(reviewedResolutionDocumentM244b());
    $resolutionPath = temporaryDocumentM244b($resolutionBytes);
    $resolution = reviewedResolutionLoaderM244b()->load(
        $resolutionPath,
        hash('sha256', $resolutionBytes),
        resolutionTemplatePathM244b(),
        approvedManifestM244b(),
    );
    $overrides = match ($case) {
        'schema' => ['schema' => 'unsupported'],
        'resolution_binding' => ['reviewed_resolution_sha256' => str_repeat('1', 64)],
        'manifest_binding' => ['candidate_manifest_sha256' => str_repeat('2', 64)],
        'reviewer' => ['reviewer_reference' => ''],
        'reason' => ['approval_reason' => ' '],
        'timestamp' => ['approved_at' => '2026-08-02T12:34:56Z'],
        default => [],
    };
    $bytes = canonicalDocumentBytesM244b(approvalDocumentM244b(hash('sha256', $resolutionBytes), $overrides));
    $path = temporaryDocumentM244b($bytes);

    try {
        expect(fn () => (new CatalogImportApprovalAttestationLoader)->load(
            $path,
            $case === 'checksum' ? str_repeat('0', 64) : hash('sha256', $bytes),
            approvedManifestM244b(),
            $resolution,
        ))->toThrow(LegacyNutritionApplyPlanException::class);
    } finally {
        unlink($resolutionPath);
        unlink($path);
    }
})->with(['schema', 'resolution_binding', 'manifest_binding', 'reviewer', 'reason', 'timestamp', 'checksum']);
