<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class SourceArtifactChecksum
{
    public const ALGORITHM = 'sha256';

    public function __construct(
        public string $algorithm,
        public string $digest,
    ) {
        if ($algorithm !== self::ALGORITHM) {
            throw new InvalidArgumentException('The source checksum algorithm must be sha256.');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw new InvalidArgumentException('The source checksum must be a canonical lowercase SHA-256 digest.');
        }
    }

    public static function fromRawBytes(string $rawBytes): self
    {
        return new self(self::ALGORITHM, hash(self::ALGORITHM, $rawBytes));
    }

    public function assertMatchesRawBytes(string $rawBytes): void
    {
        if (! hash_equals($this->digest, hash(self::ALGORITHM, $rawBytes))) {
            throw new InvalidArgumentException('The source checksum does not match the exact raw source bytes.');
        }
    }
}
