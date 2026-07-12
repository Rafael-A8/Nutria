<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleIdempotencyConflictException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleExecutionResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;

final class CatalogLifecycleReplayGuard
{
    public function __construct(
        private CatalogLifecycleEventStore $eventStore,
    ) {}

    public function replay(
        CatalogLifecycleCommand $command,
        string $actorReference,
    ): ?CatalogLifecycleExecutionResult {
        $storedEvent = $this->eventStore->findRootByIdempotencyKey($command->idempotencyKey);

        if ($storedEvent === null) {
            return null;
        }

        $fingerprint = CatalogLifecycleCommandFingerprint::forCommand($command, $actorReference);

        if ($storedEvent->commandFingerprint === null
            || ! hash_equals($storedEvent->commandFingerprint, $fingerprint)) {
            throw new CatalogLifecycleIdempotencyConflictException;
        }

        return new CatalogLifecycleExecutionResult(
            lifecycleResult: $storedEvent->toLifecycleResult(),
            rootEvent: $storedEvent,
            replayed: true,
        );
    }
}
