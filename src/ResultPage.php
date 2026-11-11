<?php

declare(strict_types=1);

namespace Datasets;

/** One page of a filtered, sorted dataset list (see Catalog::query()). */
final class ResultPage
{
    /**
     * @param list<array<string, mixed>> $items  datasets on this page
     * @param int                        $total  matches before pagination
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function hasPrev(): bool
    {
        return $this->offset > 0;
    }

    public function hasNext(): bool
    {
        return $this->offset + $this->limit < $this->total;
    }

    public function prevOffset(): ?int
    {
        return $this->hasPrev() ? max(0, $this->offset - $this->limit) : null;
    }

    public function nextOffset(): ?int
    {
        return $this->hasNext() ? $this->offset + $this->limit : null;
    }

    /** Offset of the last page (0 when there are no results). */
    public function lastOffset(): int
    {
        return $this->total === 0 ? 0 : intdiv($this->total - 1, $this->limit) * $this->limit;
    }

    /** 1-based page number and page count, for pagers. */
    public function pageNumber(): int
    {
        return intdiv($this->offset, $this->limit) + 1;
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->limit));
    }
}
