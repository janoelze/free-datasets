# Research brief template (one sub-agent per bucket)

The conductor fills the `{{...}}` placeholders and pastes everything below the line as the
sub-agent prompt. Launch the agents in one message so they run in parallel (Agent tool,
`model: sonnet`). Give each agent its own `{{DRAFT_DIR}}`, e.g. `<scratchpad>/drafts/<bucket>`.

Placeholders:

- `{{BUCKET}}`: the domains, e.g. "science, space, weather, geo"
- `{{N}}`: how many candidates to verify (12-16 works well; ask for more than you need)
- `{{LEADS}}`: optional starting points (backlog lines, HN stories, a portal to mine)
- `{{EXISTING}}`: output of `ls datasets/ | sed 's/\.json$//' | tr '\n' ' '`
- `{{DRAFT_DIR}}`: absolute path of a new, empty directory for this agent's drafts
- `{{TODAY}}`: today's date, YYYY-MM-DD

---

You are researching free, open REST APIs for a catalogue at /Users/janoelze/src/datasets (a
library of free datasets with free APIs, used by developers and AI agents). Your bucket is
**{{BUCKET}}**. Find and verify **{{N}}** candidates that are not in the catalogue yet.

Read first: `.claude/skills/add-datasets/SKILL.md` (inclusion bar, checklist, field mapping),
`.claude/skills/add-datasets/template.json` (a complete record), `schema/dataset.schema.json`
(every field has a description) and two existing files in `datasets/` close to your bucket.

Already in the catalogue (do not research these, or anything that is the same API under
another name): {{EXISTING}}

Leads: {{LEADS}}

## Rules

- Only include what you executed today ({{TODAY}}) with curl. Every structured value (auth,
  CORS, rate-limit numbers, formats, pagination, OpenAPI URL, license, counts) must come from a
  response you saw or a docs page you fetched. If you only "know" something, leave the field
  out or hedge it in `summary`/`caveats` ("not re-confirmed").
- Always send `-A 'datasets-library/1.0 (+https://endtime-instruments.org/datasets/)'`. Never
  use `example.com` contacts in User-Agent values you test or record; some servers block them.
- Retry once after 10-30 s on a timeout or 5xx before dropping a candidate; note the flakiness.
- A sandbox TLS or DNS failure on one host is not proof the API is dead. Retry, and try
  `curl -v` to see where it fails; report it instead of dropping silently.
- Prefer the provider's own host over mirrors and wrappers. Do not include SaaS free tiers that
  need a credit card, paywalled or "contact sales" APIs, or undocumented scraping endpoints
  (unless widely used and valuable; then propose `status: unstable`).
- Do not edit anything in the repository. Write only into {{DRAFT_DIR}}.

## For each candidate

Run the per-candidate checklist from SKILL.md (alive, auth, User-Agent, CORS, rate-limit
headers, formats, OpenAPI, docs/terms/license, HN story), then write the record as
`{{DRAFT_DIR}}/<slug>.json` in schema form, starting from template.json:

- `slug` = file name, kebab-case, the API's common name (`bank-of-canada-valet`, not `boc-api`).
- Leave `related` out of the draft (it is resolved against the catalogue later); list the
  slugs you would link in your report instead.
- `example` is the exact request you ran; `response_excerpt` is a trimmed real response
  (cut long arrays and strings with `...`, keep the shape).
- `verification.last_verified` = {{TODAY}}, `added` = {{TODAY}}.

Validate each draft and fix every error before reporting:

```sh
cd /Users/janoelze/src/datasets
php bin/validate.php {{DRAFT_DIR}}/<slug>.json
php .claude/skills/add-datasets/check-format.php --fix {{DRAFT_DIR}}/<slug>.json
```

## Report (your final message)

A table with one row per candidate: slug, name, verdict (`draft written` / `dropped`), auth,
CORS, example HTTP status, proposed category, proposed `related` slugs, and one line of
reasoning. Then list dropped candidates with the reason (dead host, key now mandatory, paywall,
bot wall, moved version, duplicate) so the conductor can prune the backlog. Keep it short; the
drafts carry the detail.
