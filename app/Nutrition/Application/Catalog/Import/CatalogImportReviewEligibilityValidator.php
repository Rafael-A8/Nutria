<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportAliasReviewStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportCandidateClassification;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportOwnerDecisionStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReviewPreparationStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReviewReferenceTarget;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ConceptualStableKey;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use InvalidArgumentException;

final class CatalogImportReviewEligibilityValidator
{
    private const ALLOWED_ALIAS_KINDS = ['common', 'generic', 'brand'];

    /**
     * @param  array<string, mixed>  $reviewEntry
     * @return array{eligible: bool, reasons: list<string>, selected_for_apply: bool}
     */
    public function evaluate(array $reviewEntry): array
    {
        $reasons = [];
        $classification = CatalogImportCandidateClassification::tryFrom(
            is_string($reviewEntry['candidate_classification'] ?? null)
                ? $reviewEntry['candidate_classification']
                : '',
        );

        if ($classification === null) {
            $reasons[] = 'candidate_classification_invalid';
        } elseif ($classification === CatalogImportCandidateClassification::InvalidCandidate) {
            $reasons[] = 'invalid_candidate';
        }

        $referenceTarget = CatalogImportReviewReferenceTarget::tryFrom(
            is_string($reviewEntry['reference_target'] ?? null) ? $reviewEntry['reference_target'] : '',
        );

        if ($referenceTarget === null || $referenceTarget === CatalogImportReviewReferenceTarget::Unresolved) {
            $reasons[] = 'reference_target_unresolved';
        }

        if (! $this->stableKeyIsSafe($reviewEntry['stable_key'] ?? null)) {
            $reasons[] = 'stable_key_invalid_or_source_dependent';
        }

        if (($reviewEntry['reference_visibility'] ?? null) !== CatalogVisibility::Global->value) {
            $reasons[] = 'reference_visibility_unresolved_or_unsupported';
        }

        if (! $this->ownerDecisionIsExplicitAndSupported($reviewEntry)) {
            $reasons[] = 'owner_user_id_unresolved_or_unsupported';
        }

        if (! is_bool($reviewEntry['is_generic'] ?? null)) {
            $reasons[] = 'genericity_unresolved';
        }

        foreach ([
            'version_locale' => 'version_locale_unresolved',
            'catalog_classification' => 'catalog_classification_unresolved',
        ] as $field => $reason) {
            if (! $this->isNonblankText($reviewEntry[$field] ?? null)) {
                $reasons[] = $reason;
            }
        }

        if (! $this->preparationIsResolved($reviewEntry['preparation_decision'] ?? null)) {
            $reasons[] = 'preparation_unresolved_or_invalid';
        }

        $aliasDecisions = $reviewEntry['alias_decisions'] ?? null;

        if (! is_array($aliasDecisions) || ! array_is_list($aliasDecisions) || $aliasDecisions === []) {
            $reasons[] = 'alias_decisions_missing';
        } else {
            foreach ($aliasDecisions as $aliasDecision) {
                if (! $this->aliasIsResolved($aliasDecision)) {
                    $reasons[] = 'alias_decision_unresolved_or_invalid';
                    break;
                }
            }
        }

        $existingReferencePublicId = $reviewEntry['existing_reference_public_id'] ?? null;

        if (
            $referenceTarget === CatalogImportReviewReferenceTarget::ExistingReference
            && ! $this->isCanonicalUuid($existingReferencePublicId)
        ) {
            $reasons[] = 'existing_reference_public_id_required';
        }

        if (
            $referenceTarget === CatalogImportReviewReferenceTarget::NewReference
            && $existingReferencePublicId !== null
        ) {
            $reasons[] = 'new_reference_rejects_existing_public_id';
        }

        $conflictDecisions = $reviewEntry['preflight_conflict_decisions'] ?? [];

        if (! is_array($conflictDecisions) || ! array_is_list($conflictDecisions)) {
            $reasons[] = 'preflight_conflicts_invalid';
        } else {
            foreach ($conflictDecisions as $conflictDecision) {
                if (! is_array($conflictDecision)) {
                    $reasons[] = 'preflight_conflicts_invalid';
                    break;
                }

                if (($conflictDecision['immutable_field_conflict'] ?? false) === true) {
                    $reasons[] = 'immutable_field_conflict';
                }

                if (($conflictDecision['resolution_status'] ?? null) !== 'resolved') {
                    $reasons[] = 'preflight_conflict_unresolved';
                }
            }
        }

        if (! is_bool($reviewEntry['selected_for_apply'] ?? null)) {
            $reasons[] = 'selection_not_explicit';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'selected_for_apply' => ($reviewEntry['selected_for_apply'] ?? false) === true,
        ];
    }

