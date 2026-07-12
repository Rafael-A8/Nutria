<?php

namespace App\Nutrition\Infrastructure\Catalog\Eloquent;

use App\Nutrition\Application\Catalog\Lifecycle\Contracts\CatalogLifecycleEventStore;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleEventPersistenceException;
use App\Nutrition\Application\Catalog\Lifecycle\Exceptions\CatalogLifecycleIdempotencyConflictException;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleEventDraft;
use App\Nutrition\Application\Catalog\Lifecycle\ValueObjects\CatalogLifecycleStoredEvent;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class EloquentCatalogLifecycleEventStore implements CatalogLifecycleEventStore
{
    public function findRootByIdempotencyKey(string $idempotencyKey): ?CatalogLifecycleStoredEvent
    {
        $this->validateCanonicalUuid($idempotencyKey);

        try {
            $event = CatalogLifecycleEvent::query()
                ->whereNotNull('idempotency_key')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            return $event === null ? null : $this->toStoredEvent($event);
        } catch (Throwable $exception) {
            throw new CatalogLifecycleEventPersistenceException($exception);
        }
    }

    public function storeRoot(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
    {
        if (! $event->isRoot()) {
            throw new InvalidArgumentException('storeRoot accepts only root lifecycle events.');
        }

        $existingEvent = $this->findRootByIdempotencyKey($event->idempotencyKey);

        if ($existingEvent !== null) {
            return $this->replayOrConflict($existingEvent, $event);
        }

        try {
            $query = CatalogLifecycleEvent::query();
            $storedModel = $query->withSavepointIfNeeded(
                fn (): CatalogLifecycleEvent => $query->create($this->attributesFor($event)),
            );

            return $this->toStoredEvent($storedModel);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRootIdempotencyUniqueViolation($exception)) {
                throw new CatalogLifecycleEventPersistenceException($exception);
            }

            $storedEvent = $this->findRootByIdempotencyKey($event->idempotencyKey);

            if ($storedEvent === null) {
                throw new CatalogLifecycleEventPersistenceException($exception);
            }

            return $this->replayOrConflict($storedEvent, $event, $exception);
        } catch (CatalogLifecycleIdempotencyConflictException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CatalogLifecycleEventPersistenceException($exception);
        }
    }

    public function appendDerived(CatalogLifecycleEventDraft $event): CatalogLifecycleStoredEvent
    {
        if ($event->isRoot()) {
            throw new InvalidArgumentException('appendDerived accepts only derived lifecycle events.');
        }

        try {
            return $this->toStoredEvent(CatalogLifecycleEvent::query()->create($this->attributesFor($event)));
        } catch (Throwable $exception) {
            throw new CatalogLifecycleEventPersistenceException($exception);
        }
    }

    private function replayOrConflict(
        CatalogLifecycleStoredEvent $storedEvent,
        CatalogLifecycleEventDraft $draft,
        ?Throwable $previous = null,
    ): CatalogLifecycleStoredEvent {
        $storedFingerprint = $storedEvent->commandFingerprint;
        $draftFingerprint = $draft->commandFingerprint;

        if ($storedFingerprint !== null
            && $draftFingerprint !== null
            && hash_equals($storedFingerprint, $draftFingerprint)) {
            return $storedEvent;
        }

        throw new CatalogLifecycleIdempotencyConflictException($previous);
    }

    /** @return array<string, mixed> */
    private function attributesFor(CatalogLifecycleEventDraft $event): array
    {
        return [
            'public_id' => (string) Str::uuid7(),
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectInternalId,
            'subject_public_id' => $event->subjectPublicId,
            'event_type' => $event->operation,
            'outcome' => $event->outcome,
            'reason_code' => $event->reasonCode,
            'reason' => $event->reason,
            'previous_state' => $event->previousState,
            'next_state' => $event->nextState,
            'eligibility_reasons' => $event->eligibilityReasons === []
                ? null
                : array_map(
                    fn (CatalogLifecycleReason $reason): string => $reason->value,
                    $event->eligibilityReasons,
                ),
            'actor_user_id' => $event->actorUserId,
            'actor_reference' => $event->actorReference,
            'metadata' => $event->metadata === [] ? null : $event->metadata,
            'occurred_at' => $event->occurredAt,
            'idempotency_key' => $event->idempotencyKey,
            'command_fingerprint' => $event->commandFingerprint,
            'correlation_id' => $event->correlationId,
            'transaction_id' => $event->transactionId,
        ];
    }

    private function toStoredEvent(CatalogLifecycleEvent $event): CatalogLifecycleStoredEvent
    {
        return new CatalogLifecycleStoredEvent(
            internalId: $event->id,
            publicId: $event->public_id,
            subjectType: $event->subject_type,
            subjectInternalId: $event->subject_id,
            subjectPublicId: $event->subject_public_id,
            operation: $event->event_type,
            outcome: $event->outcome,
            reasonCode: $event->reason_code,
            reason: $event->reason,
            previousState: $event->previous_state,
            nextState: $event->next_state,
            eligibilityReasons: array_map(
                fn (string $reason): CatalogLifecycleReason => CatalogLifecycleReason::from($reason),
                $event->eligibility_reasons ?? [],
            ),
            actorUserId: $event->actor_user_id,
            actorReference: $event->actor_reference,
            metadata: $event->metadata ?? [],
            occurredAt: DateTimeImmutable::createFromInterface($event->occurred_at),
            idempotencyKey: $event->idempotency_key,
            commandFingerprint: $event->command_fingerprint,
            correlationId: $event->correlation_id,
            transactionId: $event->transaction_id,
            createdAt: DateTimeImmutable::createFromInterface($event->created_at),
        );
    }

    private function validateCanonicalUuid(string $uuid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException('The lifecycle idempotency key must be a canonical UUID.');
        }
    }

    private function isRootIdempotencyUniqueViolation(UniqueConstraintViolationException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverDetail = (string) ($exception->errorInfo[2] ?? '');

        if ($sqlState === '23505') {
            return str_contains($driverDetail, 'catalog_lifecycle_events_root_idempotency_unique');
        }

        return $sqlState === '23000'
            && str_contains($driverDetail, 'catalog_lifecycle_events.idempotency_key');
    }
}
