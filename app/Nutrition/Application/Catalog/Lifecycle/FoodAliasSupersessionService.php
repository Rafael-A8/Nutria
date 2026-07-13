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
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\AliasKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodAliasLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodAliasLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class FoodAliasSupersessionService
{
    public function __construct(
        private FoodAliasLifecyclePolicy $policy,
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
            $identity = FoodAlias::query()
                ->select(['id', 'food_reference_id'])
                ->where('public_id', $command->subjectId)
                ->first();

            if ($identity === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $reference = FoodReference::query()->lockForUpdate()->find($identity->food_reference_id);
            $predecessor = FoodAlias::query()->lockForUpdate()->find($identity->id);

            if ($reference === null || $predecessor === null || $predecessor->food_reference_id !== $reference->id) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $lineage = FoodAlias::query()
                ->where('lineage_id', $predecessor->lineage_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $directSuccessor = $lineage->first(
                fn (FoodAlias $alias): bool => $alias->supersedes_food_alias_id === $predecessor->id,
            );

            if ($directSuccessor !== null) {
                $directSuccessor = FoodAlias::query()->lockForUpdate()->find($directSuccessor->id);
            }

            $source = $predecessor->food_source_id === null
                ? null
                : FoodSource::query()->lockForUpdate()->find($predecessor->food_source_id);
            $replay = $this->replayGuard->replay($command, $context->actorReference);

            if ($replay !== null) {
                return $this->creationReplay($replay, $predecessor, $lineage);
            }

            $nextRevisionNumber = ((int) $lineage->max('revision_number')) + 1;
            $result = $this->policy->evaluate(
                $command,
                $this->snapshot(
                    $predecessor,
                    $reference,
                    $source,
                    $context,
                    hasActiveConflict: false,
                    directSuccessor: $directSuccessor,
                    isLineageHead: $predecessor->revision_number === $lineage->max('revision_number'),
                    successorParentMatches: $predecessor->food_reference_id === $reference->id,
                    successorLineageMatches: true,
                    successorNumberIsContiguous: $nextRevisionNumber === $predecessor->revision_number + 1,
                ),
            );

            if ($result->outcome !== CatalogLifecycleOutcome::Succeeded) {
                return $this->storeCreationFailure($command, $context, $predecessor, $result);
            }

            try {
                $successor = FoodAlias::query()->withSavepointIfNeeded(
                    fn (): FoodAlias => FoodAlias::query()->create([
                        'public_id' => (string) Str::uuid7(),
                        'lineage_id' => $predecessor->lineage_id,
                        'food_reference_id' => $predecessor->food_reference_id,
                        'revision_number' => $nextRevisionNumber,
                        'supersedes_food_alias_id' => $predecessor->id,
                        'display_alias' => $predecessor->display_alias,
                        'normalized_alias' => $predecessor->normalized_alias,
                        'locale' => $predecessor->locale,
                        'alias_kind' => $predecessor->alias_kind,
                        'food_source_id' => $predecessor->food_source_id,
                        'source_record_key' => $predecessor->source_record_key,
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
                        'created_by_user_id' => $context->actorUserId,
                    ]),
                );
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
            $identity = FoodAlias::query()
                ->select(['id', 'food_reference_id'])
                ->where('public_id', $command->subjectId)
                ->first();

            if ($identity === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $reference = FoodReference::query()->lockForUpdate()->find($identity->food_reference_id);
            $successor = FoodAlias::query()->lockForUpdate()->find($identity->id);

            if ($reference === null || $successor === null || $successor->food_reference_id !== $reference->id) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $predecessor = $successor->supersedes_food_alias_id === null
                ? null
                : FoodAlias::query()->lockForUpdate()->find($successor->supersedes_food_alias_id);
            $activeAliases = FoodAlias::query()
                ->where('food_reference_id', $reference->id)
                ->where('locale', $successor->locale)
                ->where('normalized_alias', $successor->normalized_alias)
                ->whereNotNull('activated_at')
                ->whereNull('deactivated_at')
                ->whereNull('withdrawn_at')
                ->whereNull('archived_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $successorOfSuccessor = FoodAlias::query()
                ->where('supersedes_food_alias_id', $successor->id)
                ->lockForUpdate()
                ->first();
            $source = $successor->food_source_id === null
                ? null
                : FoodSource::query()->lockForUpdate()->find($successor->food_source_id);
            $replay = $this->replayGuard->replay($command, $context->actorReference);

            if ($replay !== null) {
                return $this->replacementReplay($replay, $successor, $predecessor);
            }

            $exactReplacement = $predecessor !== null
                && $predecessor->food_reference_id === $reference->id
                && $predecessor->lineage_id === $successor->lineage_id
                && $activeAliases->count() === 1
                && $activeAliases->first()->id === $predecessor->id;
            $result = $this->policy->evaluate(
                $command,
                $this->snapshot(
                    $successor,
                    $reference,
                    $source,
                    $context,
                    hasActiveConflict: ! $exactReplacement,
                    directSuccessor: $successorOfSuccessor,
                    isLineageHead: $successorOfSuccessor === null,
                    successorParentMatches: $successorOfSuccessor !== null
                        && $successorOfSuccessor->food_reference_id === $reference->id,
                    successorLineageMatches: $successorOfSuccessor !== null
                        && $successorOfSuccessor->lineage_id === $successor->lineage_id,
                    successorNumberIsContiguous: $successorOfSuccessor !== null
                        && $successorOfSuccessor->revision_number === $successor->revision_number + 1,
                ),
            );

            if ($result->outcome !== CatalogLifecycleOutcome::Succeeded) {
                return $this->storeReplacementFailure($command, $context, $successor, $result);
            }

            if ($predecessor === null) {
                throw $this->integrityFailure();
            }

            try {
                FoodAlias::query()->withSavepointIfNeeded(function () use ($predecessor, $successor, $command, $context): void {
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
                        CatalogLifecycleReason::ActiveAliasConflict,
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

    private function snapshot(
        FoodAlias $subject,
        FoodReference $reference,
        ?FoodSource $source,
        CatalogLifecycleExecutionContext $context,
        bool $hasActiveConflict,
        ?FoodAlias $directSuccessor,
        bool $isLineageHead,
        bool $successorParentMatches,
        bool $successorLineageMatches,
        bool $successorNumberIsContiguous,
    ): FoodAliasLifecycleSnapshot {
        return new FoodAliasLifecycleSnapshot(
            subjectId: $subject->public_id,
            exists: true,
            state: $this->stateResolver->reviewable($subject),
            actorIsOriginalAuthor: $subject->created_by_user_id === $context->actorUserId,
            parentArchived: $reference->archived_at !== null,
            referenceIsGeneric: $reference->is_generic,
            contentComplete: $this->nonblank($subject->display_alias)
                && $this->nonblank($subject->normalized_alias)
                && $this->nonblank($subject->locale)
                && $this->nonblank($subject->alias_kind),
            normalizedAliasPresent: $this->nonblank($subject->normalized_alias),
            localePresent: $this->nonblank($subject->locale),
            aliasKind: AliasKind::tryFrom((string) $subject->alias_kind),
            provenanceComplete: is_array($subject->provenance) && $subject->provenance !== [],
            sourcePresent: $source !== null,
            sourceEligible: $source !== null
                && $source->authority_status === FoodSourceAuthorityStatus::Eligible
                && $source->kind !== FoodSourceKind::AppGeneratedEstimate,
            sourceProhibited: $source !== null
                && ($source->authority_status === FoodSourceAuthorityStatus::Prohibited
                    || $source->kind === FoodSourceKind::AppGeneratedEstimate),
            sourceArchived: $source?->archived_at !== null,
            sourceRecordKeyPresent: $source !== null && $this->nonblank($subject->source_record_key),
            sourceScopeCompatible: $source !== null && $this->scopeCompatible($reference, $source),
            hasActiveAliasConflict: $hasActiveConflict,
            hasSuccessor: $directSuccessor !== null,
            isLineageHead: $isLineageHead,
            isSupersededPredecessor: $directSuccessor !== null,
            successorParentMatches: $successorParentMatches,
            successorLineageMatches: $successorLineageMatches,
            successorNumberIsContiguous: $successorNumberIsContiguous,
        );
    }

    private function storeCreationFailure(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        FoodAlias $predecessor,
        CatalogLifecycleResult $result,
    ): CatalogSuccessorCreationResult {
        $storedRoot = $this->eventStore->storeRoot(
            $this->rootEventFactory->create($command, $context, $predecessor->id, $predecessor->public_id, $result),
        );

        return new CatalogSuccessorCreationResult(
            new CatalogLifecycleExecutionResult($storedRoot->toLifecycleResult(), $storedRoot, false),
            null,
        );
    }

    private function storeReplacementFailure(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        FoodAlias $successor,
        CatalogLifecycleResult $result,
    ): CatalogActiveReplacementResult {
        $storedRoot = $this->eventStore->storeRoot(
            $this->rootEventFactory->create($command, $context, $successor->id, $successor->public_id, $result),
        );

        return new CatalogActiveReplacementResult(
            new CatalogLifecycleExecutionResult($storedRoot->toLifecycleResult(), $storedRoot, false),
            null,
        );
    }

    /** @param Collection<int, FoodAlias> $lineage */
    private function creationReplay(
        CatalogLifecycleExecutionResult $execution,
        FoodAlias $predecessor,
        Collection $lineage,
    ): CatalogSuccessorCreationResult {
        if ($execution->lifecycleResult->outcome !== CatalogLifecycleOutcome::Succeeded) {
            return new CatalogSuccessorCreationResult($execution, null);
        }

        $successorPublicId = $execution->rootEvent->metadata['successor_public_id'] ?? null;
        $successor = is_string($successorPublicId) && $this->isCanonicalUuid($successorPublicId)
            ? $lineage->firstWhere('public_id', $successorPublicId)
            : null;

        if ($successor === null
            || $successor->food_reference_id !== $predecessor->food_reference_id
            || $successor->lineage_id !== $predecessor->lineage_id
            || $successor->supersedes_food_alias_id !== $predecessor->id) {
            throw $this->integrityFailure();
        }

        return new CatalogSuccessorCreationResult($execution, $successorPublicId);
    }

    private function replacementReplay(
        CatalogLifecycleExecutionResult $execution,
        FoodAlias $successor,
        ?FoodAlias $predecessor,
    ): CatalogActiveReplacementResult {
        if ($execution->lifecycleResult->outcome !== CatalogLifecycleOutcome::Succeeded) {
            return new CatalogActiveReplacementResult($execution, null);
        }

        $predecessorPublicId = $execution->rootEvent->metadata['replaced_subject_public_id'] ?? null;

        if (! is_string($predecessorPublicId)
            || ! $this->isCanonicalUuid($predecessorPublicId)
            || $predecessor === null
            || $predecessor->public_id !== $predecessorPublicId
            || $successor->supersedes_food_alias_id !== $predecessor->id
            || $successor->food_reference_id !== $predecessor->food_reference_id
            || $successor->lineage_id !== $predecessor->lineage_id) {
            throw $this->integrityFailure();
        }

        return new CatalogActiveReplacementResult($execution, $predecessorPublicId);
    }

    private function validateInvocation(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        CatalogLifecycleOperation $operation,
    ): void {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Alias || $command->operation !== $operation) {
            throw new InvalidArgumentException('The lifecycle command does not match the alias supersession operation.');
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
            str_contains($detail, 'food_aliases_supersedes_food_alias_id_unique') => CatalogLifecycleReason::SuccessorExists,
            str_contains($detail, 'food_aliases_lineage_id_revision_number_unique') => CatalogLifecycleReason::NumberConflict,
            str_contains($detail, 'food_aliases_public_id_unique') => CatalogLifecycleReason::ConcurrencyConflict,
            default => null,
        };
    }

    private function isActiveUniqueConflict(UniqueConstraintViolationException $exception): bool
    {
        return $this->sqlState($exception) === '23505'
            && str_contains($this->driverDetail($exception), 'food_aliases_one_active_key_unique');
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

    private function nonblank(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    private function isCanonicalUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) === 1;
    }
}
