<?php
declare(strict_types=1);
/**
 * "Use with your agent": a <details> disclosure with four CSS-only tabs (radio inputs), each a
 * set of copyable snippets from Agents\Setup. Works without JavaScript (details + :checked);
 * app.js only adds the Copy buttons and Escape-to-close. Rendered once per page: at the top of
 * <main> by partials/header.php, or in the home masthead (home.php sets $agentPanel = 'masthead').
 *
 * Vars: $agentPanel ('masthead' | 'corner')
 */
use Datasets\Agents\Setup;

$setup = Setup::current();
$variant = $agentPanel ?? 'corner';
[$curlSearch, $curlRecord] = $setup->curlExamples();
$tabs = ['prompt' => 'Prompt', 'skill' => 'Skill', 'mcp' => 'MCP', 'curl' => 'curl'];
$snippet = static function (string $id, string $label, string $text): void { ?>
          <div class="code">
            <div class="code-head"><span class="micro"><?= e($label) ?></span><button type="button" class="copy" data-copy="#<?= e($id) ?>" hidden>Copy</button></div>
            <pre class="wrap"><code id="<?= e($id) ?>"><?= e($text) ?></code></pre>
          </div>
<?php };
?>
<details class="agent agent-<?= e($variant) ?>" id="agent" data-agent>
  <summary><span class="agent-button">Use with your agent</span><?php if ($variant === 'masthead'): ?><span class="agent-hint">A prompt, a skill, an MCP server or plain curl. No key, CORS open.</span><?php endif ?></summary>
  <div class="agent-body">
    <fieldset class="agent-tabs">
      <legend class="sr">Choose how to connect your agent</legend>
      <?php foreach ($tabs as $key => $label): ?>
        <input type="radio" name="agent-tab" id="at-<?= e($key) ?>" value="<?= e($key) ?>"<?= $key === 'prompt' ? ' checked' : '' ?>><label for="at-<?= e($key) ?>"><?= e($label) ?></label>
      <?php endforeach ?>
      <div class="agent-panes">
        <section class="agent-pane" data-pane="prompt" aria-label="Prompt">
          <p>Paste into any agent or chat that can read web pages. It loads the skill and searches the live catalogue; nothing to install or update.</p>
          <?php $snippet('ac-prompt', 'Prompt', $setup->prompt()); ?>
        </section>
        <section class="agent-pane" data-pane="skill" aria-label="Skill">
          <?php $snippet('ac-plugin', 'Claude Code plugin', $setup->pluginInstall()); ?>
          <p class="agent-note">Update: <code><?= e($setup->pluginUpdate()) ?></code></p>
          <?php $snippet('ac-skills', 'Codex, Cursor, Gemini CLI, Copilot and other agents', $setup->skillsAdd()); ?>
          <p class="agent-note">Uses the <a href="https://github.com/vercel-labs/skills">skills CLI</a>. Update: <code><?= e($setup->skillsUpdate()) ?></code></p>
          <?php $snippet('ac-manual', 'By hand (Claude Code; other agents read ~/.agents/skills)', $setup->manualInstall()); ?>
          <p class="agent-note"><a href="<?= e($setup->skillUrl()) ?>">Inspect the skill first</a>: one Markdown file that tells the agent how to query this catalogue.</p>
        </section>
        <section class="agent-pane" data-pane="mcp" aria-label="MCP">
          <?php $snippet('ac-mcp-claude', 'Claude Code', $setup->mcpClaudeCode()); ?>
          <?php $snippet('ac-mcp-url', 'Server URL for claude.ai, ChatGPT and other clients', $setup->mcpUrl()); ?>
          <p class="agent-note">Add to <a href="<?= e($setup->cursorLink()) ?>">Cursor</a> · <a href="<?= e($setup->vscodeLink()) ?>">VS Code</a>. Remote, read-only, no key; three tools: search, get one record, list categories. Always current.</p>
        </section>
        <section class="agent-pane" data-pane="curl" aria-label="curl">
          <?php $snippet('ac-curl-1', 'Search', $curlSearch); ?>
          <?php $snippet('ac-curl-2', 'One record', $curlRecord); ?>
          <p class="agent-note"><a href="<?= e(url('/api')) ?>">API docs</a> · <a href="<?= e(url('/api/v1/openapi.json')) ?>">OpenAPI 3.1</a> · <a href="<?= e(url('/llms.txt')) ?>">llms.txt</a></p>
        </section>
      </div>
    </fieldset>
    <p class="agent-more"><a href="<?= e(url('/agents')) ?>">All setup options and how updates work</a></p>
  </div>
</details>
