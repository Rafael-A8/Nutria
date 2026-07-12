<?php

namespace App\Nutrition\Application\Catalog\Lifecycle;

use App\Nutrition\Domain\Catalog\Lifecycle\ValueObjects\CatalogLifecycleCommand;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class CatalogLifecycleCommandFingerprint
{
    /** @throws JsonException */
    public static function forCommand(CatalogLifecycleCommand $command, string $actorReference): string
    {
        if (trim($actorReference) === '' || trim($actorReference) !== $actorReference) {
            throw new InvalidArgumentException('The actor reference must be nonblank and trimmed.');
        }

        $canonicalPayload = [
            'subject_type' => $command->subjectType->value,
            'subject_id' => $command->subjectId,
            'operation' => $command->operation->value,
            'actor_id' => $command->actorId,
            'actor_reference' => $actorReference,
            'reason' => $command->reason === null ? null : trim($command->reason),
            'idempotency_key' => $command->idempotencyKey,
            'occurred_at' => $command->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        return hash('sha256', json_encode(
            $canonicalPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
