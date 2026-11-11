<?php

declare(strict_types=1);

namespace Datasets\Agents;

use Datasets\Url;
use Datasets\Web\View;

/**
 * Every command and URL shown to visitors under "Use with your agent" (header panel, /agents,
 * README), built in one place from the public URL so the snippets stay base-path aware and
 * cannot drift between the panel and the page. tests/AgentsTest.php checks that the README
 * shows the same commands for the production URL.
 */
final class Setup
{
    public const REPO = 'https://github.com/janoelze/free-datasets';

    /** Claude Code plugin id: <plugin>@<marketplace> (.claude-plugin/marketplace.json). */
    public const PLUGIN_ID = 'free-datasets@free-datasets';

    public const MCP_NAME = 'free-datasets';

    public function __construct(private readonly Url $url)
    {
    }

    public static function current(): self
    {
        return new self(Url::current());
    }

    /** App root without trailing slash, e.g. https://endtime-instruments.org/free-datasets */
    public function site(): string
    {
        return rtrim($this->url->absolute('/'), '/');
    }

    public function skillUrl(): string
    {
        return $this->url->absolute(Skill::SKILL_PATH);
    }

    public function indexUrl(): string
    {
        return $this->url->absolute(Skill::INDEX_PATH);
    }

    public function mcpUrl(): string
    {
        return $this->url->absolute('/mcp');
    }

    public function prompt(): string
    {
        return 'Read ' . $this->skillUrl() . ' and follow it whenever I need a free API or dataset: '
            . 'search that live catalogue first, then call the API it recommends.';
    }

    public function pluginInstall(): string
    {
        return 'claude plugin install free-datasets --marketplace ' . self::REPO;
    }

    public function pluginUpdate(): string
    {
        return 'claude plugin update ' . self::PLUGIN_ID;
    }

    public function skillsAdd(): string
    {
        return 'npx skills add ' . $this->site();
    }

    public function skillsUpdate(): string
    {
        return 'npx skills update';
    }

    /** Manual install for Claude Code (~/.claude/skills) or most other agents (~/.agents/skills). */
    public function manualInstall(string $dir = '~/.claude/skills'): string
    {
        $target = $dir . '/' . Skill::NAME;

        return 'mkdir -p ' . $target . ' && curl -fsSL ' . View::shellQuote($this->skillUrl()) . ' -o ' . $target . '/SKILL.md';
    }

    public function mcpClaudeCode(): string
    {
        return 'claude mcp add --transport http ' . self::MCP_NAME . ' ' . $this->mcpUrl();
    }

    /** cursor:// deeplink; config is the mcp.json server entry, base64-encoded. */
    public function cursorLink(): string
    {
        $config = base64_encode((string) json_encode(['url' => $this->mcpUrl()], JSON_UNESCAPED_SLASHES));

        return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode(self::MCP_NAME) . '&config=' . rawurlencode($config);
    }

    public function vscodeLink(): string
    {
        $config = (string) json_encode(['type' => 'http', 'url' => $this->mcpUrl()], JSON_UNESCAPED_SLASHES);

        return 'https://vscode.dev/redirect/mcp/install?name=' . rawurlencode(self::MCP_NAME) . '&config=' . rawurlencode($config);
    }

    /** @return array{0: string, 1: string} two example API calls */
    public function curlExamples(): array
    {
        return [
            'curl ' . View::shellQuote($this->url->absolute('/api/v1/datasets', ['q' => 'earthquake', 'keyless' => true, 'fields' => 'slug,name,summary'])),
            'curl ' . View::shellQuote($this->url->absolute('/api/v1/datasets/usgs-earthquakes')),
        ];
    }
}
