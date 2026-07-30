<?php

use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportCandidateClassification;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportGraphOutcome;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIdentityResolutionStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReferenceTarget;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportAliasIdentity;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportCandidateDecision;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportChecksums;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportIdentityResolution;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportIssueSet;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportPreparationDecision;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportSelection;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ConceptualStableKey;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogSourceLinkSemantics;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;

function resolvedCatalogImportIdentityM242(
    CatalogImportReferenceTarget $target = CatalogImportReferenceTarget::NewReference,
    ?string $existingReferencePublicId = null,
): CatalogImportIdentityResolution {
    return CatalogImportIdentityResolution::resolved(
        referenceTarget: $target,
        stableKey: new ConceptualStableKey('dairy-condensed-milk'),
        referenceVisibility: CatalogVisibility::Global,
        ownerUserId: null,
        isGeneric: false,
        versionLocale: 'pt-BR',
        classification: 'dairy_product',
        preparation: CatalogImportPreparationDecision::neutral(),
        aliases: [
            new CatalogImportAliasIdentity('leite condensado', 'pt-BR', AliasKind::Common),
        ],
        sourceLink: new LegacyCatalogSourceLinkSemantics(
            FoodReferenceVersionSourceRole::Primary,
            FoodSourceAuthorityStatus::Untrusted,
        ),
        existingReferencePublicId: $existingReferencePublicId,
    );
}

it('defines the frozen manifest and typed import vocabularies', function () {
    expect(CatalogImportManifestSchema::current()->value)->toBe('nutria.catalog-import-manifest/1')
        ->and(array_column(CatalogImportCandidateClassification::cases(), 'value'))->toBe([
            'valid_candidate',
            'suspicious_candidate',
            'invalid_candidate',
        ])->and(array_column(CatalogImportIdentityResolutionStatus::cases(), 'value'))->toBe([
            'resolved',
            'unresolved',
            'conflict',
        ])->and(array_column(CatalogImportGraphOutcome::cases(), 'value'))->toBe([
            'planned',
            'unchanged',
            'no_op',
            'conflict',
        ]);
});

it('fails closed for malformed or unsupported manifest schemas', function (string $schema) {
    expect(fn () => new CatalogImportManifestSchema($schema))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unknown version' => 'nutria.catalog-import-manifest/2',
    'missing version' => 'nutria.catalog-import-manifest',
    'blank' => '',
    'untrimmed' => ' nutria.catalog-import-manifest/1',
]);

it('distinguishes unchanged graph comparison from write-free apply no-op', function () {
    expect(CatalogImportGraphOutcome::Unchanged->representsExactPersistedMatch())->toBeTrue()
        ->and(CatalogImportGraphOutcome::Unchanged->representsWriteFreeApplyResult())->toBeFalse()
        ->and(CatalogImportGraphOutcome::NoOp->representsExactPersistedMatch())->toBeFalse()
        ->and(CatalogImportGraphOutcome::NoOp->representsWriteFreeApplyResult())->toBeTrue();
});

it('keeps classification independent from explicit selection', function (
    CatalogImportCandidateClassification $classification,
) {
    $decision = new CatalogImportCandidateDecision(
        $classification,
        resolvedCatalogImportIdentityM242(),
        new CatalogImportSelection(false),
        CatalogImportIssueSet::none(),
    );

    expect($decision->classification)->toBe($classification)
        ->and($decision->identityResolution->isComplete())->toBeTrue()
        ->and($decision->selection->selectedForApply)->toBeFalse();
})->with([
    CatalogImportCandidateClassification::ValidCandidate,
    CatalogImportCandidateClassification::SuspiciousCandidate,
    CatalogImportCandidateClassification::InvalidCandidate,
]);

it('requires application selection as an explicit constructor argument', function () {
    $parameters = (new ReflectionClass(CatalogImportCandidateDecision::class))
        ->getConstructor()
        ?->getParameters() ?? [];
    $selection = array_values(array_filter(
        $parameters,
        fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'selection',
    ))[0] ?? null;

    expect($selection)->toBeInstanceOf(ReflectionParameter::class)
        ->and($selection?->isDefaultValueAvailable())->toBeFalse()
        ->and($selection?->getType()?->getName())->toBe(CatalogImportSelection::class);
});

it('allows a suspicious candidate only after complete identity resolution', function () {
    $decision = new CatalogImportCandidateDecision(
        CatalogImportCandidateClassification::SuspiciousCandidate,
        resolvedCatalogImportIdentityM242(),
        new CatalogImportSelection(true),
        new CatalogImportIssueSet([
            CatalogImportIssueCode::SourceUntrusted,
            CatalogImportIssueCode::DefaultCaloriesAssumption,
        ]),
    );

    expect($decision->selection->selectedForApply)->toBeTrue()
        ->and($decision->identityResolution->status)->toBe(CatalogImportIdentityResolutionStatus::Resolved);
});

