# Free Datasets API Library

A library of free datasets with free, open REST APIs. Each entry tells a developer or an AI
agent what the API serves, whether it needs a key, whether it works from a browser (CORS), its
rate limits, licence and pagination, a few representative endpoints, and one request that was
actually executed, with a trimmed excerpt of the response.

- Site: https://endtime-instruments.org/free-datasets/
- API root: https://endtime-instruments.org/free-datasets/api/v1
- OpenAPI 3.1: https://endtime-instruments.org/free-datasets/api/v1/openapi.json
- llms.txt: https://endtime-instruments.org/free-datasets/llms.txt
- Source: https://github.com/janoelze/free-datasets

## Using the API

Read-only, no key, `Access-Control-Allow-Origin: *`, JSON responses shaped
`{"data": ..., "meta": ..., "links": ...}`, errors as RFC 9457 `application/problem+json`.

```sh
# Weather APIs that need no key (sparse fields keep the response small)
curl 'https://endtime-instruments.org/free-datasets/api/v1/datasets?category=weather-climate&keyless=true&fields=slug,name,summary'

# One full record
curl 'https://endtime-instruments.org/free-datasets/api/v1/datasets/open-meteo-forecast'

# Counts per category, auth type, CORS mode, format, licence terms, status...
curl 'https://endtime-instruments.org/free-datasets/api/v1/facets'
```

`/datasets` also takes `q` (full text, all terms must match), `tag`, `auth`, `cors`,
`browser=true`, `format`, `style`, `provider_type`, `commercial_use`, `status`,
`has_openapi`, `sort`, `limit` and `offset`. Unknown parameters are rejected with 400 rather
than silently ignored. Other routes: `/categories`, `/tags`, `/schema`; see the OpenAPI document.

### For agents

Start at `/llms.txt` for a plain-text overview, or call `/api/v1/datasets?q=<topic>&keyless=true`
and then fetch the record you need. Every record carries `agent_notes` (units, id systems,
coordinate order, gotchas), `caveats`, any `required_headers` (several APIs block requests
without a descriptive User-Agent) and a verified `example` request you can adapt. Check
`verification.last_verified` and `status` before relying on an entry.

## The record format

One file per dataset, `datasets/<slug>.json`, validated against
[`schema/dataset.schema.json`](schema/dataset.schema.json) (JSON Schema 2020-12, every field
described there, unknown fields rejected), the categories in
[`schema/categories.json`](schema/categories.json) and a few semantic rules in
[`src/Schema/DatasetRules.php`](src/Schema/DatasetRules.php). Abridged:

```jsonc
{
  "slug": "bank-of-canada-valet",            // = file name
  "name": "Bank of Canada Valet API",
  "provider": { "name": "Bank of Canada", "type": "government" },
  "summary": "Keyless API for Bank of Canada time series: ...",
  "category": "finance-markets",             // one id from schema/categories.json
  "tags": ["exchange-rates", "interest-rates", "central-bank"],
  "links": { "homepage": "...", "docs": "...", "openapi": "..." },  // openapi only if confirmed
  "api": {
    "base_url": "https://www.bankofcanada.ca/valet",
    "auth": { "type": "none" },              // none | api_key | oauth2
    "cors": "any",                           // observed, not assumed
    "rate_limit": { "summary": "Not documented." },
    "pagination": "page"
  },
  "endpoints": [ { "method": "GET", "path": "/observations/{seriesNames}/{format}", "summary": "..." } ],
  "example": { "method": "GET", "url": "https://...?recent=2", "response_excerpt": "{...}" },
  "data": { "license": { "name": "...", "commercial_use": "allowed" }, "update_frequency": "daily" },
  "status": "active",
  "verification": { "last_verified": "2026-11-11", "http_status": 200, "with_key": true },
  "added": "2026-11-11"
}
```

A complete record with every optional field is in
[`.claude/skills/add-datasets/template.json`](.claude/skills/add-datasets/template.json).

## Adding a dataset

The bar: free (no credit card; a free key is fine), a real HTTP API with public docs, alive
today, https only. Every structured value must come from a response or docs page you checked,
not from memory.

