<?php
declare(strict_types=1);
/** /agents: every way to connect an agent, with copyable commands from Agents\Setup. Vars: $catalog, $request, $urls */
use Datasets\Agents\Setup;
use Datasets\Mcp\Server as McpServer;

$setup = Setup::current();
[$curlSearch, $curlRecord] = $setup->curlExamples();

$title = 'Use with your agent';
$metaTitle = \Datasets\Seo\Meta::withSite('Use free APIs from your AI agent: skill, MCP, prompt');
$description = 'Connect Claude Code, Codex, Cursor, claude.ai or any agent to this catalogue of free REST APIs: a one-line prompt, an agent skill, a remote MCP server or curl.';
$canonical = abs_url('/agents');
$current = 'agents';
$agentPanel = 'none';
$sections = ['prompt' => 'Prompt', 'skill' => 'Skill', 'mcp' => 'MCP server', 'rest' => 'curl & API', 'updates' => 'Updates'];
$rail = static function () use ($sections): void { ?>
    <div class="rail-section">
      <h2 class="micro">On this page</h2>
      <ol><?php foreach ($sections as $id => $label): ?><li><a href="#<?= e($id) ?>"><?= e($label) ?></a></li><?php endforeach ?></ol>
    </div>
<?php };
$copyBlock = static function (string $id, string $label, string $text): void { ?>
        <div class="code">
          <div class="code-head"><span class="micro"><?= e($label) ?></span><button type="button" class="copy" data-copy="#<?= e($id) ?>" hidden>Copy</button></div>
          <pre class="wrap"><code id="<?= e($id) ?>"><?= e($text) ?></code></pre>
        </div>
<?php };

include __DIR__ . '/partials/header.php';
?>
    <article class="doc">
      <header class="d-head">
        <p class="kicker micro">For agents</p>
        <h1>Use with your agent</h1>
        <p class="lede">Let an AI agent search these <?= e($catalog->count()) ?> free APIs before it guesses one: paste a prompt, install a small skill, or connect the MCP server. All of them read the live catalogue, and none needs a key.</p>
      </header>

      <section class="section" id="prompt" aria-labelledby="h-prompt">
        <h2 id="h-prompt">A prompt</h2>
        <p>Works in any agent or chat that can fetch a web page. The agent reads the skill file and then queries the catalogue’s JSON API. Nothing to install, nothing to update.</p>
        <?php $copyBlock('g-prompt', 'Prompt', $setup->prompt()); ?>
      </section>

      <section class="section" id="skill" aria-labelledby="h-skill">
        <h2 id="h-skill">An agent skill</h2>
        <p>A skill is one Markdown file (<a href="<?= e($setup->skillUrl()) ?>">read it first</a>) that tells the agent when to use this catalogue, which filters exist and which fields of a record to check before calling an API. It holds no data, so it rarely changes; the catalogue it points to changes daily.</p>
        <?php $copyBlock('g-plugin', 'Claude Code: plugin from the GitHub marketplace', $setup->pluginInstall()); ?>
        <?php $copyBlock('g-skills', 'Codex, Cursor, Gemini CLI, GitHub Copilot, opencode and other agents', $setup->skillsAdd()); ?>
        <p class="aside">The <a href="https://github.com/vercel-labs/skills">skills CLI</a> reads this site’s discovery document, <a href="<?= e($setup->indexUrl()) ?>"><code><?= e(url('/.well-known/agent-skills/index.json')) ?></code></a>, and asks which agents to install for. It also works with the repository: <code>npx skills add <?= e(Setup::REPO) ?></code>.</p>
        <?php $copyBlock('g-manual', 'By hand, for Claude Code', $setup->manualInstall()); ?>
        <?php $copyBlock('g-manual-agents', 'By hand, for agents that read ~/.agents/skills', $setup->manualInstall('~/.agents/skills')); ?>
      </section>

      <section class="section" id="mcp" aria-labelledby="h-mcp">
        <h2 id="h-mcp">The MCP server</h2>
        <p>For chat apps without a shell (claude.ai, Claude Desktop, ChatGPT) and for editors. A remote server at <code class="url"><?= e($setup->mcpUrl()) ?></code>: read-only, no key, no sign-in, answering from the same catalogue as the API.</p>
        <?php $copyBlock('g-mcp-claude', 'Claude Code', $setup->mcpClaudeCode()); ?>
        <?php $copyBlock('g-mcp-url', 'Server URL', $setup->mcpUrl()); ?>
        <ul class="plain spaced">
          <li><strong>claude.ai and Claude Desktop:</strong> add it as a custom connector with the server URL above (Settings › Connectors), where your plan allows custom connectors.</li>
          <li><strong>ChatGPT and other clients</strong> that accept a remote MCP server: paste the server URL; no authentication.</li>
          <li><strong>Editors:</strong> <a href="<?= e($setup->cursorLink()) ?>">Add to Cursor</a> · <a href="<?= e($setup->vscodeLink()) ?>">Add to VS Code</a></li>
        </ul>
        <table class="facts">
          <tbody>
            <tr><th scope="row">Tools</th><td><code>search_datasets</code> (q, category, tag, keyless, browser, has_openapi, limit ≤ 50, offset) · <code>get_dataset</code> (slug) · <code>list_categories</code>; all read-only</td></tr>
            <tr><th scope="row">Transport</th><td>Streamable HTTP, POST only, one JSON response per request; no sessions, no streaming</td></tr>
            <tr><th scope="row">Protocol</th><td><?= e(implode(', ', McpServer::supported())) ?></td></tr>
            <tr><th scope="row">Limits</th><td><?= e(McpServer::LIMITS[0]) ?> requests per minute per client, request bodies up to 64 KB</td></tr>
          </tbody>
        </table>
      </section>

      <section class="section" id="rest" aria-labelledby="h-curl">
        <h2 id="h-curl">curl and the REST API</h2>
        <p>Every agent with a shell can use the JSON API directly: no key, CORS open, documented on the <a href="<?= e(url('/api')) ?>">API page</a> and in <a href="<?= e(url('/api/v1/openapi.json')) ?>">OpenAPI 3.1</a>; <a href="<?= e(url('/llms.txt')) ?>">llms.txt</a> is a plain-text overview.</p>
        <?php $copyBlock('g-curl-1', 'Search', $curlSearch); ?>
        <?php $copyBlock('g-curl-2', 'One record', $curlRecord); ?>
      </section>

      <section class="section" id="updates" aria-labelledby="h-updates">
        <h2 id="h-updates">Updates</h2>
        <p>The prompt, the MCP server and the API always serve the current catalogue. An installed skill is a copy of one small file; installers do not refresh it on their own, but it only describes how to query the catalogue, so it rarely needs to change. To update it:</p>
        <?php $copyBlock('g-up-plugin', 'Claude Code plugin', $setup->pluginUpdate()); ?>
        <?php $copyBlock('g-up-skills', 'skills CLI', $setup->skillsUpdate()); ?>
        <p class="aside">Installed by hand: run the same command again.</p>
      </section>
    </article>
<?php include __DIR__ . '/partials/footer.php';
