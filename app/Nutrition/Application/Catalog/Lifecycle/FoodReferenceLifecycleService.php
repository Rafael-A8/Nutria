<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleSubjectNotFoundException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleTransitionPersistenceException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodReferenceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodReferenceLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReference;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class FoodReferenceLifecycleService
{
    public function __construct(
        private FoodReferenceLifecyclePolicy $policy,
        private CatalogLifecycleEventStore $eventStore,
        private CatalogLifecycleReplayGuard $replayGuard,
        private CatalogLifecycleRootEventFactory $rootEventFactory,
        private CatalogLifecycleProjectionStateResolver $stateResolver,
    ) {}

    public function archive(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        $this->validateInvocation($command, $context);

        return DB::transaction(function () use ($command, $context): CatalogLifecycleExecutionResult {
            $reference = FoodReference::query()->where('public_id', $command->subjectId)->lockForUpdate()->first();
            if ($reference === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $activeVersions = $this->activeQuery(FoodReferenceVersion::query(), $reference->id)->orderBy('id')->lockForUpdate()->get();
            $activeAliases = $this->activeQuery(FoodAlias::query(), $reference->id)->orderBy('id')->lockForUpdate()->get();
            $activePortions = $this->activeQuery(FoodPortion::query(), $reference->id)->orderBy('id')->lockForUpdate()->get();
            $replay = $this->replayGuard->replay($command, $context->actorReference);
            if ($replay !== null) {
                return $replay;
            }

            $result = $this->policy->evaluate($command, new FoodReferenceLifecycleSnapshot(
                subjectId: $reference->public_id,
                exists: true,
                state: $this->stateResolver->reference($reference),
                hasActiveVersion: $activeVersions->isNotEmpty(),
                hasActiveAlias: $activeAliases->isNotEmpty(),
                hasActivePortion: $activePortions->isNotEmpty(),
            ));

            if ($result->outcome === CatalogLifecycleOutcome::Succeeded) {
                try {
                    $reference->forceFill([
                        'archived_at' => $command->occurredAt,
                        'archived_by_user_id' => $context->actorUserId,
                        'archive_reason' => $command->reason,
                    ])->save();
                } catch (Throwable $exception) {
                    throw new CatalogLifecycleTransitionPersistenceException($exception);
                }
            }

            $storedEvent = $this->eventStore->storeRoot(
                $this->rootEventFactory->create($command, $context, $reference->id, $reference->public_id, $result),
            );

            return new CatalogLifecycleExecutionResult($storedEvent->toLifecycleResult(), $storedEvent, false);
        }, attempts: 3);
    }

    /** @param Builder<FoodReferenceVersion|FoodAlias|FoodPortion> $query */
    private function activeQuery(Builder $query, int $referenceId): Builder
    {
        return $query->where('food_reference_id', $referenceId)->whereNotNull('activated_at')
            ->whereNull('deactivated_at')->whereNull('withdrawn_at')->whereNull('archived_at');
    }

    private function validateInvocation(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): void
    {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Reference || $command->operation !== CatalogLifecycleOperation::Archive) {
            throw new InvalidArgumentException('The lifecycle command does not match the reference service operation.');
        }
        if ((string) $context->actorUserId !== $command->actorId) {
            throw new InvalidArgumentException('The lifecycle actor does not match the execution context.');
        }
    }
}
