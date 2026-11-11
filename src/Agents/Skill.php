<?php

declare(strict_types=1);

namespace Datasets\Agents;

use Datasets\Config;
use Datasets\Url;

/**
 * The agent skill (plugins/free-datasets/skills/free-datasets/SKILL.md), served from that one
 * file at {base}/.well-known/agent-skills/free-datasets/SKILL.md, plus the discovery document
 * at {base}/.well-known/agent-skills/index.json (Agent Skills discovery draft v0.2.0, read by
 * `npx skills add <site>`). The digest is computed from the served bytes on every request, so
 * index and file cannot disagree.
 */
final class Skill
{
    public const NAME = 'free-datasets';

    /** Path of the skill file, relative to the repository root. */
    public const FILE = 'plugins/free-datasets/skills/free-datasets/SKILL.md';

    public const DISCOVERY_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

    /** App path of the discovery document. */
    public const INDEX_PATH = '/.well-known/agent-skills/index.json';

    /** App path of the served SKILL.md (relative to the index: "free-datasets/SKILL.md"). */
    public const SKILL_PATH = '/.well-known/agent-skills/' . self::NAME . '/SKILL.md';

    public function __construct(private readonly string $file)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->rootDir . '/' . self::FILE);
    }

    public function exists(): bool
    {
        return is_file($this->file) && is_readable($this->file);
    }

    public function markdown(): string
    {
        $s = @file_get_contents($this->file);
        if (!is_string($s)) {
            throw new \RuntimeException('Skill file is missing: ' . $this->file);
        }

        return $s;
    }

    public function digest(): string
    {
        return 'sha256:' . hash('sha256', $this->markdown());
    }

    /**
     * Front-matter fields with plain one-line values (name, description, license, ...).
     *
     * @return array<string, string>
     */
    public function frontMatter(): array
    {
        if (preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n/s', $this->markdown(), $m) !== 1) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r?\n/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([a-z][a-z0-9_-]*):[ \t]+(\S.*)$/', $line, $kv) === 1) {
                $out[$kv[1]] = trim($kv[2]);
            }
        }

        return $out;
    }

    public function description(): string
    {
        return $this->frontMatter()['description'] ?? '';
    }

    /** @return array<string, mixed> */
    public function discovery(): array
    {
        return [
            '$schema' => self::DISCOVERY_SCHEMA,
            'skills' => [[
                'name' => self::NAME,
                'type' => 'skill-md',
                'description' => $this->description(),
                'url' => self::NAME . '/SKILL.md',
                'digest' => $this->digest(),
            ]],
        ];
    }

    public static function url(Url $url): string
    {
        return $url->absolute(self::SKILL_PATH);
    }
}
