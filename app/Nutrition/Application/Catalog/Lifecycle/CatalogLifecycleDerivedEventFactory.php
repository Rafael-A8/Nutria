<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionContext;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;

final class CatalogLifecycleDerivedEventFactory
{
    /** @param array<string, mixed> $metadata */
    public function create(
        CatalogLifecycleEventDraft $root,
        int $subjectInternalId,
        string $subjectPublicId,
        CatalogLifecycleOperation $operation,
        CatalogLifecycleResult $result,
        CatalogLifecycleExecutionContext $context,
        array $metadata = [],
    ): CatalogLifecycleEventDraft {
        return CatalogLifecycleEventDraft::derived(
            subjectType: $root->subjectType,
            subjectInternalId: $subjectInternalId,
            subjectPublicId: $subjectPublicId,
            operation: $operation,
            outcome: $result->outcome,
            reasonCode: $result->reason,
            reason: $root->reason,
            previousState: $result->previousState,
            nextState: $result->nextState,
            eligibilityReasons: $result->eligibility->reasons(),
            actorUserId: $context->actorUserId,
            actorReference: $context->actorReference,
            metadata: $metadata,
            occurredAt: $root->occurredAt,
            correlationId: $root->correlationId,
            transactionId: $root->transactionId,
        );
    }
}
