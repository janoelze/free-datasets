# Free Datasets API Library

A library of free datasets with free, open REST APIs. Each entry tells a developer or an AI
agent what the API serves, whether it needs a key, whether it works from a browser (CORS), its
rate limits, licence and pagination, a few representative endpoints, and one request that was
actually executed, with a trimmed excerpt of the response.

- Site: https://endtime-instruments.org/datasets/
- API root: https://endtime-instruments.org/datasets/api/v1
- OpenAPI 3.1: https://endtime-instruments.org/datasets/api/v1/openapi.json
- llms.txt: https://endtime-instruments.org/datasets/llms.txt
- Source: https://github.com/janoelze/datasets

## Using the API

Read-only, no key, `Access-Control-Allow-Origin: *`, JSON responses shaped
`{"data": ..., "meta": ..., "links": ...}`, errors as RFC 9457 `application/problem+json`.

```sh
# Weather APIs that need no key (sparse fields keep the response small)
curl 'https://endtime-instruments.org/datasets/api/v1/datasets?category=weather-climate&keyless=true&fields=slug,name,summary'

# One full record
curl 'https://endtime-instruments.org/datasets/api/v1/datasets/open-meteo-forecast'

# Counts per category, auth type, CORS mode, format, licence terms, status...
curl 'https://endtime-instruments.org/datasets/api/v1/facets'
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
