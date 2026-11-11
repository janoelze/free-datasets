<?php

declare(strict_types=1);

namespace Datasets\Schema;

/** One validation failure, located by an RFC 6901 JSON pointer ("" = document root). */
final class ValidationError
{
    public function __construct(
        public readonly string $pointer,
        public readonly string $keyword,
        public readonly string $message,
    ) {
    }

    /** Pointer for display: the root is shown as "/". */
    public function displayPointer(): string
    {
        return $this->pointer === '' ? '/' : $this->pointer;
    }

    public function __toString(): string
    {
        return $this->displayPointer() . ': ' . $this->message;
    }

    /** @return array{pointer: string, keyword: string, message: string} */
    public function toArray(): array
    {
        return ['pointer' => $this->pointer, 'keyword' => $this->keyword, 'message' => $this->message];
    }

    /** Append an escaped reference token to a pointer. */
    public static function child(string $pointer, string|int $token): string
    {
        return $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $token);
    }
}
