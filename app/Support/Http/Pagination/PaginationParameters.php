<?php

namespace App\Support\Http\Pagination;

use Illuminate\Http\Request;

final class PaginationParameters
{
    public const DEFAULT_PAGE = 1;

    public const DEFAULT_PER_PAGE = 500;

    public const MAX_PER_PAGE = 1000;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?int $offset,
    ) {}

    public static function fromRequest(Request $request, int $rowCeiling): ?self
    {
        $query = $request->query();

        if (! array_key_exists('page', $query) && ! array_key_exists('per_page', $query)) {
            return null;
        }

        $queryString = $request->server('QUERY_STRING') ?? $request->getQueryString();

        if (self::hasDuplicatePaginationKey($queryString)) {
            throw new InvalidPaginationParametersException;
        }

        $page = array_key_exists('page', $query)
            ? self::positiveInteger($query['page'])
            : self::DEFAULT_PAGE;
        $perPage = array_key_exists('per_page', $query)
            ? self::positiveInteger($query['per_page'])
            : self::DEFAULT_PER_PAGE;

        if ($page === null || $perPage === null || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidPaginationParametersException;
        }

        $rowCeiling = max(1, $rowCeiling);
        $lastPage = intdiv($rowCeiling - 1, $perPage) + 1;

        return new self(
            page: $page,
            perPage: $perPage,
            offset: $page > $lastPage ? null : ($page - 1) * $perPage,
        );
    }

    public function isPastCeiling(): bool
    {
        return $this->offset === null;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($integer) ? $integer : null;
    }

    private static function hasDuplicatePaginationKey(?string $queryString): bool
    {
        if ($queryString === null || $queryString === '') {
            return false;
        }

        $seen = [];

        foreach (explode('&', $queryString) as $part) {
            [$rawKey] = explode('=', $part, 2);
            $key = urldecode($rawKey);

            if (! in_array($key, ['page', 'per_page'], true)) {
                continue;
            }

            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }
}
