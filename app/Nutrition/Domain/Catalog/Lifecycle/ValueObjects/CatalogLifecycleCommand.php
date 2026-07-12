<?php

namespace App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CatalogLifecycleCommand
{
    public function __construct(
        public CatalogLifecycleSubjectType $subjectType,
        public string $subjectId,
        public CatalogLifecycleOperation $operation,
        public string $actorId,
        public ?string $reason,
        public string $idempotencyKey,
        public DateTimeImmutable $occurredAt,
    ) {
        if (trim($this->subjectId) === '') {
            throw new InvalidArgumentException('A lifecycle subject identifier cannot be blank.');
        }

        if (trim($this->actorId) === '') {
            throw new InvalidArgumentException('A lifecycle actor identifier cannot be blank.');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $this->idempotencyKey) !== 1) {
            throw new InvalidArgumentException('A lifecycle idempotency key must be a canonical UUID.');
        }

        if ($this->reason !== null && ($this->reason === '' || trim($this->reason) !== $this->reason)) {
            throw new InvalidArgumentException('A lifecycle reason must be nonblank and trimmed.');
        }

        if ($this->requiresReason() && $this->reason === null) {
            throw new InvalidArgumentException('This lifecycle operation requires a reason.');
        }
    }

    private function requiresReason(): bool
    {
        return in_array($this->operation, [
            CatalogLifecycleOperation::ReturnToDraft,
            CatalogLifecycleOperation::Approve,
            CatalogLifecycleOperation::Reject,
            CatalogLifecycleOperation::Publish,
            CatalogLifecycleOperation::Activate,
            CatalogLifecycleOperation::Reactivate,
            CatalogLifecycleOperation::Deactivate,
            CatalogLifecycleOperation::Withdraw,
            CatalogLifecycleOperation::Archive,
            CatalogLifecycleOperation::CreateSuccessor,
            CatalogLifecycleOperation::ChangeAuthority,
        ], true);
    }
}