it('keeps valid candidates with unresolved identity manifest-only', function () {
    $decision = new CatalogImportCandidateDecision(
        CatalogImportCandidateClassification::ValidCandidate,
        CatalogImportIdentityResolution::unresolved(
            new CatalogImportIssueSet([CatalogImportIssueCode::ConceptualIdentityUnresolved]),
        ),
        new CatalogImportSelection(false),
        CatalogImportIssueSet::none(),
    );

    expect($decision->selection->selectedForApply)->toBeFalse()
        ->and($decision->identityResolution->status)->toBe(CatalogImportIdentityResolutionStatus::Unresolved);
});

it('rejects selected candidates without eligible classification and identity', function (
    CatalogImportCandidateClassification $classification,
    CatalogImportIdentityResolution $identityResolution,
) {
    expect(fn () => new CatalogImportCandidateDecision(
        $classification,
        $identityResolution,
        new CatalogImportSelection(true),
        CatalogImportIssueSet::none(),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'unresolved valid candidate' => [
        CatalogImportCandidateClassification::ValidCandidate,
        CatalogImportIdentityResolution::unresolved(
            new CatalogImportIssueSet([CatalogImportIssueCode::GenericityUnresolved]),
        ),
    ],
    'conflicting valid candidate' => [
        CatalogImportCandidateClassification::ValidCandidate,
        CatalogImportIdentityResolution::conflict(
            new CatalogImportIssueSet([CatalogImportIssueCode::ImmutableFieldConflict]),
        ),
    ],
    'invalid resolved candidate' => [
        CatalogImportCandidateClassification::InvalidCandidate,
        resolvedCatalogImportIdentityM242(),
    ],
]);

it('requires typed issues for unresolved and conflicting identities', function () {
    expect(fn () => CatalogImportIdentityResolution::unresolved(
        new CatalogImportIssueSet([CatalogImportIssueCode::SourceDeclarationMissing]),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CatalogImportIdentityResolution::conflict(
            new CatalogImportIssueSet([CatalogImportIssueCode::ConceptualIdentityUnresolved]),
        ))->toThrow(InvalidArgumentException::class);
});

it('requires all immutable identity decisions explicitly', function () {
    expect(fn () => CatalogImportIdentityResolution::resolved(
        referenceTarget: CatalogImportReferenceTarget::NewReference,
        stableKey: new ConceptualStableKey('dairy-condensed-milk'),
        referenceVisibility: CatalogVisibility::Global,
        ownerUserId: null,
        isGeneric: false,
        versionLocale: 'pt-BR',
        classification: 'dairy_product',
        preparation: CatalogImportPreparationDecision::neutral(),
        aliases: [],
        sourceLink: new LegacyCatalogSourceLinkSemantics(
            FoodReferenceVersionSourceRole::Primary,
            FoodSourceAuthorityStatus::Untrusted,
        ),
        existingReferencePublicId: null,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => resolvedCatalogImportIdentityM242(
            CatalogImportReferenceTarget::ExistingReference,
            null,
        ))->toThrow(InvalidArgumentException::class);
});

it('requires a source-neutral conceptual stable key', function () {
    expect(new ConceptualStableKey('dairy-condensed-milk')->value)->toBe('dairy-condensed-milk')
        ->and(fn () => new ConceptualStableKey('legacy_config_nutrition_v1:leite-condensado'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConceptualStableKey('config/nutrition.php:leite-condensado'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConceptualStableKey('legacy:leite-condensado'))
        ->toThrow(InvalidArgumentException::class);
});

it('orders and deduplicates typed issue codes deterministically', function () {
    $issues = new CatalogImportIssueSet([
        CatalogImportIssueCode::AliasKindUnresolved,
        CatalogImportIssueCode::StructuralShapeInvalid,
        CatalogImportIssueCode::SourceDeclarationMissing,
        CatalogImportIssueCode::AliasKindUnresolved,
        CatalogImportIssueCode::PreparationUnresolved,
    ]);

    expect($issues->values())->toBe([
        'structural_shape_invalid',
        'source_declaration_missing',
        'preparation_unresolved',
        'alias_kind_unresolved',
    ]);
});

it('describes the legacy artifact without inventing a FoodSource code', function () {
    $descriptor = new LegacyCatalogArtifactDescriptor(
        title: 'Nutri legacy nutrition configuration',
        checksum: SourceArtifactChecksum::fromRawBytes('source bytes'),
        artifactPath: 'config/nutrition.php',
        sourceFormat: 'php_return_array',
        byteSize: 12,
        repositoryCommit: str_repeat('a', 40),
    );

    expect(LegacyCatalogArtifactDescriptor::ARTIFACT_ID)->toBe('legacy_config_nutrition_v1')
        ->and((new ReflectionClass($descriptor))->hasProperty('code'))->toBeFalse()
        ->and($descriptor->visibility)->toBe(CatalogVisibility::Global)
        ->and($descriptor->ownerUserId)->toBeNull()
        ->and($descriptor->kind)->toBe(FoodSourceKind::LegacyConfig)
        ->and($descriptor->authority)->toBe(FoodSourceAuthorityStatus::Untrusted)
        ->and($descriptor->metadata())->toBe([
            'artifact_id' => 'legacy_config_nutrition_v1',
            'artifact_path' => 'config/nutrition.php',
            'byte_size' => 12,
            'repository_commit' => str_repeat('a', 40),
            'source_format' => 'php_return_array',
        ]);
});

it('receives mutable source evidence and rejects machine-specific paths', function () {
    $constructor = (new ReflectionClass(LegacyCatalogArtifactDescriptor::class))->getConstructor();
    $parameters = $constructor?->getParameters() ?? [];

    expect(array_filter(
        $parameters,
        fn (ReflectionParameter $parameter): bool => $parameter->isDefaultValueAvailable(),
    ))->toBe([])
        ->and(fn () => new LegacyCatalogArtifactDescriptor(
            'Legacy source',
            SourceArtifactChecksum::fromRawBytes('source bytes'),
            '/home/user/config/nutrition.php',
            'php_return_array',
            12,
            str_repeat('a', 40),
        ))->toThrow(InvalidArgumentException::class);
});

it('freezes primary evidence as untrusted without granting activation eligibility', function () {
    $semantics = new LegacyCatalogSourceLinkSemantics(
        FoodReferenceVersionSourceRole::Primary,
        FoodSourceAuthorityStatus::Untrusted,
    );

    expect($semantics->isPrincipalEvidence())->toBeTrue()
        ->and($semantics->isTrusted())->toBeFalse()
        ->and($semantics->mayParticipateInDraftReview())->toBeTrue()
        ->and($semantics->isEligibleForActivation())->toBeFalse()
        ->and(fn () => new LegacyCatalogSourceLinkSemantics(
            FoodReferenceVersionSourceRole::Supporting,
            FoodSourceAuthorityStatus::Eligible,
        ))->toThrow(InvalidArgumentException::class);
});

it('keeps source and manifest checksums distinct by construction', function () {
    $checksums = new CatalogImportChecksums(
        SourceArtifactChecksum::fromRawBytes('source bytes'),
        CanonicalManifestChecksum::fromCanonicalBytes('manifest bytes'),
    );
    $constructor = (new ReflectionClass(CatalogImportChecksums::class))->getConstructor();

    expect($checksums->source)->toBeInstanceOf(SourceArtifactChecksum::class)
        ->and($checksums->manifest)->toBeInstanceOf(CanonicalManifestChecksum::class)
        ->and($constructor?->getParameters()[0]->getType()?->getName())->toBe(SourceArtifactChecksum::class)
        ->and($constructor?->getParameters()[1]->getType()?->getName())->toBe(CanonicalManifestChecksum::class);
});

it('rejects malformed checksum algorithms and digests', function (
    string $algorithm,
    string $digest,
) {
    expect(fn () => new SourceArtifactChecksum($algorithm, $digest))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CanonicalManifestChecksum($algorithm, $digest))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unsupported algorithm' => ['sha1', str_repeat('a', 64)],
    'uppercase digest' => ['sha256', str_repeat('A', 64)],
    'short digest' => ['sha256', str_repeat('a', 63)],
    'long digest' => ['sha256', str_repeat('a', 65)],
]);

it('rejects exact source and manifest checksum mismatches', function () {
    $source = SourceArtifactChecksum::fromRawBytes('source bytes');
    $manifest = CanonicalManifestChecksum::fromCanonicalBytes('manifest bytes');

    expect(fn () => $source->assertMatchesRawBytes('changed source'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $manifest->assertMatchesCanonicalBytes('changed manifest'))
        ->toThrow(InvalidArgumentException::class);
});

it('uses an existing reference UUID as the effective child identity input', function () {
    $planned = '6e360841-51b8-5deb-a201-38debd0f03dc';
    $existing = 'aaaaaaaa-aaaa-5aaa-8aaa-aaaaaaaaaaaa';
    $newResolution = resolvedCatalogImportIdentityM242();
    $existingResolution = resolvedCatalogImportIdentityM242(
        CatalogImportReferenceTarget::ExistingReference,
        $existing,
    );
    $newEffectiveReference = $newResolution->effectiveReferencePublicId($planned);
    $existingEffectiveReference = $existingResolution->effectiveReferencePublicId($planned);

    expect($newEffectiveReference)->toBe($planned)
        ->and($existingEffectiveReference)->toBe($existing)
        ->and($existingResolution->stableKey?->value)->toBe('dairy-condensed-milk')
        ->not->toBe($planned)
        ->and(CatalogImportDeterministicIdentity::referenceVersionPublicId($newEffectiveReference, 1))
        ->not->toBe(CatalogImportDeterministicIdentity::referenceVersionPublicId($existingEffectiveReference, 1))
        ->and(CatalogImportDeterministicIdentity::aliasLineageId($newEffectiveReference, 'pt-BR', 'leite condensado'))
        ->not->toBe(CatalogImportDeterministicIdentity::aliasLineageId($existingEffectiveReference, 'pt-BR', 'leite condensado'));
});
