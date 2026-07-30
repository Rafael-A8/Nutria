<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportPlanningException;

final class LegacyNutritionCommitReader
{
    public function resolve(string $repositoryRoot): string
    {
        $gitDirectory = $this->gitDirectory($repositoryRoot);
        $head = $this->readTrimmed($gitDirectory.DIRECTORY_SEPARATOR.'HEAD');

        if ($this->isCommit($head)) {
            return $head;
        }

        if (! str_starts_with($head, 'ref: ')) {
            throw new LegacyNutritionImportPlanningException('The repository HEAD does not identify a supported commit.');
        }

        $reference = substr($head, 5);
        $looseReference = $this->readTrimmed($gitDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $reference), false);

        if ($looseReference !== null && $this->isCommit($looseReference)) {
            return $looseReference;
        }

        $packedReferences = $this->readTrimmed($gitDirectory.DIRECTORY_SEPARATOR.'packed-refs', false);

        if ($packedReferences !== null) {
            foreach (preg_split('/\R/', $packedReferences) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '^')) {
                    continue;
                }

                [$commit, $packedReference] = array_pad(explode(' ', $line, 2), 2, null);

                if ($packedReference === $reference && is_string($commit) && $this->isCommit($commit)) {
                    return $commit;
                }
            }
        }

        throw new LegacyNutritionImportPlanningException('The repository commit could not be resolved.');
    }

    private function gitDirectory(string $repositoryRoot): string
    {
        $gitPath = rtrim($repositoryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git';

        if (is_dir($gitPath)) {
            $canonicalPath = realpath($gitPath);

            if ($canonicalPath !== false) {
                return $canonicalPath;
            }
        }

        $gitFile = $this->readTrimmed($gitPath, false);

        if ($gitFile !== null && str_starts_with($gitFile, 'gitdir: ')) {
            $referencedPath = substr($gitFile, 8);
            $absolutePath = str_starts_with($referencedPath, DIRECTORY_SEPARATOR)
                ? $referencedPath
                : dirname($gitPath).DIRECTORY_SEPARATOR.$referencedPath;
            $canonicalPath = realpath($absolutePath);

            if ($canonicalPath !== false && is_dir($canonicalPath)) {
                return $canonicalPath;
            }
        }

        throw new LegacyNutritionImportPlanningException('The Git repository metadata is unavailable.');
    }

    private function readTrimmed(string $path, bool $required = true): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            if ($required) {
                throw new LegacyNutritionImportPlanningException('Required Git repository metadata is unreadable.');
            }

            return null;
        }

        return trim($contents);
    }

    private function isCommit(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/D', $value) === 1;
    }
}
