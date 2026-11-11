<?php

declare(strict_types=1);

/*
 * "Use with your agent": the skill file and its plugin/marketplace manifests, the agent-skills
 * discovery routes, the header panel and /agents page, README/llms.txt/deploy consistency.
 * Uses server() / prefixed_server() from HttpTest.php.
 */

use Datasets\Agents\Setup;
use Datasets\Agents\Skill;
use Datasets\Url;

/**
 * sha256 of SKILL.md per plugin.json version. Installed Claude Code plugins only update when the
 * version changes, so editing the skill without bumping the version would never reach users:
 * after changing SKILL.md, bump plugins/free-datasets/.claude-plugin/plugin.json and add a line.
 */
const SKILL_RELEASES = [
    '1.0.0' => '2e324af64db0b25846efce06b09b8890b577248eb9d97049b44bb80d6dcbcac2',
];

function repo_file(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
}

function prod_setup(): Setup
{
    return new Setup(new Url('/free-datasets', 'https://endtime-instruments.org/free-datasets'));
}

test('agents: discovery index lists the one skill with the digest of the served file', function () {
    foreach ([[server()->base, ''], [prefixed_server()->base, '/free-datasets']] as [$host, $prefix]) {
        $r = http('GET', "$host$prefix/.well-known/agent-skills/index.json");
        assert_same(200, $r['status'], "index $prefix");
        assert_api_headers($r);
        assert_same('noindex', $r['headers']['x-robots-tag'] ?? null);
        $j = $r['json'];
        assert_same('https://schemas.agentskills.io/discovery/0.2.0/schema.json', $j['$schema']);
        assert_same(1, count($j['skills']));
        $s = $j['skills'][0];
        assert_same(['name', 'type', 'description', 'url', 'digest'], array_keys($s));
        assert_same(['free-datasets', 'skill-md', 'free-datasets/SKILL.md'], [$s['name'], $s['type'], $s['url']]);
        assert_true(str_starts_with($s['description'], 'Find a free public REST API'), 'description from the front matter');
        // url is relative to the index document
        $md = http('GET', "$host$prefix/.well-known/agent-skills/" . $s['url']);
        assert_same(200, $md['status']);
        assert_same('sha256:' . hash('sha256', $md['body']), $s['digest'], 'digest = sha256 of the served bytes');
        assert_same(repo_file(Skill::FILE), $md['body'], 'served from the single source file');
    }
});

test('agents: SKILL.md is markdown, CORS-open, cacheable, noindex, conditional', function () {
    $r = get('/.well-known/agent-skills/free-datasets/SKILL.md');
    assert_same(200, $r['status']);
    assert_api_headers($r, 'text/markdown; charset=utf-8');
    assert_same('noindex', $r['headers']['x-robots-tag'] ?? null);
    assert_same(304, get('/.well-known/agent-skills/free-datasets/SKILL.md', ['If-None-Match: ' . $r['headers']['etag']])['status']);
    $h = get('/.well-known/agent-skills/free-datasets/SKILL.md', [], 'HEAD');
    assert_same(200, $h['status']);
    assert_same('', $h['body']);
    $o = get('/.well-known/agent-skills/index.json', ['Origin: https://example.com', 'Access-Control-Request-Method: GET'], 'OPTIONS');
    assert_same(204, $o['status']);
    assert_same('*', $o['headers']['access-control-allow-origin'] ?? null);
    assert_same(405, get('/.well-known/agent-skills/index.json', [], 'POST')['status']);
    assert_same(404, get('/.well-known/agent-skills/other/SKILL.md')['status']);
    assert_same(404, get('/.well-known/other')['status']);
});

