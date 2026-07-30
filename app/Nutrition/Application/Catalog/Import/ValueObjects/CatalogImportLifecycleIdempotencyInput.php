<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleOperation;
use App\Nutrition\Domain\Catalog\Lifecycle\Enums\CatalogLifecycleSubjectType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CatalogImportLifecycleIdempotencyInput
{
    public ?string $normalizedReason;

    public function __construct(
        public CanonicalManifestChecksum $manifestChecksum,
        public CatalogLifecycleSubjectType $subjectType,
        public string $subjectPublicId,
        public CatalogLifecycleOperation $operation,
        public string $actorId,
        public string $actorReference,
        ?string $reason,
        public DateTimeImmutable $occurredAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $subjectPublicId) !== 1) {
            throw new InvalidArgumentException('The lifecycle subject public ID must be a canonical UUID.');
        }

        foreach ([$actorId, $actorReference] as $actorValue) {
            if (
                trim($actorValue) === ''
                || trim($actorValue) !== $actorValue
                || ! mb_check_encoding($actorValue, 'UTF-8')
            ) {
                throw new InvalidArgumentException('Lifecycle actor values must be nonblank, trimmed, and valid UTF-8.');
            }
        }

        if ($reason !== null && (trim($reason) === '' || ! mb_check_encoding($reason, 'UTF-8'))) {
            throw new InvalidArgumentException('A lifecycle reason must be nonblank valid UTF-8 or null.');
        }

        $this->normalizedReason = $reason === null ? null : trim($reason);
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
