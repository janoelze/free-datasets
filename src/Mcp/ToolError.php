<?php

declare(strict_types=1);

namespace Datasets\Mcp;

/** Invalid tool arguments or an unknown slug: reported as a tool result with isError: true. */
final class ToolError extends \RuntimeException
{
}