test('agents: the skill follows the Agent Skills format and stays thin', function () {
    $md = repo_file(Skill::FILE);
    assert_true(preg_match('/\A---\n(.*?)\n---\n/s', $md, $m) === 1, 'front matter');
    preg_match_all('/^([a-z-]+):/m', $m[1], $keys);
    $allowed = ['name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools'];
    assert_same([], array_values(array_diff($keys[1], $allowed)), 'only spec-standard top-level fields');
    $skill = new Skill(dirname(__DIR__) . '/' . Skill::FILE);
    $fm = $skill->frontMatter();
    assert_same('free-datasets', $fm['name']);
    assert_same('free-datasets', basename(dirname(Skill::FILE)), 'name = directory');
    assert_true(mb_strlen($fm['description']) <= 1024 && mb_strlen($fm['compatibility']) <= 500, 'field lengths');
    assert_true(substr_count($md, "\n") <= 85, 'thin: at most ~80 lines');
    assert_contains('API=https://endtime-instruments.org/free-datasets/api/v1', $md);
    assert_false(str_contains($md, 'endtime-instruments.org/datasets'), 'no pre-rename URLs');
    assert_false(str_contains($md, 'janoelze/datasets'), 'no pre-rename repo');
    // The maintainer skill must not be offered by installers (npx skills skips metadata.internal).
    assert_contains("metadata:\n  internal: \"true\"\n---", repo_file('.claude/skills/add-datasets/SKILL.md'));
});

