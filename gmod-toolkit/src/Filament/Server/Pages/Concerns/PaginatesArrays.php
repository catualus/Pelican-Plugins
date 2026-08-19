<?php

namespace Catualus\GmodToolkit\Filament\Server\Pages\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Search, sort and pagination for tables whose rows come from the daemon rather than
 * from a database.
 *
 * Filament hands an array-backed table the current search term, sort and page and
 * expects the data source to have applied them - there is no query to push them
 * into. This does that once, so five pages do not each grow their own slightly
 * different version of it.
 */
trait PaginatesArrays
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $searchable  keys whose values the search box matches against
     * @param  array<string, callable(array<string, mixed>): mixed>  $sorts  column name => value to sort on
     */
    protected function arrayRecords(
        array $rows,
        ?string $search,
        ?string $sortColumn,
        ?string $sortDirection,
        int|string $page,
        int|string $recordsPerPage,
        array $searchable = [],
        array $sorts = [],
    ): LengthAwarePaginator|Collection {
        $rows = $this->applySearch($rows, $search, $searchable);
        $rows = $this->applySort($rows, $sortColumn, $sortDirection, $sorts);

        // "All" is a real option in Filament's page-size menu, and paginating it
        // would be a contradiction.
        if ($recordsPerPage === 'all' || (int) $recordsPerPage <= 0) {
            return collect($rows);
        }

        $perPage = (int) $recordsPerPage;
        $page = max(1, (int) $page);

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            count($rows),
            $perPage,
            $page,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $searchable
     * @return list<array<string, mixed>>
     */
    private function applySearch(array $rows, ?string $search, array $searchable): array
    {
        $needle = mb_strtolower(trim((string) $search));

        if ($needle === '' || $searchable === []) {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($needle, $searchable): bool {
            foreach ($searchable as $key) {
                $value = $row[$key] ?? null;

                if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, callable(array<string, mixed>): mixed>  $sorts
     * @return list<array<string, mixed>>
     */
    private function applySort(array $rows, ?string $sortColumn, ?string $sortDirection, array $sorts): array
    {
        // No sort chosen means the caller's own ordering stands, which is usually
        // more useful than alphabetical - these lists arrive sorted by severity.
        if ($sortColumn === null) {
            return $rows;
        }

        $resolve = $sorts[$sortColumn] ?? static fn (array $row): mixed => $row[$sortColumn] ?? null;
        $descending = $sortDirection === 'desc';

        usort($rows, static function (array $a, array $b) use ($resolve, $descending): int {
            $left = $resolve($a);
            $right = $resolve($b);

            // Rows with no value for the column sort last either way, rather than
            // clustering at whichever end null happens to compare to.
            if ($left === null || $right === null) {
                return ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
            }

            $comparison = is_string($left) && is_string($right)
                ? strcasecmp($left, $right)
                : $left <=> $right;

            return $descending ? -$comparison : $comparison;
        });

        return $rows;
    }
}
