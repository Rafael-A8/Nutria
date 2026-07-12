<?php

namespace App\Nutrition\Application\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOutcome;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleReason;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleState;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CatalogLifecycleEventDraft
{
    /**
     * @param  list<CatalogLifecycleReason>  $eligibilityReasons
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
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
    ) {
        $this->validate();
    }

    /**
     * @param  list<CatalogLifecycleReason>  $eligibilityReasons
     * @param  array<string, mixed>  $metadata
     */
    public static function root(
        CatalogLifecycleSubjectType $subjectType,
        int $subjectInternalId,
        string $subjectPublicId,
        CatalogLifecycleOperation $operation,
        CatalogLifecycleOutcome $outcome,
        CatalogLifecycleReason $reasonCode,
        ?string $reason,
        ?CatalogLifecycleState $previousState,
        ?CatalogLifecycleState $nextState,
        array $eligibilityReasons,
        ?int $actorUserId,
        string $actorReference,
        array $metadata,
        DateTimeImmutable $occurredAt,
        string $idempotencyKey,
        ?string $commandFingerprint,
        string $correlationId,
        string $transactionId,
    ): self {
        return new self(
            $subjectType,
            $subjectInternalId,
            $subjectPublicId,
            $operation,
            $outcome,
            $reasonCode,
            $reason,
            $previousState,
            $nextState,
            $eligibilityReasons,
            $actorUserId,
            $actorReference,
            $metadata,
            $occurredAt,
            $idempotencyKey,
            $commandFingerprint,
            $correlationId,
            $transactionId,
        );
    }

    /**
     * @param  list<CatalogLifecycleReason>  $eligibilityReasons
     * @param  array<string, mixed>  $metadata
     */
    public static function derived(
        CatalogLifecycleSubjectType $subjectType,
        int $subjectInternalId,
        string $subjectPublicId,
        CatalogLifecycleOperation $operation,
        CatalogLifecycleOutcome $outcome,
        CatalogLifecycleReason $reasonCode,
        ?string $reason,
        ?CatalogLifecycleState $previousState,
        ?CatalogLifecycleState $nextState,
        array $eligibilityReasons,
        ?int $actorUserId,
        string $actorReference,
        array $metadata,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        string $transactionId,
    ): self {
        return new self(
            $subjectType,
            $subjectInternalId,
            $subjectPublicId,
            $operation,
            $outcome,
            $reasonCode,
            $reason,
            $previousState,
            $nextState,
            $eligibilityReasons,
            $actorUserId,
            $actorReference,
            $metadata,
            $occurredAt,
            null,
            null,
            $correlationId,
            $transactionId,
        );
    }

    public function isRoot(): bool
    {
        return $this->idempotencyKey !== null;
    }

    private function validate(): void
    {
        if ($this->subjectInternalId <= 0) {
            throw new InvalidArgumentException('The lifecycle subject internal identifier must be positive.');
        }

        $this->validateCanonicalUuid($this->subjectPublicId, 'subject public identifier');

        if ($this->actorUserId !== null && $this->actorUserId <= 0) {
            throw new InvalidArgumentException('The lifecycle actor user identifier must be positive when present.');
        }

        if (trim($this->actorReference) === '' || trim($this->actorReference) !== $this->actorReference) {
            throw new InvalidArgumentException('The lifecycle actor reference must be nonblank and trimmed.');
        }

        if ($this->reason !== null && (trim($this->reason) === '' || trim($this->reason) !== $this->reason)) {
            throw new InvalidArgumentException('The lifecycle editorial reason must be nonblank and trimmed when present.');
        }

        if (! array_is_list($this->eligibilityReasons)) {
            throw new InvalidArgumentException('Lifecycle eligibility reasons must be a list.');
        }

        $seenReasons = [];
        foreach ($this->eligibilityReasons as $eligibilityReason) {
            if (! $eligibilityReason instanceof CatalogLifecycleReason) {
                throw new InvalidArgumentException('Lifecycle eligibility reasons must use CatalogLifecycleReason values.');
            }
            if (isset($seenReasons[$eligibilityReason->value])) {
                throw new InvalidArgumentException('Lifecycle eligibility reasons cannot contain duplicates.');
            }
            $seenReasons[$eligibilityReason->value] = true;
        }

        $this->validateRootPair();
        $this->validateOutcomeShape();
        $this->validateCanonicalUuid($this->correlationId, 'correlation identifier');
        $this->validateCanonicalUuid($this->transactionId, 'transaction identifier');
    }

    private function validateRootPair(): void
    {
        if (($this->idempotencyKey === null) !== ($this->commandFingerprint === null)) {
            throw new InvalidArgumentException('Lifecycle idempotency key and command fingerprint must both be present or both be null.');
        }

        if ($this->idempotencyKey !== null) {
            $this->validateCanonicalUuid($this->idempotencyKey, 'idempotency key');
        }

        if ($this->commandFingerprint !== null
            && preg_match('/^[0-9a-f]{64}$/D', $this->commandFingerprint) !== 1) {
            throw new InvalidArgumentException('The lifecycle command fingerprint must be lowercase SHA-256 hexadecimal.');
        }
    }

    private function validateOutcomeShape(): void
    {
        if ($this->outcome === CatalogLifecycleOutcome::NoOp
            && ($this->previousState === null || $this->previousState !== $this->nextState)) {
            throw new InvalidArgumentException('A lifecycle no-op requires identical nonnull states.');
        }

        if ($this->outcome === CatalogLifecycleOutcome::ValidationFailed) {
            if ($this->eligibilityReasons === [] || $this->eligibilityReasons[0] !== $this->reasonCode) {
                throw new InvalidArgumentException('A validation failure requires matching ordered eligibility reasons.');
            }
        } elseif ($this->eligibilityReasons !== []) {
            throw new InvalidArgumentException('Only validation failures may contain eligibility reasons.');
        }

        if ($this->outcome !== CatalogLifecycleOutcome::Succeeded
            && $this->previousState !== $this->nextState) {
            throw new InvalidArgumentException('An unsuccessful lifecycle event cannot change state.');
        }

        if ($this->outcome === CatalogLifecycleOutcome::Succeeded) {
            $this->validateSuccessfulStateChange();
        }
    }

    private function validateSuccessfulStateChange(): void
    {
        if ($this->nextState === null) {
            throw new InvalidArgumentException('A successful lifecycle event requires a next state.');
        }

        if ($this->previousState !== null) {
            return;
        }

        $expectedCreationState = match ($this->operation) {
            CatalogLifecycleOperation::CreateSource,
            CatalogLifecycleOperation::CreateReference => CatalogLifecycleState::Available,
            CatalogLifecycleOperation::CreateDraft => CatalogLifecycleState::Draft,
            default => null,
        };

        if ($expectedCreationState === null || $this->nextState !== $expectedCreationState) {
            throw new InvalidArgumentException('A successful creation event has inconsistent lifecycle states.');
        }
    }

    private function validateCanonicalUuid(string $uuid, string $field): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException("The lifecycle {$field} must be a canonical UUID.");
        }
    }
}