- **By hand**: copy the template to `datasets/<slug>.json`, call the API with curl, fill in
  what you observed, then run `php bin/validate.php` and `php bin/verify.php <slug>`.
- **With Claude Code**: the project skill
  [`.claude/skills/add-datasets`](.claude/skills/add-datasets/SKILL.md) holds the full
  playbook (where to look, the verification checklist, field-by-field judgement calls,
  fanning out research to sub-agents, maintenance). Ask Claude to "add datasets from the
  backlog" or "find free APIs for X" and it will follow it. Leads that were verified but not
  yet added are in [`backlog.md`](.claude/skills/add-datasets/backlog.md).

## Scheduled curation

[`.github/workflows/curate.yml`](.github/workflows/curate.yml) runs once a day (03:37 UTC). A
script re-tests the 30 records verified longest ago
([`bin/curate-select.php`](bin/curate-select.php)), a Claude agent grooms the failures and adds up
to 3 new APIs following the skill's
[scheduled-run prompt](.claude/skills/add-datasets/scheduled-run.md), and a separate job with no
agent decides what is accepted ([`bin/curate-guard.php`](bin/curate-guard.php): allowed paths,
caps, validation, re-execution of every new or changed example, evidence for removals, free-text
and host checks), runs lint, validation, the tests and the format check, opens a pull request
(label `curation`) and squash-merges it. Merges made with the workflow token start no workflows,
so the run then dispatches `ci.yml` on `main`, which tests and deploys. If the guard or the
checks fail, nothing is merged: an allowed-paths change becomes a `curation-needs-review` PR, and
anything else only fails the run. A run is skipped while a curation PR is still open.

Setup (once; the run is a no-op with a notice until a credential exists):

```sh
# Agent credential: either a Claude subscription token ...
claude setup-token                                   # prints the token
gh secret set CLAUDE_CODE_OAUTH_TOKEN --repo janoelze/free-datasets   # paste it
# ... or an API key
gh secret set ANTHROPIC_API_KEY --repo janoelze/free-datasets

# Let workflows open pull requests (Settings > Actions > General > Workflow permissions)
gh api -X PUT repos/janoelze/free-datasets/actions/permissions/workflow \
  -f default_workflow_permissions=read -F can_approve_pull_request_reviews=true
```

Operating it:

```sh
gh workflow run curate.yml -f dry_run=true           # everything except PR and merge; see the artifacts
gh workflow run curate.yml -f add_count=0 -f verify_count=60
gh variable set CURATE_AUTOMERGE --body false --repo janoelze/free-datasets  # review mode: open PRs, merge by hand
gh variable delete CURATE_AUTOMERGE --repo janoelze/free-datasets           # back to auto-merge
gh variable set CURATE_MODEL --body opus --repo janoelze/free-datasets      # default: sonnet
gh workflow disable curate.yml                       # pause entirely (gh workflow enable curate.yml)
```

In review mode, CI does not start on its own for a PR opened by the workflow token (GitHub may
show it as awaiting approval); the PR body links the run in which the checks passed, and merging
it by hand triggers the normal CI and deploy. GitHub disables scheduled workflows in public
repositories after 60 days without repository activity; if that happens, re-enable it with
`gh workflow enable curate.yml`.

## Local development

Plain PHP 8.3 with the curl extension; no Composer, no database.

```sh
php -S localhost:8000 -t public public/index.php   # dev server, http://localhost:8000/
php bin/validate.php [FILE...]                     # schema + rules; exit 1 on errors
php bin/build.php                                  # validate, then write var/catalog.json
php bin/verify.php [slug...] [--write]             # execute each record's example request
php tests/run.php [filter]                         # test suite
```

## Deployment

CI validates and tests every push and deploys `main` to the server; details in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## License

Code: MIT, see [`LICENSE`](LICENSE). The catalogue records in `datasets/` are dedicated to the
public domain under CC0 1.0. Each listed dataset and API keeps its own licence and terms,
recorded in its `data.license` field; check them before reusing the data.
