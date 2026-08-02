<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\Exceptions\ApprovedCatalogImportValidationException;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ApprovedCatalogImportExecutionInput
{
    private const ARTIFACT_OPTIONS = [
        'source',
        'expected-source-sha256',
        'manifest',
        'expected-manifest-sha256',
        'resolution',
        'expected-resolution-sha256',
        'approval',
        'expected-approval-sha256',
        'apply-plan',
        'expected-apply-plan-sha256',
    ];

    private const ACTOR_OPTIONS = [
        'actor-id',
        'actor-reference',
        'reason',
        'occurred-at',
    ];

    private function __construct(
        public string $sourcePath,
        public string $expectedSourceSha256,
        public string $manifestPath,
        public string $expectedManifestSha256,
        public string $resolutionPath,
        public string $expectedResolutionSha256,
        public string $approvalPath,
        public string $expectedApprovalSha256,
        public string $applyPlanPath,
        public string $expectedApplyPlanSha256,
        public int $actorId,
        public string $actorReference,
        public string $reason,
        public string $occurredAtInput,
        public DateTimeImmutable $occurredAt,
    ) {}

    /** @param array<string, mixed> $options */
    public static function fromCommandOptions(array $options, bool $execute): self
    {
        self::validateRequiredCommandOptions($options, $execute);

        if (preg_match('/^[1-9][0-9]*$/D', $options['actor-id']) !== 1) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ActorInvalid,
                'The --actor-id option must be a positive persisted user identifier.',
            );
        }

        $actorReference = $options['actor-reference'];

        if (
            strlen($actorReference) > 191
            || ! mb_check_encoding($actorReference, 'UTF-8')
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]*$/D', $actorReference) !== 1
        ) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ActorInvalid,
                'The --actor-reference option must use explicit identifier syntax.',
            );
        }

        $reason = $options['reason'];

        if (trim($reason) !== $reason || ! mb_check_encoding($reason, 'UTF-8')) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ActorInvalid,
                'The --reason option must be explicit, trimmed, nonblank UTF-8 text.',
            );
        }

        $occurredAtInput = $options['occurred-at'];
        $occurredAt = self::parseOccurredAt($occurredAtInput);

        return new self(
            sourcePath: $options['source'],
            expectedSourceSha256: $options['expected-source-sha256'],
            manifestPath: $options['manifest'],
            expectedManifestSha256: $options['expected-manifest-sha256'],
            resolutionPath: $options['resolution'],
            expectedResolutionSha256: $options['expected-resolution-sha256'],
            approvalPath: $options['approval'],
            expectedApprovalSha256: $options['expected-approval-sha256'],
            applyPlanPath: $options['apply-plan'],
            expectedApplyPlanSha256: $options['expected-apply-plan-sha256'],
            actorId: (int) $options['actor-id'],
            actorReference: $actorReference,
            reason: $reason,
            occurredAtInput: $occurredAtInput,
            occurredAt: $occurredAt,
        );
    }

    /** @param array<string, mixed> $options */
    public static function validateRequiredCommandOptions(array $options, bool $execute): void
    {
        if (! $execute) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ArtifactInvalid,
                'The --execute option must be explicitly present.',
            );
        }

        foreach ([...self::ARTIFACT_OPTIONS, ...self::ACTOR_OPTIONS] as $name) {
            if (! is_string($options[$name] ?? null) || trim($options[$name]) === '') {
                $outcome = in_array($name, self::ACTOR_OPTIONS, true)
                    ? ApprovedCatalogImportOutcome::ActorInvalid
                    : ApprovedCatalogImportOutcome::ArtifactInvalid;

                throw new ApprovedCatalogImportValidationException(
                    $outcome,
                    "The --{$name} option is required.",
                );
            }
        }

        foreach (self::ARTIFACT_OPTIONS as $name) {
            if (trim($options[$name]) !== $options[$name]) {
                throw new ApprovedCatalogImportValidationException(
                    ApprovedCatalogImportOutcome::ArtifactInvalid,
                    "The --{$name} option must not contain surrounding whitespace.",
                );
            }
        }

        foreach ([
            'expected-source-sha256',
            'expected-manifest-sha256',
            'expected-resolution-sha256',
            'expected-approval-sha256',
            'expected-apply-plan-sha256',
        ] as $checksumOption) {
            if (preg_match('/^[0-9a-f]{64}$/D', $options[$checksumOption]) !== 1) {
                throw new ApprovedCatalogImportValidationException(
                    ApprovedCatalogImportOutcome::ArtifactInvalid,
                    "The --{$checksumOption} option must be a lowercase SHA-256 digest.",
                );
            }
        }

    }

    private static function parseOccurredAt(string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ActorInvalid,
                'The --occurred-at option must be UTC RFC3339 with exactly six microseconds.',
            );
        }

        $occurredAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $occurredAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $occurredAt->format('Y-m-d\TH:i:s.u\Z') !== $value
        ) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ActorInvalid,
                'The --occurred-at option is not a valid UTC instant.',
            );
        }

        return $occurredAt;
    }
}
