<?php

use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\ValueObjects\FoodReferenceId;
use App\Nutrition\Domain\Catalog\ValueObjects\FoodReferenceVersionId;
use App\Nutrition\Domain\Catalog\ValueObjects\FoodResolutionCandidate;
use App\Nutrition\Domain\Catalog\ValueObjects\NormalizedFoodText;
use App\Nutrition\Domain\Drafts\MealComponentDraft;
use App\Nutrition\Domain\Enums\ComponentRole;
use App\Nutrition\Domain\Enums\QuantitySource;
use App\Nutrition\Domain\Enums\QuantityType;
use App\Nutrition\Domain\Resolution\Enums\FoodMatchKind;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionReason;
use App\Nutrition\Domain\Resolution\Enums\FoodResolutionStatus;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolution;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolutionRequest;
use App\Nutrition\Domain\Resolution\ValueObjects\FoodResolutionTrace;
use App\Nutrition\Domain\ValueObjects\Quantity;

function foodResolutionCandidateForM22(
    string $identifier,
    FoodMatchKind $matchKind = FoodMatchKind::ExactCanonicalName,
    CatalogVisibility $visibility = CatalogVisibility::Global,
    int|string|null $catalogOwnerId = null,
    bool $isGeneric = false,
): FoodResolutionCandidate {
    return new FoodResolutionCandidate(
        referenceId: new FoodReferenceId("reference-{$identifier}"),
        versionId: new FoodReferenceVersionId("version-{$identifier}"),
        canonicalName: "Food {$identifier}",
        matchedText: new NormalizedFoodText("Food {$identifier}", "food {$identifier}"),
        matchKind: $matchKind,
        visibility: $visibility,
        catalogOwnerId: $catalogOwnerId,
        isGeneric: $isGeneric,
        classification: 'food',
        matchedAliasIdentifier: $matchKind === FoodMatchKind::ExactAlias ? "alias-{$identifier}" : null,
        sourceIdentifier: 'curated-source',
        isReferenceActive: true,
        isVersionActive: true,
        isVersionReviewed: true,
    );
}

/**
 * @param  list<FoodResolutionCandidate>  $candidates
 */
function foodResolutionTraceForM22(
    FoodResolutionStatus $status,
    FoodResolutionReason $reason,
    array $candidates = [],
): FoodResolutionTrace {
    return new FoodResolutionTrace(
        policyVersion: 'm2.2-contract-v1',
        originalFoodText: 'food',
        normalizedFoodText: new NormalizedFoodText('food', 'food'),
        locale: 'pt-BR',
        requestedCatalogOwnerId: null,
        lookupKindsAttempted: [FoodMatchKind::ExactCanonicalName, FoodMatchKind::ExactAlias],
        candidateVersionIds: array_map(
            fn (FoodResolutionCandidate $candidate): FoodReferenceVersionId => $candidate->versionId,
            $candidates,
        ),
        filteringReasons: [],
        finalStatus: $status,
        finalReason: $reason,
    );
}

it('defines the approved catalog and resolution enum vocabulary', function () {
    expect(array_column(CatalogVisibility::cases(), 'value'))->toBe(['global', 'private'])
        ->and(array_column(FoodResolutionStatus::cases(), 'value'))->toBe([
            'resolved',
            'ambiguous',
            'clarification_required',
            'unresolved',
            'invalid_input',
        ])->and(array_column(FoodMatchKind::cases(), 'value'))->toBe([
            'exact_canonical_name',
            'exact_alias',
        ])->and(array_column(FoodResolutionReason::cases(), 'value'))->toBe([
            'blank_food_text',
            'no_exact_match',
            'alias_collision',
            'canonical_alias_collision',
            'generic_alias_requires_clarification',
            'explicit_generic_reference',
            'role_incompatible',
            'preparation_incompatible',
            'multiple_compatible_candidates',
            'inactive_reference',
            'no_active_reviewed_version',
            'private_scope_excluded',
            'unique_compatible_exact_match',
        ]);
});

it('retains the exact meal component draft and its quantity without mutation or copying', function () {
    $quantity = new Quantity(
        type: QuantityType::Vague,
        source: QuantitySource::UserReported,
        measureText: 'um pouco',
    );
    $component = new MealComponentDraft(
        originalText: 'um pouco de azeite',
        interpretedFoodText: 'azeite',
        role: ComponentRole::Unknown,
        quantity: $quantity,
        preparations: [],
    );
    $request = new FoodResolutionRequest(
        component: $component,
        locale: 'pt-BR',
        catalogOwnerId: 'owner-42',
        entryOriginalText: 'salada com um pouco de azeite',
    );

    expect($request->component)->toBe($component)
        ->and($request->component->quantity)->toBe($quantity)
        ->and($request->component->originalText)->toBe('um pouco de azeite')
        ->and($request->component->interpretedFoodText)->toBe('azeite')
        ->and($request->component->role)->toBe(ComponentRole::Unknown)
        ->and($request->component->preparations)->toBe([])
        ->and($request->locale)->toBe('pt-BR')
        ->and($request->catalogOwnerId)->toBe('owner-42')
        ->and($request->entryOriginalText)->toBe('salada com um pouco de azeite')
        ->and($quantity->gramAmount)->toBeNull();
});