    private function stableKeyIsSafe(mixed $stableKey): bool
    {
        if (! is_string($stableKey)) {
            return false;
        }

        try {
            new ConceptualStableKey($stableKey);
        } catch (InvalidArgumentException) {
            return false;
        }

        $lowercaseStableKey = mb_strtolower($stableKey, 'UTF-8');

        return ! str_contains($lowercaseStableKey, 'legacy_config')
            && ! str_contains($lowercaseStableKey, 'nutrition.php')
            && ! str_contains($lowercaseStableKey, '/home/')
            && ! str_contains($lowercaseStableKey, '/users/')
            && ! str_contains($stableKey, '\\')
            && ! str_starts_with($stableKey, '/')
            && preg_match('/(^|[^0-9a-f])[0-9a-f]{40}([0-9a-f]{24})?([^0-9a-f]|$)/i', $stableKey) !== 1;
    }

    /** @param array<string, mixed> $reviewEntry */
    private function ownerDecisionIsExplicitAndSupported(array $reviewEntry): bool
    {
        $status = CatalogImportOwnerDecisionStatus::tryFrom(
            is_string($reviewEntry['owner_user_id_decision'] ?? null)
                ? $reviewEntry['owner_user_id_decision']
                : '',
        );
        $ownerUserId = $reviewEntry['owner_user_id'] ?? null;

        return $status === CatalogImportOwnerDecisionStatus::ExplicitNull
            && $ownerUserId === null;
    }

    private function preparationIsResolved(mixed $preparation): bool
    {
        if (! is_array($preparation) || array_is_list($preparation)) {
            return false;
        }

        $status = CatalogImportReviewPreparationStatus::tryFrom(
            is_string($preparation['status'] ?? null) ? $preparation['status'] : '',
        );

        return match ($status) {
            CatalogImportReviewPreparationStatus::ExplicitNull => ($preparation['preparation_key'] ?? null) === null,
            CatalogImportReviewPreparationStatus::ResolvedValue => $this->isNonblankText(
                $preparation['preparation_key'] ?? null,
            ),
            default => false,
        };
    }

    private function aliasIsResolved(mixed $aliasDecision): bool
    {
        if (! is_array($aliasDecision) || array_is_list($aliasDecision)) {
            return false;
        }

        $status = CatalogImportAliasReviewStatus::tryFrom(
            is_string($aliasDecision['status'] ?? null) ? $aliasDecision['status'] : '',
        );
        $aliasKind = $aliasDecision['alias_kind'] ?? null;

        return match ($status) {
            CatalogImportAliasReviewStatus::Include => is_string($aliasKind)
                && in_array($aliasKind, self::ALLOWED_ALIAS_KINDS, true),
            CatalogImportAliasReviewStatus::Exclude => $aliasKind === null,
            default => false,
        };
    }

    private function isNonblankText(mixed $value): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && trim($value) === $value
            && mb_check_encoding($value, 'UTF-8');
    }

    private function isCanonicalUuid(mixed $uuid): bool
    {
        return is_string($uuid)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) === 1;
    }
}
