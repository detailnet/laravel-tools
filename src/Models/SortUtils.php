<?php

namespace Detail\Laravel\Models;

use Detail\Laravel\Validators\SortIndex;
use RuntimeException;
use function array_key_exists;
use function asort;
use function current;
use function floor;
use function key;
use function next;
use function preg_match;
use function prev;
use function reset;
use function sprintf;

trait SortUtils
{
    /**
     * @param array<string, int> $indexes
     * @return int|null Null when a reindex is needed
     */
    private function extractSortIndex(string $value, array $indexes, int $delta = Model::SORT_INDEX_DEFAULT_DELTA): ?int
    {
        if (preg_match(SortIndex::DRAG_AND_DROP_POSITION_REGEX, $value, $reference) === false) {
            throw new RuntimeException('Wrong sorting string');
        }

        if (!array_key_exists($reference['uuid'], $indexes)) {
            // This happens also when trying to reposition before or after self, which has to be suppressed
            // because we have already lost the integer value (self sort index is a string at this point)
            throw new RuntimeException(
                sprintf(
                    'Failed to apply sort_index: reference model "%s" can\'t be self and has to be in the adjacent models',
                    $reference['uuid']
                )
            );
        }

        asort($indexes);

        // Check that there is a space before or after the referenced model
        reset($indexes); // Set internal pointer to first element

        // Move internal pointer to referenced model
        while (key($indexes) !== $reference['uuid']) {
            next($indexes);
        }

        switch ($reference['position']) {
            case 'before':
                $max = current($indexes);
                $min = prev($indexes) ?: 0;
                break;
            case 'after':
                $min = current($indexes);
                $max = next($indexes) ?: ($min + 2 * $delta);
                break;
            default:
                throw new RuntimeException(
                    sprintf('Failed to apply sort_index: position to reference "%s" not supported', $reference['position'])
                );
        }

        // Mean value between min and max
        $newIndex = $min + (integer) floor(($max - $min) / 2);

        return ($newIndex === $min || $newIndex === $max) ? null : $newIndex;
    }
}
