<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CanonicalManifestChecksum
{
    public const ALGORITHM = 'sha256';

    public function __construct(
        public string $algorithm,
        public string $digest,
    ) {
        if ($algorithm !== self::ALGORITHM) {
            throw new InvalidArgumentException('The manifest checksum algorithm must be sha256.');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw new InvalidArgumentException('The manifest checksum must be a canonical lowercase SHA-256 digest.');
        }
    }

    public static function fromCanonicalBytes(string $canonicalBytes): self
    {
        self::assertUtf8($canonicalBytes);

        return new self(self::ALGORITHM, hash(self::ALGORITHM, $canonicalBytes));
    }

    public function assertMatchesCanonicalBytes(string $canonicalBytes): void
    {
        self::assertUtf8($canonicalBytes);

        if (! hash_equals($this->digest, hash(self::ALGORITHM, $canonicalBytes))) {
            throw new InvalidArgumentException('The manifest checksum does not match the exact canonical UTF-8 bytes.');
        }
    }

    private static function assertUtf8(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Canonical manifest bytes must be valid UTF-8.');
        }
    }
}
