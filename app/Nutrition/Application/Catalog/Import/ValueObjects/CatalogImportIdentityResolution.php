<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIdentityResolutionStatus;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportReferenceTarget;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use InvalidArgumentException;

final readonly class CatalogImportIdentityResolution
{
    /**
     * @param  list<CatalogImportAliasIdentity>  $aliases
     */
    private function __construct(
        public CatalogImportIdentityResolutionStatus $status,
        public CatalogImportIssueSet $issues,
        public ?CatalogImportReferenceTarget $referenceTarget,
        public ?ConceptualStableKey $stableKey,
        public ?CatalogVisibility $referenceVisibility,
        public ?int $ownerUserId,
        public ?bool $isGeneric,
        public ?string $versionLocale,
        public ?string $classification,
        public ?CatalogImportPreparationDecision $preparation,
        public array $aliases,
        public ?LegacyCatalogSourceLinkSemantics $sourceLink,
        public ?string $existingReferencePublicId,
    ) {
        if ($status === CatalogImportIdentityResolutionStatus::Resolved) {
            $this->assertResolvedIdentity();
        }
    }

    /**
     * @param  list<CatalogImportAliasIdentity>  $aliases
     */
    public static function resolved(
        CatalogImportReferenceTarget $referenceTarget,
        ConceptualStableKey $stableKey,
        CatalogVisibility $referenceVisibility,
        ?int $ownerUserId,
        bool $isGeneric,
        string $versionLocale,
        string $classification,
        CatalogImportPreparationDecision $preparation,
        array $aliases,
        LegacyCatalogSourceLinkSemantics $sourceLink,
        ?string $existingReferencePublicId,
        ?CatalogImportIssueSet $issues = null,
    ): self {
        return new self(
            CatalogImportIdentityResolutionStatus::Resolved,
            $issues ?? CatalogImportIssueSet::none(),
            $referenceTarget,
            $stableKey,
            $referenceVisibility,
            $ownerUserId,
            $isGeneric,
            $versionLocale,
            $classification,
            $preparation,
            $aliases,
            $sourceLink,
            $existingReferencePublicId,
        );
    }

    public static function unresolved(CatalogImportIssueSet $issues): self
    {
        if (! $issues->containsAny([
            CatalogImportIssueCode::ConceptualIdentityUnresolved,
            CatalogImportIssueCode::GenericityUnresolved,
            CatalogImportIssueCode::ClassificationUnresolved,
            CatalogImportIssueCode::PreparationUnresolved,
            CatalogImportIssueCode::AliasKindUnresolved,
        ])) {
            throw new InvalidArgumentException('Unresolved identity requires an explicit unresolved identity issue.');
        }

        return self::withoutResolvedFields(CatalogImportIdentityResolutionStatus::Unresolved, $issues);
    }

    public static function conflict(CatalogImportIssueSet $issues): self
    {
        if (! $issues->contains(CatalogImportIssueCode::ImmutableFieldConflict)) {
            throw new InvalidArgumentException('Conflicting identity requires an immutable-field conflict issue.');
        }

        return self::withoutResolvedFields(CatalogImportIdentityResolutionStatus::Conflict, $issues);
    }

    public function isComplete(): bool
    {
        return $this->status === CatalogImportIdentityResolutionStatus::Resolved;
    }

    public function effectiveReferencePublicId(string $plannedNewReferencePublicId): string
    {
        if (! $this->isComplete()) {
            throw new InvalidArgumentException('Child identities require a completely resolved reference identity.');
        }

        self::assertCanonicalUuid($plannedNewReferencePublicId);

        return $this->referenceTarget === CatalogImportReferenceTarget::ExistingReference
            ? $this->existingReferencePublicId
            : $plannedNewReferencePublicId;
    }

    private static function withoutResolvedFields(
        CatalogImportIdentityResolutionStatus $status,
        CatalogImportIssueSet $issues,
    ): self {
        return new self(
            $status,
            $issues,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
            null,
            null,
        );
    }

    private function assertResolvedIdentity(): void
    {
        if (
            $this->referenceTarget === null
            || $this->stableKey === null
            || $this->referenceVisibility === null
            || $this->isGeneric === null
            || $this->versionLocale === null
            || $this->classification === null
            || $this->preparation === null
            || $this->sourceLink === null
        ) {
            throw new InvalidArgumentException('Resolved catalog import identity requires every immutable decision.');
        }

        if ($this->referenceVisibility !== CatalogVisibility::Global || $this->ownerUserId !== null) {
            throw new InvalidArgumentException('Legacy catalog references must explicitly resolve to global ownership.');
        }

        foreach ([$this->versionLocale, $this->classification] as $value) {
            if (
                trim($value) === ''
                || trim($value) !== $value
                || ! mb_check_encoding($value, 'UTF-8')
            ) {
                throw new InvalidArgumentException('Version identity values must be nonblank, trimmed, and valid UTF-8.');
            }
        }

        if (! array_is_list($this->aliases) || $this->aliases === []) {
            throw new InvalidArgumentException('Resolved legacy identity requires an explicit nonempty alias list.');
        }

        foreach ($this->aliases as $alias) {
            if (! $alias instanceof CatalogImportAliasIdentity) {
                throw new InvalidArgumentException('Resolved aliases must use typed alias identities.');
            }
        }

        if ($this->referenceTarget === CatalogImportReferenceTarget::ExistingReference) {
            if ($this->existingReferencePublicId === null) {
                throw new InvalidArgumentException('An existing-reference target requires its public UUID.');
            }

            self::assertCanonicalUuid($this->existingReferencePublicId);
        }

        if (
            $this->referenceTarget === CatalogImportReferenceTarget::NewReference
            && $this->existingReferencePublicId !== null
        ) {
            throw new InvalidArgumentException('A new-reference target cannot carry an existing reference UUID.');
        }
    }

    private static function assertCanonicalUuid(string $uuid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException('A canonical UUID is required.');
        }
    }
}