it('represents exact candidate identity, scope, eligibility, and provenance', function () {
    $candidate = foodResolutionCandidateForM22(
        identifier: 'private-rice',
        matchKind: FoodMatchKind::ExactAlias,
        visibility: CatalogVisibility::Private,
        catalogOwnerId: 42,
    );

    expect($candidate->referenceId->value)->toBe('reference-private-rice')
        ->and($candidate->versionId->value)->toBe('version-private-rice')
        ->and($candidate->matchKind)->toBe(FoodMatchKind::ExactAlias)
        ->and($candidate->visibility)->toBe(CatalogVisibility::Private)
        ->and($candidate->catalogOwnerId)->toBe(42)
        ->and($candidate->matchedAliasIdentifier)->toBe('alias-private-rice')
        ->and($candidate->sourceIdentifier)->toBe('curated-source')
        ->and($candidate->isReferenceActive)->toBeTrue()
        ->and($candidate->isVersionActive)->toBeTrue()
        ->and($candidate->isVersionReviewed)->toBeTrue();
});

it('enforces candidate owner isolation shape', function (CatalogVisibility $visibility, int|string|null $ownerId) {
    expect(fn () => foodResolutionCandidateForM22(
        identifier: 'invalid-scope',
        visibility: $visibility,
        catalogOwnerId: $ownerId,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'private without owner' => [CatalogVisibility::Private, null],
    'global with owner' => [CatalogVisibility::Global, 42],
    'private with blank owner' => [CatalogVisibility::Private, '   '],
    'private with invalid numeric owner' => [CatalogVisibility::Private, 0],
]);

it('resolves exactly one candidate without changing candidate order', function () {
    $candidate = foodResolutionCandidateForM22('rice');
    $reason = FoodResolutionReason::UniqueCompatibleExactMatch;
    $result = FoodResolution::resolved(
        $candidate,
        $reason,
        foodResolutionTraceForM22(FoodResolutionStatus::Resolved, $reason, [$candidate]),
    );

    expect($result->status)->toBe(FoodResolutionStatus::Resolved)
        ->and($result->candidates)->toBe([$candidate])
        ->and($result->selectedCandidate)->toBe($candidate);
});

it('preserves ambiguous candidate order and never selects one', function () {
    $second = foodResolutionCandidateForM22('second');
    $first = foodResolutionCandidateForM22('first', FoodMatchKind::ExactAlias);
    $candidates = [$second, $first];
    $reason = FoodResolutionReason::CanonicalAliasCollision;
    $result = FoodResolution::ambiguous(
        $candidates,
        $reason,
        foodResolutionTraceForM22(FoodResolutionStatus::Ambiguous, $reason, $candidates),
    );

    expect($result->candidates)->toBe([$second, $first])
        ->and($result->selectedCandidate)->toBeNull();
});

it('rejects an ambiguous result with fewer than two candidates', function () {
    $candidate = foodResolutionCandidateForM22('only');
    $reason = FoodResolutionReason::AliasCollision;

    expect(fn () => FoodResolution::ambiguous(
        [$candidate],
        $reason,
        foodResolutionTraceForM22(FoodResolutionStatus::Ambiguous, $reason, [$candidate]),
    ))->toThrow(InvalidArgumentException::class);
});

it('represents clarification with zero or more unselected candidates', function (array $candidates) {
    $reason = FoodResolutionReason::GenericAliasRequiresClarification;
    $result = FoodResolution::clarificationRequired(
        $candidates,
        $reason,
        foodResolutionTraceForM22(FoodResolutionStatus::ClarificationRequired, $reason, $candidates),
    );

    expect($result->selectedCandidate)->toBeNull()
        ->and($result->candidates)->toBe($candidates);
})->with([
    'no candidates' => [[]],
    'known generic candidate' => [[foodResolutionCandidateForM22('generic', isGeneric: true)]],
]);

it('represents unresolved and invalid input without candidates', function (
    FoodResolutionStatus $status,
    FoodResolutionReason $reason,
) {
    $trace = foodResolutionTraceForM22($status, $reason);
    $result = $status === FoodResolutionStatus::Unresolved
        ? FoodResolution::unresolved($reason, $trace)
        : FoodResolution::invalidInput($reason, $trace);

    expect($result->candidates)->toBe([])
        ->and($result->selectedCandidate)->toBeNull();
})->with([
    'unresolved' => [FoodResolutionStatus::Unresolved, FoodResolutionReason::NoExactMatch],
    'invalid input' => [FoodResolutionStatus::InvalidInput, FoodResolutionReason::BlankFoodText],
]);

it('rejects a trace outcome that differs from the result outcome', function () {
    $candidate = foodResolutionCandidateForM22('rice');

    expect(fn () => FoodResolution::resolved(
        $candidate,
        FoodResolutionReason::UniqueCompatibleExactMatch,
        foodResolutionTraceForM22(
            FoodResolutionStatus::Unresolved,
            FoodResolutionReason::NoExactMatch,
        ),
    ))->toThrow(InvalidArgumentException::class);
});

it('represents the required diagnostic reasons', function (FoodResolutionReason $reason) {
    expect($reason->value)->not->toBeEmpty();
})->with([
    FoodResolutionReason::GenericAliasRequiresClarification,
    FoodResolutionReason::ExplicitGenericReference,
    FoodResolutionReason::InactiveReference,
    FoodResolutionReason::NoActiveReviewedVersion,
    FoodResolutionReason::PrivateScopeExcluded,
    FoodResolutionReason::CanonicalAliasCollision,
]);
