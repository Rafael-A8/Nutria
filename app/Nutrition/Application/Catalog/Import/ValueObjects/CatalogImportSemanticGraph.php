<?php

namespace App\Nutrition\Application\Catalog\Import\ValueObjects;

use InvalidArgumentException;

final readonly class CatalogImportSemanticGraph
{
    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $version
     * @param  array<string, mixed>  $sourceLink
     * @param  list<array<string, mixed>>  $aliases
     * @param  array<string, mixed>  $initialLifecycleStates
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public array $source,
        public array $reference,
        public array $version,
        public array $sourceLink,
        public array $aliases,
        public array $initialLifecycleStates,
        public array $provenance,
    ) {
        foreach ([$source, $reference, $version, $sourceLink, $initialLifecycleStates, $provenance] as $component) {
            if ($component === [] || array_is_list($component)) {
                throw new InvalidArgumentException('Semantic graph components must be nonempty objects.');
            }
        }

        if (! array_is_list($aliases)) {
            throw new InvalidArgumentException('Semantic graph aliases must be a list.');
        }

        foreach ($aliases as $alias) {
            if (
                ! is_array($alias)
                || array_is_list($alias)
                || ! isset($alias['lineage_id'], $alias['public_id'])
                || ! is_string($alias['lineage_id'])
                || ! is_string($alias['public_id'])
            ) {
                throw new InvalidArgumentException('Every semantic alias requires lineage_id and public_id.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toCanonicalPayload(): array
    {
        return [
            'aliases' => $this->aliases,
            'initial_lifecycle_states' => $this->initialLifecycleStates,
            'provenance' => $this->provenance,
            'reference' => $this->reference,
            'source' => $this->source,
            'source_link' => $this->sourceLink,
            'version' => $this->version,
        ];
    }
}
