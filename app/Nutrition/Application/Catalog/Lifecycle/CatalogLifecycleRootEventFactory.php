<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use Illuminate\Support\Str;

final class CatalogLifecycleRootEventFactory
{
    /** @param array<string, mixed> $metadata */
    public function create(
        CatalogLifecycleCommand $command,
        CatalogLifecycleExecutionContext $context,
        int $subjectInternalId,
        string $subjectPublicId,
        CatalogLifecycleResult $result,
        array $metadata = [],
    ): CatalogLifecycleEventDraft {
        return CatalogLifecycleEventDraft::root(
            subjectType: $command->subjectType,
            subjectInternalId: $subjectInternalId,
            subjectPublicId: $subjectPublicId,
            operation: $command->operation,
            outcome: $result->outcome,
            reasonCode: $result->reason,
            reason: $command->reason,
            previousState: $result->previousState,
            nextState: $result->nextState,
            eligibilityReasons: $result->eligibility->reasons(),
            actorUserId: $context->actorUserId,
            actorReference: $context->actorReference,
            metadata: $metadata,
            occurredAt: $command->occurredAt,
            idempotencyKey: $command->idempotencyKey,
            commandFingerprint: CatalogLifecycleCommandFingerprint::forCommand($command, $context->actorReference),
            correlationId: (string) Str::uuid7(),
            transactionId: (string) Str::uuid7(),
        );
    }
}
