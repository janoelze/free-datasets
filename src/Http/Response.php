<?php

declare(strict_types=1);

namespace Datasets\Http;

/** A response value: status, headers and body. Use Http::* to build one. */
final class Response
{
    /** @param array<string, string> $headers header name (canonical case) => value */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        foreach (array_keys($this->headers) as $k) {
            if (strcasecmp($k, $name) === 0) {
                unset($this->headers[$k]);
            }
        }
        $this->headers[$name] = $value;

        return $this;
    }

    public function withoutHeader(string $name): self
    {
        foreach (array_keys($this->headers) as $k) {
            if (strcasecmp($k, $name) === 0) {
                unset($this->headers[$k]);
            }
        }

        return $this;
    }

    /** Emit status, headers and (unless HEAD) the body. */
    public function send(bool $head = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            if ($this->header('Content-Type') === null) {
                ini_set('default_mimetype', ''); // no implicit text/html on 204/304
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if (!$head && $this->body !== '') {
            echo $this->body;
        }
    }
}
