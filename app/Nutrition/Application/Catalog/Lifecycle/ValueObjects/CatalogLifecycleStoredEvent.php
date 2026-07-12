<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogEligibilityResult;
use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleResult;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CatalogLifecycleStoredEvent
{
    /**
     * @param  list<CatalogLifecycleReason>  $eligibilityReasons
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $internalId,
        public string $publicId,
        public CatalogLifecycleSubjectType $subjectType,
        public int $subjectInternalId,
        public string $subjectPublicId,
        public CatalogLifecycleOperation $operation,
        public CatalogLifecycleOutcome $outcome,
        public CatalogLifecycleReason $reasonCode,
        public ?string $reason,
        public ?CatalogLifecycleState $previousState,
        public ?CatalogLifecycleState $nextState,
        public array $eligibilityReasons,
        public ?int $actorUserId,
        public string $actorReference,
        public array $metadata,
        public DateTimeImmutable $occurredAt,
        public ?string $idempotencyKey,
        public ?string $commandFingerprint,
        public string $correlationId,
        public string $transactionId,
        public DateTimeImmutable $createdAt,
    ) {
        if ($this->internalId <= 0) {
            throw new InvalidArgumentException('A stored lifecycle event requires a positive internal identifier.');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->publicId) !== 1) {
            throw new InvalidArgumentException('A stored lifecycle event requires a canonical public UUID.');
        }

        $draftArguments = [
            $this->subjectType,
            $this->subjectInternalId,
            $this->subjectPublicId,
            $this->operation,
            $this->outcome,
            $this->reasonCode,
            $this->reason,
            $this->previousState,
            $this->nextState,
            $this->eligibilityReasons,
            $this->actorUserId,
            $this->actorReference,
            $this->metadata,
            $this->occurredAt,
        ];

        if (($this->idempotencyKey === null) !== ($this->commandFingerprint === null)) {
            throw new InvalidArgumentException('A stored lifecycle event requires a valid root or derived identifier pair.');
        }

        if ($this->idempotencyKey === null) {
            CatalogLifecycleEventDraft::derived(...[
                ...$draftArguments,
                $this->correlationId,
                $this->transactionId,
            ]);
        } else {
            $commandFingerprint = $this->commandFingerprint;

            CatalogLifecycleEventDraft::root(...[
                ...$draftArguments,
                $this->idempotencyKey,
                $commandFingerprint,
                $this->correlationId,
                $this->transactionId,
            ]);
        }
    }

    public function toLifecycleResult(): CatalogLifecycleResult
    {
        return match ($this->outcome) {
            CatalogLifecycleOutcome::Succeeded => CatalogLifecycleResult::succeeded(
                $this->reasonCode,
                $this->previousState,
                $this->nextState,
            ),
            CatalogLifecycleOutcome::NoOp => CatalogLifecycleResult::noOp(
                $this->reasonCode,
                $this->previousState,
            ),
            CatalogLifecycleOutcome::InvalidTransition => CatalogLifecycleResult::invalid(
                $this->reasonCode,
                $this->previousState,
            ),
            CatalogLifecycleOutcome::ValidationFailed => CatalogLifecycleResult::validationFailed(
                $this->reasonCode,
                $this->previousState,
                CatalogEligibilityResult::ineligible($this->eligibilityReasons),
            ),
            CatalogLifecycleOutcome::Conflict => CatalogLifecycleResult::conflict(
                $this->reasonCode,
                $this->previousState,
            ),
        };
    }
}
