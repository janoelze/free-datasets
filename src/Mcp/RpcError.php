<?php

declare(strict_types=1);

namespace Datasets\Mcp;

/** A JSON-RPC error response with its HTTP status (Server turns it into the response). */
final class RpcError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    /** MCP 2026-07-28: HTTP headers disagree with the body, or a required header is missing. */
    public const HEADER_MISMATCH = -32020;
    /** MCP 2026-07-28: UnsupportedProtocolVersionError, data {supported, requested}. */
    public const UNSUPPORTED_VERSION = -32022;
    /** Application-defined (outside the JSON-RPC reserved range): rate limited, see Retry-After. */
    public const RATE_LIMITED = -31429;

    /** @param array<string, string> $headers extra HTTP response headers */
    public function __construct(
        public readonly int $status,
        public readonly int $rpcCode,
        string $message,
        public readonly mixed $data = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
