<?php

declare(strict_types=1);

namespace Datasets\Explore;

/** A request the explorer proxy will not carry out; becomes an RFC 9457 problem response. */
final class Refusal extends \RuntimeException
{
    /** @param array<string, string> $headers extra response headers, e.g. Retry-After */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        string $detail,
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }
}
