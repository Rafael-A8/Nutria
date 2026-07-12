<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleSubjectNotFoundException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleTransitionPersistenceException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodPortionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodPortionLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class FoodPortionLifecycleService
{
    public function __construct(
        private FoodPortionLifecyclePolicy $policy,
        private CatalogLifecycleEventStore $eventStore,
        private CatalogLifecycleReplayGuard $replayGuard,
        private CatalogLifecycleRootEventFactory $rootEventFactory,
        private CatalogLifecycleProjectionStateResolver $stateResolver,
    ) {}

    public function submitForReview(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::SubmitForReview);
    }

    public function returnToDraft(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::ReturnToDraft);
    }

    public function approve(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Approve);
    }

    public function reject(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Reject);
    }

    public function publish(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Publish);
    }

    public function activate(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Activate);
    }

    public function reactivate(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Reactivate);
    }

    public function deactivate(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Deactivate);
    }

    public function withdraw(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Withdraw);
    }

    public function archive(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Archive);
    }

    private function execute(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context, CatalogLifecycleOperation $operation): CatalogLifecycleExecutionResult
    {
        $this->validateInvocation($command, $context, $operation);

        return DB::transaction(function () use ($command, $context, $operation): CatalogLifecycleExecutionResult {
            $identity = FoodPortion::query()->select(['id', 'public_id', 'food_reference_id'])->where('public_id', $command->subjectId)->first();
            if ($identity === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $reference = FoodReference::query()->lockForUpdate()->find($identity->food_reference_id);
            $subject = FoodPortion::query()->lockForUpdate()->find($identity->id);
            if ($reference === null || $subject === null || $subject->food_reference_id !== $reference->id) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $lineage = FoodPortion::query()->where('lineage_id', $subject->lineage_id)->whereKeyNot($subject->id)
                ->orderBy('id')->lockForUpdate()->get();
            $matchingPreparationExists = $subject->preparation_key === 'any'
                || FoodReferenceVersion::query()->where('food_reference_id', $reference->id)
                    ->where('preparation_key', $subject->preparation_key)->lockForUpdate()->exists();
            $source = $subject->food_source_id === null ? null : FoodSource::query()->lockForUpdate()->find($subject->food_source_id);
            $activeConflicts = FoodPortion::query()
                ->where('food_reference_id', $reference->id)->where('locale', $subject->locale)
                ->where('portion_key', $subject->portion_key)->where('preparation_key', $subject->preparation_key)
                ->whereKeyNot($subject->id)->whereNotNull('activated_at')->whereNull('deactivated_at')
                ->whereNull('withdrawn_at')->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get();

            $replay = $this->replayGuard->replay($command, $context->actorReference);
            if ($replay !== null) {
                return $replay;
            }

            $result = $this->policy->evaluate($command, $this->snapshot(
                $subject, $reference, $source, $lineage, $activeConflicts, $matchingPreparationExists, $context,
            ));
            if ($result->outcome === CatalogLifecycleOutcome::Succeeded) {
                $this->applyProjection($subject, $operation, $command, $context);
            }

            $storedEvent = $this->eventStore->storeRoot(
                $this->rootEventFactory->create($command, $context, $subject->id, $subject->public_id, $result),
            );

            return new CatalogLifecycleExecutionResult($storedEvent->toLifecycleResult(), $storedEvent, false);
        }, attempts: 3);
    }

    /** @param Collection<int, FoodPortion> $lineage @param Collection<int, FoodPortion> $activeConflicts */
    private function snapshot(
        FoodPortion $subject,
        FoodReference $reference,
        ?FoodSource $source,
        Collection $lineage,
        Collection $activeConflicts,
        bool $matchingPreparationExists,
        CatalogLifecycleExecutionContext $context,
    ): FoodPortionLifecycleSnapshot {
        $successor = $lineage->first(fn (FoodPortion $portion): bool => $portion->supersedes_food_portion_id === $subject->id);

        return new FoodPortionLifecycleSnapshot(
            subjectId: $subject->public_id,
            exists: true,
            state: $this->stateResolver->reviewable($subject),
            actorIsOriginalAuthor: $subject->created_by_user_id === $context->actorUserId,
            parentArchived: $reference->archived_at !== null,
            contentComplete: $this->nonblank($subject->portion_key) && $this->nonblank($subject->display_label)
                && $this->nonblank($subject->normalized_label) && $this->nonblank($subject->locale)
                && $this->nonblank($subject->portion_type) && $this->nonblank($subject->unit_code)
                && $this->nonblank($subject->preparation_key),
            localePresent: $this->nonblank($subject->locale),
            provenanceComplete: is_array($subject->provenance) && $subject->provenance !== [],
            hasPositiveUnitQuantity: (float) $subject->unit_quantity > 0,
            hasPositiveGramWeight: (float) $subject->gram_weight > 0,
            preparationApplicabilityValid: $matchingPreparationExists,
            sourcePresent: $source !== null,
            sourceEligible: $source !== null && $source->authority_status === FoodSourceAuthorityStatus::Eligible
                && $source->kind !== FoodSourceKind::AppGeneratedEstimate,
            sourceProhibited: $source !== null && ($source->authority_status === FoodSourceAuthorityStatus::Prohibited
                || $source->kind === FoodSourceKind::AppGeneratedEstimate),
            sourceArchived: $source?->archived_at !== null,
            sourceRecordKeyPresent: $source !== null && $this->nonblank($subject->source_record_key),
            sourceScopeCompatible: $source !== null && $this->scopeCompatible($reference, $source),
            hasActivePortionConflict: $activeConflicts->isNotEmpty(),
            hasSuccessor: $successor !== null,
            isLineageHead: $subject->revision_number === $lineage->push($subject)->max('revision_number'),
            isSupersededPredecessor: $successor !== null,
            successorParentMatches: $successor !== null && $successor->food_reference_id === $reference->id,
            successorLineageMatches: $successor !== null && $successor->lineage_id === $subject->lineage_id,
            successorNumberIsContiguous: $successor !== null && $successor->revision_number === $subject->revision_number + 1,
        );
    }

    private function applyProjection(FoodPortion $subject, CatalogLifecycleOperation $operation, CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): void
    {
        $attributes = match ($operation) {
            CatalogLifecycleOperation::SubmitForReview => ['review_status' => 'pending_review', 'submitted_at' => $command->occurredAt, 'submitted_by_user_id' => $context->actorUserId, 'reviewed_at' => null, 'reviewed_by_user_id' => null, 'review_reason' => null],
            CatalogLifecycleOperation::ReturnToDraft => ['review_status' => 'draft', 'submitted_at' => null, 'submitted_by_user_id' => null, 'reviewed_at' => null, 'reviewed_by_user_id' => null, 'review_reason' => null],
            CatalogLifecycleOperation::Approve => ['review_status' => 'approved', 'reviewed_at' => $command->occurredAt, 'reviewed_by_user_id' => $context->actorUserId, 'review_reason' => $command->reason],
            CatalogLifecycleOperation::Reject => ['review_status' => 'rejected', 'reviewed_at' => $command->occurredAt, 'reviewed_by_user_id' => $context->actorUserId, 'review_reason' => $command->reason],
            CatalogLifecycleOperation::Publish => ['published_at' => $command->occurredAt, 'published_by_user_id' => $context->actorUserId],
            CatalogLifecycleOperation::Activate, CatalogLifecycleOperation::Reactivate => ['activated_at' => $command->occurredAt, 'activated_by_user_id' => $context->actorUserId, 'deactivated_at' => null, 'deactivated_by_user_id' => null, 'deactivation_reason' => null],
            CatalogLifecycleOperation::Deactivate => ['deactivated_at' => $command->occurredAt, 'deactivated_by_user_id' => $context->actorUserId, 'deactivation_reason' => $command->reason],
            CatalogLifecycleOperation::Withdraw => ['withdrawn_at' => $command->occurredAt, 'withdrawn_by_user_id' => $context->actorUserId, 'withdrawal_reason' => $command->reason],
            CatalogLifecycleOperation::Archive => ['archived_at' => $command->occurredAt, 'archived_by_user_id' => $context->actorUserId, 'archive_reason' => $command->reason],
            default => throw new InvalidArgumentException('Unsupported portion lifecycle operation.'),
        };

        try {
            $subject->forceFill($attributes)->save();
        } catch (Throwable $exception) {
            throw new CatalogLifecycleTransitionPersistenceException($exception);
        }
    }

    private function validateInvocation(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context, CatalogLifecycleOperation $operation): void
    {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Portion || $command->operation !== $operation) {
            throw new InvalidArgumentException('The lifecycle command does not match the portion service operation.');
        }
        if ((string) $context->actorUserId !== $command->actorId) {
            throw new InvalidArgumentException('The lifecycle actor does not match the execution context.');
        }
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
}
