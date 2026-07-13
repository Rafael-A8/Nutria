<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleSubjectNotFoundException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleTransitionPersistenceException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogActiveReplacementResult;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogSuccessorCreationResult;
use App\Nutrition\Domain\Catalog\Enums\CatalogReviewStatus;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceVersionLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class FoodReferenceVersionSupersessionService
{
    public function __construct(
        private FoodReferenceVersionLifecyclePolicy $policy,
        private CatalogLifecycleEventStore $eventStore,
        private CatalogLifecycleReplayGuard $replayGuard,
        private CatalogLifecycleRootEventFactory $rootEventFactory,
        private CatalogLifecycleDerivedEventFactory $derivedEventFactory,
        private CatalogLifecycleProjectionStateResolver $stateResolver,
    ) {}

    public function createSuccessor(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
    ): CatalogSuccessorCreationResult {
        $this->validateInvocation($command, $context, CatalogLifecycleOperation::CreateSuccessor);

        return DB::transaction(function () use ($command, $context): CatalogSuccessorCreationResult {
            $identity = FoodReferenceVersion::query()
                ->select(['id', 'food_reference_id'])
                ->where('public_id', $command->subjectId)
                ->first();

            if ($identity === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $reference = FoodReference::query()->lockForUpdate()->find($identity->food_reference_id);
            $predecessor = FoodReferenceVersion::query()->lockForUpdate()->find($identity->id);

            if ($reference === null || $predecessor === null || $predecessor->food_reference_id !== $reference->id) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $versions = FoodReferenceVersion::query()
                ->where('food_reference_id', $reference->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $directSuccessor = $versions->first(
                fn (FoodReferenceVersion $version): bool => $version->supersedes_food_reference_version_id === $predecessor->id,
            );

            if ($directSuccessor !== null) {
                $directSuccessor = FoodReferenceVersion::query()->lockForUpdate()->find($directSuccessor->id);
            }

            $sourceLinks = FoodReferenceVersionSource::query()
                ->where('food_reference_version_id', $predecessor->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sources = FoodSource::query()
                ->whereKey($sourceLinks->pluck('food_source_id')->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $replay = $this->replayGuard->replay($command, $context->actorReference);

            if ($replay !== null) {
                return $this->creationReplay($replay, $predecessor, $versions);
            }

            $nextVersionNumber = ((int) $versions->max('version_number')) + 1;
            $result = $this->policy->evaluate(
                $command,
                $this->creationSnapshot(
                    $predecessor,
                    $reference,
                    $versions,
                    $sourceLinks,
                    $sources,
                    $directSuccessor,
                    $nextVersionNumber,
                    $context,
                ),
            );

            if ($result->outcome !== CatalogLifecycleOutcome::Succeeded) {
                return $this->storeCreationFailure($command, $context, $predecessor, $result);
            }

            try {
                $successor = FoodReferenceVersion::query()->withSavepointIfNeeded(function () use (
                    $predecessor,
                    $sourceLinks,
                    $nextVersionNumber,
                    $context,
                ): FoodReferenceVersion {
                    $successor = FoodReferenceVersion::query()->create([
                        'public_id' => (string) Str::uuid7(),
                        'food_reference_id' => $predecessor->food_reference_id,
                        'version_number' => $nextVersionNumber,
                        'canonical_name' => $predecessor->canonical_name,
                        'normalized_canonical_name' => $predecessor->normalized_canonical_name,
                        'locale' => $predecessor->locale,
                        'classification' => $predecessor->classification,
                        'preparation_key' => $predecessor->preparation_key,
                        'energy_basis_grams' => $predecessor->energy_basis_grams,
                        'energy_kcal' => $predecessor->energy_kcal,
                        'nutrient_values' => $predecessor->nutrient_values,
                        'provenance' => $predecessor->provenance,
                        'review_status' => CatalogReviewStatus::Draft,
                        'submitted_at' => null,
                        'submitted_by_user_id' => null,
                        'reviewed_at' => null,
                        'reviewed_by_user_id' => null,
                        'review_reason' => null,
                        'published_at' => null,
                        'published_by_user_id' => null,
                        'activated_at' => null,
                        'activated_by_user_id' => null,
                        'deactivated_at' => null,
                        'deactivated_by_user_id' => null,
                        'deactivation_reason' => null,
                        'withdrawn_at' => null,
                        'withdrawn_by_user_id' => null,
                        'withdrawal_reason' => null,
                        'archived_at' => null,
                        'archived_by_user_id' => null,
                        'archive_reason' => null,
                        'supersedes_food_reference_version_id' => $predecessor->id,
                        'created_by_user_id' => $context->actorUserId,
                    ]);

                    foreach ($sourceLinks as $sourceLink) {
                        FoodReferenceVersionSource::query()->create([
                            'food_reference_version_id' => $successor->id,
                            'food_source_id' => $sourceLink->food_source_id,
                            'role' => $sourceLink->role,
                            'source_record_key' => $sourceLink->source_record_key,
                            'evidence_metadata' => $sourceLink->evidence_metadata,
                            'created_by_user_id' => $context->actorUserId,
                        ]);
                    }

                    return $successor;
                });
            } catch (UniqueConstraintViolationException $exception) {
                $reason = $this->creationConflictReason($exception);

                if ($reason === null) {
                    throw new CatalogLifecycleTransitionPersistenceException($exception);
                }

                return $this->storeCreationFailure(
                    $command,
                    $context,
                    $predecessor,
                    CatalogLifecycleResult::conflict($reason, $this->stateResolver->reviewable($predecessor)),
                );
            } catch (Throwable $exception) {
                throw new CatalogLifecycleTransitionPersistenceException($exception);
            }

            $rootDraft = $this->rootEventFactory->create(
                $command,
                $context,
                $predecessor->id,
                $predecessor->public_id,
                $result,
                ['successor_public_id' => $successor->public_id],
            );
            $derivedDraft = $this->derivedEventFactory->create(
                $rootDraft,
                $successor->id,
                $successor->public_id,
                CatalogLifecycleOperation::CreateDraft,
                CatalogLifecycleResult::succeeded(
                    CatalogLifecycleReason::DraftCreated,
                    null,
                    CatalogLifecycleState::Draft,
                ),
                $context,
                ['predecessor_public_id' => $predecessor->public_id],
            );
            $storedRoot = $this->eventStore->storeRoot($rootDraft);
            $this->eventStore->appendDerived($derivedDraft);

            return new CatalogSuccessorCreationResult(
                new CatalogLifecycleExecutionResult($storedRoot->toLifecycleResult(), $storedRoot, false),
                $successor->public_id,
            );
        }, attempts: 3);
    }

    public function activateSuccessorReplacingCurrent(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
    ): CatalogActiveReplacementResult {
        $this->validateInvocation($command, $context, CatalogLifecycleOperation::Activate);

        return DB::transaction(function () use ($command, $context): CatalogActiveReplacementResult {
            $identity = FoodReferenceVersion::query()
                ->select(['id', 'food_reference_id'])
                ->where('public_id', $command->subjectId)
                ->first();

            if ($identity === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $reference = FoodReference::query()->lockForUpdate()->find($identity->food_reference_id);
            $successor = FoodReferenceVersion::query()->lockForUpdate()->find($identity->id);

            if ($reference === null || $successor === null || $successor->food_reference_id !== $reference->id) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $predecessor = $successor->supersedes_food_reference_version_id === null
                ? null
                : FoodReferenceVersion::query()->lockForUpdate()->find($successor->supersedes_food_reference_version_id);
            $activeVersions = FoodReferenceVersion::query()
                ->where('food_reference_id', $reference->id)
                ->whereNotNull('activated_at')
                ->whereNull('deactivated_at')
                ->whereNull('withdrawn_at')
                ->whereNull('archived_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $successorOfSuccessor = FoodReferenceVersion::query()
                ->where('supersedes_food_reference_version_id', $successor->id)
                ->lockForUpdate()
                ->first();
            $sourceLinks = FoodReferenceVersionSource::query()
                ->where('food_reference_version_id', $successor->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sources = FoodSource::query()
                ->whereKey($sourceLinks->pluck('food_source_id')->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $replay = $this->replayGuard->replay($command, $context->actorReference);

            if ($replay !== null) {
                return $this->replacementReplay($replay, $successor, $predecessor);
            }

            $exactReplacement = $predecessor !== null
                && $predecessor->food_reference_id === $reference->id
                && $activeVersions->count() === 1
                && $activeVersions->first()->id === $predecessor->id;
            $result = $this->policy->evaluate(
                $command,
                $this->activationSnapshot(
                    $successor,
                    $reference,
                    $predecessor,
                    $successorOfSuccessor,
                    $sourceLinks,
                    $sources,
                    ! $exactReplacement,
                    $context,
                ),
            );

            if ($result->outcome !== CatalogLifecycleOutcome::Succeeded) {
                return $this->storeReplacementFailure($command, $context, $successor, $result);
            }

            if ($predecessor === null) {
                throw $this->integrityFailure();
            }

            try {
                FoodReferenceVersion::query()->withSavepointIfNeeded(function () use ($predecessor, $successor, $command, $context): void {
                    $predecessor->forceFill([
                        'deactivated_at' => $command->occurredAt,
                        'deactivated_by_user_id' => $context->actorUserId,
                        'deactivation_reason' => $command->reason,
                    ])->save();
                    $successor->forceFill([
                        'activated_at' => $command->occurredAt,
                        'activated_by_user_id' => $context->actorUserId,
                        'deactivated_at' => null,
                        'deactivated_by_user_id' => null,
                        'deactivation_reason' => null,
                    ])->save();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isActiveUniqueConflict($exception)) {
                    throw new CatalogLifecycleTransitionPersistenceException($exception);
                }

                return $this->storeReplacementFailure(
                    $command,
                    $context,
                    $successor,
                    CatalogLifecycleResult::conflict(
                        CatalogLifecycleReason::ActiveVersionConflict,
                        CatalogLifecycleState::PublishedInactive,
                    ),
                );
            } catch (Throwable $exception) {
                throw new CatalogLifecycleTransitionPersistenceException($exception);
            }

            $rootDraft = $this->rootEventFactory->create(
                $command,
                $context,
                $successor->id,
                $successor->public_id,
                $result,
                ['replaced_subject_public_id' => $predecessor->public_id],
            );
            $derivedDraft = $this->derivedEventFactory->create(
                $rootDraft,
                $predecessor->id,
                $predecessor->public_id,
                CatalogLifecycleOperation::Deactivate,
                CatalogLifecycleResult::succeeded(
                    CatalogLifecycleReason::TransitionApplied,
                    CatalogLifecycleState::Active,
                    CatalogLifecycleState::Deactivated,
                ),
                $context,
                ['replacement_subject_public_id' => $successor->public_id],
            );
            $storedRoot = $this->eventStore->storeRoot($rootDraft);
            $this->eventStore->appendDerived($derivedDraft);

            return new CatalogActiveReplacementResult(
                new CatalogLifecycleExecutionResult($storedRoot->toLifecycleResult(), $storedRoot, false),
                $predecessor->public_id,
            );
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, FoodReferenceVersion>  $versions
     * @param  Collection<int, FoodReferenceVersionSource>  $sourceLinks
     * @param  Collection<int, FoodSource>  $sources
     */
    private function creationSnapshot(
        FoodReferenceVersion $predecessor,
        FoodReference $reference,
        Collection $versions,
        Collection $sourceLinks,
        Collection $sources,
        ?FoodReferenceVersion $directSuccessor,
        int $nextVersionNumber,
        CatalogLifecycleExecutionContext $context,
    ): FoodReferenceVersionLifecycleSnapshot {
        return $this->snapshot(
            $predecessor,
            $reference,
            $sourceLinks,
            $sources,
            $context,
            hasActiveConflict: $versions->where('id', '!=', $predecessor->id)
                ->contains(fn (FoodReferenceVersion $version): bool => $this->isActive($version)),
            directSuccessor: $directSuccessor,
            isReferenceHead: $predecessor->version_number === $versions->max('version_number'),
            conceptCompatible: true,
            successorParentMatches: $predecessor->food_reference_id === $reference->id,
            successorNumberIsContiguous: $nextVersionNumber === $predecessor->version_number + 1,
        );
    }

    /**
     * @param  Collection<int, FoodReferenceVersionSource>  $sourceLinks
     * @param  Collection<int, FoodSource>  $sources
     */
    private function activationSnapshot(
        FoodReferenceVersion $successor,
        FoodReference $reference,
        ?FoodReferenceVersion $predecessor,
        ?FoodReferenceVersion $directSuccessor,
        Collection $sourceLinks,
        Collection $sources,
        bool $hasActiveConflict,
        CatalogLifecycleExecutionContext $context,
    ): FoodReferenceVersionLifecycleSnapshot {
        return $this->snapshot(
            $successor,
            $reference,
            $sourceLinks,
            $sources,
            $context,
            hasActiveConflict: $hasActiveConflict,
            directSuccessor: $directSuccessor,
            isReferenceHead: $directSuccessor === null,
            conceptCompatible: $predecessor !== null
                && $predecessor->food_reference_id === $reference->id
                && $predecessor->classification === $successor->classification
                && $predecessor->preparation_key === $successor->preparation_key
                && $predecessor->locale === $successor->locale,
            successorParentMatches: $directSuccessor !== null && $directSuccessor->food_reference_id === $reference->id,
            successorNumberIsContiguous: $directSuccessor !== null
                && $directSuccessor->version_number === $successor->version_number + 1,
        );
    }

    /**
     * @param  Collection<int, FoodReferenceVersionSource>  $sourceLinks
     * @param  Collection<int, FoodSource>  $sources
     */
    private function snapshot(
        FoodReferenceVersion $subject,
        FoodReference $reference,
        Collection $sourceLinks,
        Collection $sources,
        CatalogLifecycleExecutionContext $context,
        bool $hasActiveConflict,
        ?FoodReferenceVersion $directSuccessor,
        bool $isReferenceHead,
        bool $conceptCompatible,
        bool $successorParentMatches,
        bool $successorNumberIsContiguous,
    ): FoodReferenceVersionLifecycleSnapshot {
        $primaryLinks = $sourceLinks->filter(
            fn (FoodReferenceVersionSource $link): bool => $link->role === FoodReferenceVersionSourceRole::Primary,
        )->values();
        $primarySource = $primaryLinks->count() === 1
            ? $sources->get($primaryLinks->first()->food_source_id)
            : null;

        return new FoodReferenceVersionLifecycleSnapshot(
            subjectId: $subject->public_id,
            exists: true,
            state: $this->stateResolver->reviewable($subject),
            actorIsOriginalAuthor: $subject->created_by_user_id === $context->actorUserId,
            parentArchived: $reference->archived_at !== null,
            contentComplete: $this->nonblank($subject->canonical_name)
                && $this->nonblank($subject->normalized_canonical_name)
                && $this->nonblank($subject->locale)
                && $this->nonblank($subject->classification),
            normalizedCanonicalNamePresent: $this->nonblank($subject->normalized_canonical_name),
            provenanceComplete: is_array($subject->provenance) && $subject->provenance !== [],
            conceptCompatible: $conceptCompatible,
            hasPositiveEnergyBasis: (float) $subject->energy_basis_grams > 0,
            hasPositiveEnergyKcal: (float) $subject->energy_kcal > 0,
            primarySourceCount: $primaryLinks->count(),
            primarySourceEligible: $primarySource !== null
                && $primarySource->authority_status === FoodSourceAuthorityStatus::Eligible
                && $primarySource->kind !== FoodSourceKind::AppGeneratedEstimate,
            primarySourceProhibited: $primarySource !== null
                && ($primarySource->authority_status === FoodSourceAuthorityStatus::Prohibited
                    || $primarySource->kind === FoodSourceKind::AppGeneratedEstimate),
            primarySourceArchived: $primarySource?->archived_at !== null,
            primarySourceRecordKeyPresent: $primaryLinks->count() > 0
                && $primaryLinks->every(fn (FoodReferenceVersionSource $link): bool => $this->nonblank($link->source_record_key)),
            sourceScopeCompatible: $primarySource !== null && $this->scopeCompatible($reference, $primarySource),
            hasActiveVersionConflict: $hasActiveConflict,
            hasSuccessor: $directSuccessor !== null,
            isReferenceHead: $isReferenceHead,
            isSupersededPredecessor: $directSuccessor !== null,
            successorParentMatches: $successorParentMatches,
            successorNumberIsContiguous: $successorNumberIsContiguous,
        );
    }

    private function storeCreationFailure(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        FoodReferenceVersion $predecessor,
        CatalogLifecycleResult $result,
    ): CatalogSuccessorCreationResult {
        $storedRoot = $this->eventStore->storeRoot(
            $this->rootEventFactory->create(
                $command,
                $context,
                $predecessor->id,
                $predecessor->public_id,
                $result,
            ),
        );

        return new CatalogSuccessorCreationResult(
            new CatalogLifecycleExecutionResult($storedRoot->toLifecycleResult(), $storedRoot, false),
            null,
        );
    }

    private function storeReplacementFailure(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        FoodReferenceVersion $successor,
        CatalogLifecycleResult $result,
    ): CatalogActiveReplacementResult {
        $storedRoot = $this->eventStore->storeRoot(
            $this->rootEventFactory->create(
                $command,
                $context,
                $successor->id,
                $successor->public_id,
                $result,
            ),
        );

        return new CatalogActiveReplacementResult(
            new CatalogLifecycleExecutionResult($storedRoot->toLifecycleResult(), $storedRoot, false),
            null,
        );
    }

    /** @param Collection<int, FoodReferenceVersion> $versions */
    private function creationReplay(
        CatalogLifecycleExecutionResult $execution,
        FoodReferenceVersion $predecessor,
        Collection $versions,
    ): CatalogSuccessorCreationResult {
        if ($execution->lifecycleResult->outcome !== CatalogLifecycleOutcome::Succeeded) {
            return new CatalogSuccessorCreationResult($execution, null);
        }

        $successorPublicId = $execution->rootEvent->metadata['successor_public_id'] ?? null;
        $successor = is_string($successorPublicId) && $this->isCanonicalUuid($successorPublicId)
            ? $versions->firstWhere('public_id', $successorPublicId)
            : null;

        if ($successor === null
            || $successor->food_reference_id !== $predecessor->food_reference_id
            || $successor->supersedes_food_reference_version_id !== $predecessor->id) {
            throw $this->integrityFailure();
        }

        return new CatalogSuccessorCreationResult($execution, $successorPublicId);
    }

    private function replacementReplay(
        CatalogLifecycleExecutionResult $execution,
        FoodReferenceVersion $successor,
        ?FoodReferenceVersion $predecessor,
    ): CatalogActiveReplacementResult {
        if ($execution->lifecycleResult->outcome !== CatalogLifecycleOutcome::Succeeded) {
            return new CatalogActiveReplacementResult($execution, null);
        }

        $predecessorPublicId = $execution->rootEvent->metadata['replaced_subject_public_id'] ?? null;

        if (! is_string($predecessorPublicId)
            || ! $this->isCanonicalUuid($predecessorPublicId)
            || $predecessor === null
            || $predecessor->public_id !== $predecessorPublicId
            || $successor->supersedes_food_reference_version_id !== $predecessor->id
            || $successor->food_reference_id !== $predecessor->food_reference_id) {
            throw $this->integrityFailure();
        }

        return new CatalogActiveReplacementResult($execution, $predecessorPublicId);
    }

    private function validateInvocation(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        CatalogLifecycleOperation $operation,
    ): void {
        if ($command->subjectType !== CatalogLifecycleSubjectType::ReferenceVersion || $command->operation !== $operation) {
            throw new InvalidArgumentException('The lifecycle command does not match the version supersession operation.');
        }

        if ((string) $context->actorUserId !== $command->actorId) {
            throw new InvalidArgumentException('The lifecycle actor does not match the execution context.');
        }
    }

    private function creationConflictReason(UniqueConstraintViolationException $exception): ?CatalogLifecycleReason
    {
        if ($this->sqlState($exception) !== '23505') {
            return null;
        }

        $detail = $this->driverDetail($exception);

        return match (true) {
            str_contains($detail, 'food_reference_versions_supersedes_food_reference_version_id_unique') => CatalogLifecycleReason::SuccessorExists,
            str_contains($detail, 'food_reference_versions_food_reference_id_version_number_unique') => CatalogLifecycleReason::NumberConflict,
            str_contains($detail, 'food_reference_versions_public_id_unique') => CatalogLifecycleReason::ConcurrencyConflict,
            default => null,
        };
    }

    private function isActiveUniqueConflict(UniqueConstraintViolationException $exception): bool
    {
        return $this->sqlState($exception) === '23505'
            && str_contains($this->driverDetail($exception), 'food_reference_versions_one_active_unique');
    }

    private function sqlState(UniqueConstraintViolationException $exception): string
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode());
    }

    private function driverDetail(UniqueConstraintViolationException $exception): string
    {
        return (string) ($exception->errorInfo[2] ?? '');
    }

    private function integrityFailure(): CatalogLifecycleTransitionPersistenceException
    {
        return new CatalogLifecycleTransitionPersistenceException(
            new InvalidArgumentException('The catalog supersession replay metadata is inconsistent.'),
        );
    }

    private function scopeCompatible(FoodReference $reference, FoodSource $source): bool
    {
        return $reference->visibility === CatalogVisibility::Global
            ? $source->visibility === CatalogVisibility::Global
            : $source->visibility === CatalogVisibility::Global
                || ($source->visibility === CatalogVisibility::Private && $source->owner_user_id === $reference->owner_user_id);
    }

    private function isActive(FoodReferenceVersion $version): bool
    {
        return $version->activated_at !== null
            && $version->deactivated_at === null
            && $version->withdrawn_at === null
            && $version->archived_at === null;
    }

    private function nonblank(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function isCanonicalUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) === 1;
    }
}