test('agents: plugin and marketplace manifests agree; version bumped with the skill', function () {
    $plugin = json_decode(repo_file('plugins/free-datasets/.claude-plugin/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $market = json_decode(repo_file('.claude-plugin/marketplace.json'), true, 512, JSON_THROW_ON_ERROR);
    assert_same('free-datasets', $plugin['name']);
    assert_same('free-datasets', $market['name']);
    assert_same(Setup::PLUGIN_ID, $plugin['name'] . '@' . $market['name']);
    assert_same(1, count($market['plugins']));
    assert_same('free-datasets', $market['plugins'][0]['name']);
    assert_true(is_dir(dirname(__DIR__) . '/' . $market['plugins'][0]['source'] . '/skills/free-datasets'), 'source holds the skill');
    assert_same(Setup::REPO, $plugin['repository']);
    assert_same('https://endtime-instruments.org/free-datasets/', $plugin['homepage']);
    $hash = hash('sha256', repo_file(Skill::FILE));
    assert_same($hash, SKILL_RELEASES[$plugin['version']] ?? null,
        'SKILL.md changed: bump plugin.json "version" and record the new hash in SKILL_RELEASES');
});

test('agents: visitor commands for production, and README / llms.txt show the same', function () {
    $s = prod_setup();
    $skill = 'https://endtime-instruments.org/free-datasets/.well-known/agent-skills/free-datasets/SKILL.md';
    assert_same($skill, $s->skillUrl());
    assert_same('claude plugin install free-datasets --marketplace https://github.com/janoelze/free-datasets', $s->pluginInstall());
    assert_same('claude plugin update free-datasets@free-datasets', $s->pluginUpdate());
    assert_same('npx skills add https://endtime-instruments.org/free-datasets', $s->skillsAdd());
    assert_same('claude mcp add --transport http free-datasets https://endtime-instruments.org/free-datasets/mcp', $s->mcpClaudeCode());
    assert_same("mkdir -p ~/.claude/skills/free-datasets && curl -fsSL $skill -o ~/.claude/skills/free-datasets/SKILL.md", $s->manualInstall());
    assert_true(str_starts_with($s->prompt(), "Read $skill and follow it"));
    $cfg = [];
    parse_str((string) parse_url($s->cursorLink(), PHP_URL_QUERY), $cfg);
    assert_same(['url' => 'https://endtime-instruments.org/free-datasets/mcp'], json_decode(base64_decode($cfg['config']), true));
    parse_str((string) parse_url($s->vscodeLink(), PHP_URL_QUERY), $cfg);
    assert_same(['type' => 'http', 'url' => 'https://endtime-instruments.org/free-datasets/mcp'], json_decode($cfg['config'], true));

    $readme = repo_file('README.md');
    foreach ([$s->prompt(), $s->pluginInstall(), $s->pluginUpdate(), $s->skillsAdd(), $s->skillsUpdate(), $s->manualInstall(), $s->mcpClaudeCode(), $s->mcpUrl()] as $cmd) {
        assert_contains($cmd, $readme, 'README');
    }
    $base = server()->base;
    $llms = get('/llms.txt')['body'];
    assert_contains("- Agent skill (how to use this catalogue): $base/.well-known/agent-skills/free-datasets/SKILL.md", $llms);
    assert_contains("- MCP server (remote, read-only, no key): $base/mcp", $llms);
});

test('agents: header panel on every page, all commands as plain text, base-path aware', function () {
    foreach (['/' => 'masthead', '/d/open-meteo' => 'corner', '/api' => 'corner', '/c/weather-climate' => 'corner', '/no/such/page' => 'corner'] as $path => $variant) {
        $b = get($path)['body'];
        assert_same(1, substr_count($b, '<details class="agent'), "one panel on $path");
        assert_contains('<details class="agent agent-' . $variant . '" id="agent" data-agent>', $b, $path);
        foreach (['prompt', 'skill', 'mcp', 'curl'] as $tab) {
            assert_contains('<input type="radio" name="agent-tab" id="at-' . $tab . '"', $b, "$tab tab on $path");
            assert_contains('data-pane="' . $tab . '"', $b);
        }
    }
    $home = get('/')['body'];
    assert_false(str_contains($home, '<p class="agents">'), 'the old "For agents" masthead line is merged into the panel');
    $base = server()->base;
    $s = new Setup(new Url('', $base));
    foreach ([$s->prompt(), $s->pluginInstall(), $s->skillsAdd(), $s->manualInstall(), $s->mcpClaudeCode(), $s->mcpUrl(), ...$s->curlExamples()] as $cmd) {
        assert_contains('>' . e($cmd) . '</code>', $home, 'snippet visible without JS');
    }
    assert_contains('data-copy="#ac-prompt"', $home, 'copy button wired to the snippet');
    preg_match_all('/\bid="([^"]+)"/', $home, $ids);
    assert_same(count($ids[1]), count(array_unique($ids[1])), 'no duplicate ids');

    // Prefixed mount: links and commands carry /free-datasets.
    $p = prefixed_server()->base;
    $b = http('GET', "$p/free-datasets/d/open-meteo")['body'];
    assert_contains("$p/free-datasets/.well-known/agent-skills/free-datasets/SKILL.md", $b);
    assert_contains("npx skills add $p/free-datasets<", $b);
    assert_contains("$p/free-datasets/mcp", $b);
    assert_contains('href="/free-datasets/agents"', $b);
});

test('agents: /agents page is indexable, self-canonical and has no second panel', function () {
    $r = get('/agents');
    assert_same(200, $r['status']);
    $b = $r['body'];
    assert_false(str_contains($b, '<details class="agent'), 'the page is the panel, expanded');
    $h = seo_head($b);
    assert_same(server()->base . '/agents', $h['canonical']);
    assert_same(null, $h['robots']);
    $s = new Setup(new Url('', server()->base));
    foreach ([$s->prompt(), $s->pluginInstall(), $s->pluginUpdate(), $s->skillsAdd(), $s->skillsUpdate(), $s->mcpClaudeCode(), $s->mcpUrl()] as $cmd) {
        assert_contains('>' . e($cmd) . '</code>', $b);
    }
    assert_contains('aria-current="page">setup</a>', $b, 'rail link marked current');
    assert_same(301, get('/Agents')['status']);
    assert_same('/free-datasets/agents', http('GET', prefixed_server()->base . '/free-datasets/AGENTS')['headers']['location'] ?? null);
});

test('agents: deploy ships plugins/ and smoke-tests discovery and MCP; .htaccess lets .well-known through', function () {
    $deploy = repo_file('bin/deploy.sh');
    assert_true(preg_match('/^\s*public src templates datasets schema bin docs deploy plugins \\\\$/m', $deploy) === 1, 'rsync list includes plugins');
    assert_contains('/.well-known/agent-skills/index.json', $deploy);
    assert_contains('Mcp-Method: tools/list', $deploy);
    $ht = repo_file('public/.htaccess');
    assert_true(preg_match('/<FilesMatch "(.+)">\s*\n\s*Require all denied/', $ht, $m) === 1, 'dotfile rule present');
    $re = '/' . str_replace('/', '\/', $m[1]) . '/';
    foreach (['.htaccess', '.git', '.env', '.well-knownx', '.well'] as $denied) {
        assert_same(1, preg_match($re, $denied), "$denied denied");
    }
    assert_same(0, preg_match($re, '.well-known'), '.well-known reaches the app');
    assert_same(0, preg_match($re, 'index.php'));
});
