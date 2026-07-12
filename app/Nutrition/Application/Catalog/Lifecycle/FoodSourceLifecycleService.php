<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleSubjectNotFoundException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleTransitionPersistenceException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;
use App\Nutrition\Domain\Catalog\Enums\FoodSourceAuthorityStatus;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\Policies\FoodSourceLifecyclePolicy;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\FoodSourceLifecycleSnapshot;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodAlias;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodPortion;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodReferenceVersionSource;
use App\Nutrition\Infrastructure\Catalog\Eloquent\FoodSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class FoodSourceLifecycleService
{
    public function __construct(
        private FoodSourceLifecyclePolicy $policy,
        private CatalogLifecycleEventStore $eventStore,
        private CatalogLifecycleReplayGuard $replayGuard,
        private CatalogLifecycleRootEventFactory $rootEventFactory,
        private CatalogLifecycleProjectionStateResolver $stateResolver,
    ) {}

    public function changeAuthority(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        FoodSourceAuthorityStatus $targetAuthority,
    ): CatalogLifecycleExecutionResult {
        return $this->execute($command, $context, CatalogLifecycleOperation::ChangeAuthority, $targetAuthority);
    }

    public function archive(CatalogLifecycleCommand $command, CatalogLifecycleExecutionContext $context): CatalogLifecycleExecutionResult
    {
        return $this->execute($command, $context, CatalogLifecycleOperation::Archive);
    }

    private function execute(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        CatalogLifecycleOperation $operation,
        ?FoodSourceAuthorityStatus $targetAuthority = null,
    ): CatalogLifecycleExecutionResult {
        $this->validateInvocation($command, $context, $operation, $targetAuthority);

        return DB::transaction(function () use ($command, $context, $operation, $targetAuthority): CatalogLifecycleExecutionResult {
            $source = FoodSource::query()->where('public_id', $command->subjectId)->lockForUpdate()->first();
            if ($source === null) {
                throw new CatalogLifecycleSubjectNotFoundException;
            }

            $isReferenced = FoodReferenceVersionSource::query()->where('food_source_id', $source->id)->exists()
                || FoodAlias::query()->where('food_source_id', $source->id)->exists()
                || FoodPortion::query()->where('food_source_id', $source->id)->exists();
            $replay = $this->replayGuard->replay($command, $context->actorReference);
            if ($replay !== null) {
                return $replay;
            }

            $result = $this->policy->evaluate($command, new FoodSourceLifecycleSnapshot(
                subjectId: $source->public_id,
                exists: true,
                state: $this->stateResolver->source($source),
                isAlreadyReferenced: $isReferenced,
                authorityChangeValid: $targetAuthority !== null && $targetAuthority !== $source->authority_status,
                archiveAllowed: true,
            ));
            $metadata = [];

            if ($result->outcome === CatalogLifecycleOutcome::Succeeded) {
                if ($operation === CatalogLifecycleOperation::ChangeAuthority) {
                    $metadata = [
                        'previous_authority' => $source->authority_status->value,
                        'next_authority' => $targetAuthority->value,
                    ];
                    $source->authority_status = $targetAuthority;
                } else {
                    $source->forceFill([
                        'archived_at' => $command->occurredAt,
                        'archived_by_user_id' => $context->actorUserId,
                        'archive_reason' => $command->reason,
                    ]);
                }

                try {
                    $source->save();
                } catch (Throwable $exception) {
                    throw new CatalogLifecycleTransitionPersistenceException($exception);
                }
            }

            $storedEvent = $this->eventStore->storeRoot(
                $this->rootEventFactory->create($command, $context, $source->id, $source->public_id, $result, $metadata),
            );

            return new CatalogLifecycleExecutionResult($storedEvent->toLifecycleResult(), $storedEvent, false);
        }, attempts: 3);
    }

    private function validateInvocation(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        CatalogLifecycleOperation $operation,
        ?FoodSourceAuthorityStatus $targetAuthority,
    ): void {
        if ($command->subjectType !== CatalogLifecycleSubjectType::Source || $command->operation !== $operation) {
            throw new InvalidArgumentException('The lifecycle command does not match the source service operation.');
        }
        if (($operation === CatalogLifecycleOperation::ChangeAuthority) !== ($targetAuthority !== null)) {
            throw new InvalidArgumentException('The source authority target does not match the service operation.');
        }
        if ((string) $context->actorUserId !== $command->actorId) {
            throw new InvalidArgumentException('The lifecycle actor does not match the execution context.');
        }
    }
}
