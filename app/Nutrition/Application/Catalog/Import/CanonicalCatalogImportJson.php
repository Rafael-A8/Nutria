<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportManifestSchema;
use InvalidArgumentException;
use JsonException;

final class CanonicalCatalogImportJson
{
    /**
     * Canonical rules:
     * - object keys use ascending UTF-8 byte order;
     * - records use ascending source_record_key byte order;
     * - issue_codes/issues use CatalogImportIssueCode declaration order;
     * - aliases are semantic sets ordered by their canonical JSON bytes;
     * - every other list is semantically ordered and preserves input order;
     * - integers and finite decimals use PHP JSON numeric representation;
     * - Unicode and slashes remain unescaped, and no whitespace is emitted.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws JsonException
     */
    public static function serializeManifest(array $manifest): string
    {
        if (($manifest['schema'] ?? null) !== CatalogImportManifestSchema::IDENTIFIER) {
            throw new InvalidArgumentException('The canonical manifest must use the supported schema.');
        }

        if (self::containsKey($manifest, 'manifest_checksum')) {
            throw new InvalidArgumentException('The manifest checksum must be outside its own canonical payload.');
        }

        self::assertNoExecutionData($manifest);

        if (array_key_exists('records', $manifest)) {
            $manifest['records'] = self::sortRecords($manifest['records']);
        }

        return self::encode(self::canonicalize($manifest));
    }

    /**
     * @param  array<string, mixed>  $graph
     *
     * @throws JsonException
     */
    public static function serializeSemanticGraph(array $graph): string
    {
        self::assertNoExecutionData($graph);

        return self::encode(self::canonicalize($graph));
    }

    private static function canonicalize(mixed $value, ?string $parentKey = null): mixed
    {
        if (is_string($value)) {
            self::assertUtf8($value);

            return $value;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Canonical catalog import decimals must be finite.');
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Canonical catalog import values must be JSON-compatible scalars or arrays.');
        }

        if (array_is_list($value)) {
            $canonicalList = array_map(
                fn (mixed $item): mixed => self::canonicalize($item),
                $value,
            );

            if (in_array($parentKey, ['issue_codes', 'issues'], true)) {
                return self::sortIssueCodes($canonicalList);
            }

            if ($parentKey === 'aliases') {
                usort(
                    $canonicalList,
                    fn (mixed $left, mixed $right): int => strcmp(self::encode($left), self::encode($right)),
                );
            }

            return $canonicalList;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Canonical catalog import object keys must be strings.');
            }

            self::assertUtf8($key);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item, $key);
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private static function sortRecords(mixed $records): array
    {
        if (! is_array($records) || ! array_is_list($records)) {
            throw new InvalidArgumentException('Manifest records must be a list.');
        }

        $recordKeys = [];

        foreach ($records as $record) {
            if (
                ! is_array($record)
                || array_is_list($record)
                || ! isset($record['source_record_key'])
                || ! is_string($record['source_record_key'])
                || $record['source_record_key'] === ''
            ) {
                throw new InvalidArgumentException('Every manifest record requires a nonblank source_record_key.');
            }

            self::assertUtf8($record['source_record_key']);

            if (isset($recordKeys[$record['source_record_key']])) {
                throw new InvalidArgumentException('Manifest source record keys must be unique.');
            }

            $recordKeys[$record['source_record_key']] = true;
        }

        usort(
            $records,
            fn (array $left, array $right): int => strcmp($left['source_record_key'], $right['source_record_key']),
        );

        return $records;
    }

    /** @param list<mixed> $issues
     * @return list<string>
     */
    private static function sortIssueCodes(array $issues): array
    {
        $order = array_flip(array_column(CatalogImportIssueCode::cases(), 'value'));
        $uniqueIssues = [];

        foreach ($issues as $issue) {
            if (! is_string($issue) || ! isset($order[$issue])) {
                throw new InvalidArgumentException('Canonical issue lists must contain supported issue-code strings.');
            }

            if (isset($uniqueIssues[$issue])) {
                throw new InvalidArgumentException('Canonical issue lists cannot contain duplicates.');
            }

            $uniqueIssues[$issue] = true;
        }

        $issues = array_keys($uniqueIssues);
        usort($issues, fn (string $left, string $right): int => $order[$left] <=> $order[$right]);

        return $issues;
    }

    /** @param array<mixed> $value */
    private static function containsKey(array $value, string $searchedKey): bool
    {
        foreach ($value as $key => $item) {
            if ($key === $searchedKey) {
                return true;
            }

            if (is_array($item) && self::containsKey($item, $searchedKey)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $value */
    private static function assertNoExecutionData(array $value): void
    {
        foreach ([
            'absolute_path',
            'actor_id',
            'actor_reference',
            'created_at',
            'database_id',
            'event_public_id',
            'executed_at',
            'generated_at',
            'internal_id',
            'machine_path',
            'occurred_at',
            'updated_at',
        ] as $forbiddenKey) {
            if (self::containsKey($value, $forbiddenKey)) {
                throw new InvalidArgumentException('Execution-specific data cannot participate in canonical import semantics.');
            }
        }
    }

    private static function assertUtf8(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Canonical catalog import text must be valid UTF-8.');
        }
    }

    /** @throws JsonException */
    private static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        );
    }
}
