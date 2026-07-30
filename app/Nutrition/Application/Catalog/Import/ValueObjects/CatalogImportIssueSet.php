<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use App\Nutrition\Application\Catalog\Import\Enums\CatalogImportIssueCode;
use InvalidArgumentException;

final readonly class CatalogImportIssueSet
{
    /** @var list<CatalogImportIssueCode> */
    private array $issues;

    /** @param list<CatalogImportIssueCode> $issues */
    public function __construct(array $issues)
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Catalog import issues must be a list.');
        }

        $caseOrder = array_flip(array_column(CatalogImportIssueCode::cases(), 'value'));
        $uniqueIssues = [];

        foreach ($issues as $issue) {
            if (! $issue instanceof CatalogImportIssueCode) {
                throw new InvalidArgumentException('Catalog import issues must use typed issue codes.');
            }

            $uniqueIssues[$issue->value] = $issue;
        }

        uasort(
            $uniqueIssues,
            fn (CatalogImportIssueCode $left, CatalogImportIssueCode $right): int => $caseOrder[$left->value] <=> $caseOrder[$right->value],
        );

        $this->issues = array_values($uniqueIssues);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function contains(CatalogImportIssueCode $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }

    /** @param list<CatalogImportIssueCode> $issues */
    public function containsAny(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($this->contains($issue)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CatalogImportIssueCode> */
    public function all(): array
    {
        return $this->issues;
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_column($this->issues, 'value');
    }
}
