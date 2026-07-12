<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleSubjectNotFoundException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleTransitionPersistenceException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;
use App\Nutrition\Domain\Catalog\Enums\CatalogVisibility;
use App\Nutrition\Domain\Catalog\Enums\FoodReferenceVersionSourceRole;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceKind;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceVersionLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceVersionLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class FoodReferenceVersionLifecycleService
{
    public function __construct(
        private FoodReferenceVersionLifecyclePolicy $policy,
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

    private function execute(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        CatalogLifecycleOperation $operation,
    ): CatalogLifecycleExecutionResult {
        $this->validateInvocation($command, $context, $operation);

        return DB::transaction(function () use ($command, $context, $operation): CatalogLifecycleExecutionResult {
            $identity = FoodReferenceVersion::query()
                ->select(['id', 'public_id', 'food_reference_id'])
                ->where('public_id', $command->subjectId)
                ->first();

            if ($identity === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $reference = FoodReference::query()->lockForUpdate()->find($identity->food_reference_id);

            if ($reference === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $subject = FoodReferenceVersion::query()->lockForUpdate()->find($identity->id);

            if ($subject === null || $subject->food_reference_id !== $reference->id) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $relatedVersions = FoodReferenceVersion::query()
                ->where(function (Builder $query) use ($reference, $subject): void {
                    $query->where('food_reference_id', $reference->id);

                    if ($subject->supersedes_food_reference_version_id !== null) {
                        $query->orWhereKey($subject->supersedes_food_reference_version_id);
                    }
                })
                ->whereKeyNot($subject->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $sourceLinks = FoodReferenceVersionSource::query()
                ->where('food_reference_version_id', $subject->id)
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
                return $replay;
            }

            $result = $this->policy->evaluate(
                $command,
                $this->snapshot($subject, $reference, $relatedVersions, $sourceLinks, $sources, $context),
            );

            if ($result->outcome === CatalogLifecycleOutcome::Succeeded) {
                $this->applyProjection($subject, $operation, $command, $context);
            }

            $draft = $this->rootEventFactory->create(
                $command,
                $context,
                $subject->id,
                $subject->public_id,
                $result,
            );
            $storedEvent = $this->eventStore->storeRoot($draft);

            return new CatalogLifecycleExecutionResult($storedEvent->toLifecycleResult(), $storedEvent, false);
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, FoodReferenceVersion>  $relatedVersions
     * @param  Collection<int, FoodReferenceVersionSource>  $sourceLinks
     * @param  Collection<int, FoodSource>  $sources
     */
    private function snapshot(
        FoodReferenceVersion $subject,
        FoodReference $reference,
        Collection $relatedVersions,
        Collection $sourceLinks,
        Collection $sources,
        CatalogLifecycleExecutionContext $context,
    ): FoodReferenceVersionLifecycleSnapshot {
        $primaryLinks = $sourceLinks->filter(
            fn (FoodReferenceVersionSource $link): bool => $link->role === FoodReferenceVersionSourceRole::Primary,
        )->values();
        $siblings = $relatedVersions->where('food_reference_id', $reference->id)->values();
        $primarySource = $primaryLinks->count() === 1
            ? $sources->get($primaryLinks->first()->food_source_id)
            : null;
        $successor = $siblings->first(
            fn (FoodReferenceVersion $version): bool => $version->supersedes_food_reference_version_id === $subject->id,
        );
        $predecessor = $subject->supersedes_food_reference_version_id === null
            ? null
            : $relatedVersions->firstWhere('id', $subject->supersedes_food_reference_version_id);

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
            conceptCompatible: $this->conceptCompatible($subject, $reference, $predecessor),
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
            hasActiveVersionConflict: $siblings->contains(fn (FoodReferenceVersion $version): bool => $this->isActive($version)),
            hasSuccessor: $successor !== null,
            isReferenceHead: $subject->version_number === $siblings->push($subject)->max('version_number'),
            isSupersededPredecessor: $successor !== null,
            successorParentMatches: $successor !== null && $successor->food_reference_id === $reference->id,
            successorNumberIsContiguous: $successor !== null && $successor->version_number === $subject->version_number + 1,
        );
    }

    private function applyProjection(
        FoodReferenceVersion $subject,
        CatalogLifecycleOperation $operation,
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
    ): void {
        match ($operation) {
            CatalogLifecycleOperation::SubmitForReview => $subject->forceFill([
                'review_status' => 'pending_review', 'submitted_at' => $command->occurredAt,
                'submitted_by_user_id' => $context->actorUserId, 'reviewed_at' => null,
                'reviewed_by_user_id' => null, 'review_reason' => null,
            ]),
            CatalogLifecycleOperation::ReturnToDraft => $subject->forceFill([
                'review_status' => 'draft', 'submitted_at' => null, 'submitted_by_user_id' => null,
                'reviewed_at' => null, 'reviewed_by_user_id' => null, 'review_reason' => null,
            ]),
            CatalogLifecycleOperation::Approve => $subject->forceFill([
                'review_status' => 'approved', 'reviewed_at' => $command->occurredAt,
                'reviewed_by_user_id' => $context->actorUserId, 'review_reason' => $command->reason,
            ]),
            CatalogLifecycleOperation::Reject => $subject->forceFill([
                'review_status' => 'rejected', 'reviewed_at' => $command->occurredAt,
                'reviewed_by_user_id' => $context->actorUserId, 'review_reason' => $command->reason,
            ]),
            CatalogLifecycleOperation::Publish => $subject->forceFill([
                'published_at' => $command->occurredAt, 'published_by_user_id' => $context->actorUserId,
            ]),
            CatalogLifecycleOperation::Activate, CatalogLifecycleOperation::Reactivate => $subject->forceFill([
                'activated_at' => $command->occurredAt, 'activated_by_user_id' => $context->actorUserId,
                'deactivated_at' => null, 'deactivated_by_user_id' => null, 'deactivation_reason' => null,
            ]),
            CatalogLifecycleOperation::Deactivate => $subject->forceFill([
                'deactivated_at' => $command->occurredAt, 'deactivated_by_user_id' => $context->actorUserId,
                'deactivation_reason' => $command->reason,
            ]),
            CatalogLifecycleOperation::Withdraw => $subject->forceFill([
                'withdrawn_at' => $command->occurredAt, 'withdrawn_by_user_id' => $context->actorUserId,
                'withdrawal_reason' => $command->reason,
            ]),
            CatalogLifecycleOperation::Archive => $subject->forceFill([
                'archived_at' => $command->occurredAt, 'archived_by_user_id' => $context->actorUserId,
                'archive_reason' => $command->reason,
            ]),
            default => throw new InvalidArgumentException('Unsupported version lifecycle operation.'),
        };

        try {
            $subject->save();
        } catch (Throwable $exception) {
            throw new CatalogLifecycleTransitionPersistenceException($exception);
        }
    }

    private function validateInvocation(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context, CatalogLifecycleOperation $operation): void
    {
        if ($command->subjectType !== CatalogLifecycleSubjectType::ReferenceVersion || $command->operation !== $operation) {
            throw new InvalidArgumentException('The lifecycle command does not match the version service operation.');
        }

        if ((string) $context->actorUserId !== $command->actorId) {
            throw new InvalidArgumentException('The lifecycle actor does not match the execution context.');
        }
    }

    private function conceptCompatible(FoodReferenceVersion $subject, FoodReference $reference, ?FoodReferenceVersion $predecessor): bool
    {
        if ($predecessor === null) {
            return $subject->supersedes_food_reference_version_id === null && $this->nonblank($reference->stable_key);
        }

        return $predecessor->food_reference_id === $reference->id
            && $predecessor->classification === $subject->classification
            && $predecessor->preparation_key === $subject->preparation_key
            && $predecessor->locale === $subject->locale;
    }

    private function scopeCompatible(FoodReference $reference, FoodSource $source): bool
    {
        if ($reference->visibility === CatalogVisibility::Global) {
            return $source->visibility === CatalogVisibility::Global;
        }

        return $source->visibility === CatalogVisibility::Global
            || ($source->visibility === CatalogVisibility::Private && $source->owner_user_id === $reference->owner_user_id);
    }

    private function isActive(FoodReferenceVersion $version): bool
    {
        return $version->activated_at !== null && $version->deactivated_at === null
            && $version->withdrawn_at === null && $version->archived_at === null;
    }

    private function nonblank(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
