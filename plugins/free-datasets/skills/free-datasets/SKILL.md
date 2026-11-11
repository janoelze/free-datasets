---
name: free-datasets
description: Find a free public REST API or dataset for a task (weather, earthquakes, finance, government statistics, transport, sports, books, space, ...) and learn how to call it correctly - auth, CORS, rate limits, licence and one verified example request. Use when the user needs data from an external API, asks "is there a free API for X", wants a keyless or browser-callable (CORS) data source, or is choosing between public APIs. Queries the live Free Datasets API Library catalogue; no key needed.
license: MIT
compatibility: Needs HTTPS access to endtime-instruments.org (curl, or any web-fetch tool).
metadata:
  homepage: https://endtime-instruments.org/free-datasets/
  source: https://github.com/janoelze/free-datasets
---

# Free Datasets API Library

A curated catalogue of free datasets with free REST APIs. Every record was checked by
executing a real request. The catalogue is itself a read-only JSON API: no key,
`Access-Control-Allow-Origin: *`, responses `{"data", "meta", "links"}`, errors RFC 9457
`application/problem+json`.

    API=https://endtime-instruments.org/free-datasets/api/v1

The catalogue changes daily. Always query it; do not answer from memory or from this file.

## 1. Search (keep responses small with `fields`)

```sh
curl -s "$API/datasets?q=earthquake&keyless=true&fields=slug,name,summary,api&limit=10"
curl -s "$API/datasets?category=weather-climate&browser=true&fields=slug,name,summary"
```

| Parameter | Meaning |
|---|---|
| `q` | full text; every term must match (name, provider, summary, description, tags, endpoints) |
| `category` | one id from `$API/categories` (comma = OR) |
| `tag` | from `$API/tags` (comma = AND) |
| `keyless=true` | no key needed (`auth=none`); `auth=api_key,oauth2` for the rest |
| `browser=true` | callable from a web page (CORS `any` or `reflect`) |
| `format`, `style`, `provider_type`, `commercial_use`, `status`, `has_openapi` | see `$API/facets` for values and counts |
| `sort` | `name`, `-name`, `added`, `-added`, `verified`, `-verified`; `limit` 1-200 (default 50), `offset` |
| `fields` | top-level keys only, e.g. `slug,name,summary,api,example` |

Unknown parameters or values return 400 with the allowed list: read it and retry.
`$API/facets?q=...` shows how results split by category, auth, CORS, format, licence.
No match: try synonyms or fewer terms, browse `$API/tags`, then say nothing fits rather than
inventing an API.

## 2. Read one record

```sh
curl -s "$API/datasets/<slug>"
```

Check, in this order:

- `status` (`active`, `unstable`, `deprecated`) and `verification.last_verified`
- `api.base_url`, `endpoints[]` (method, path, summary), `links.docs`, `links.openapi`
- `api.auth`: `type` none|api_key|oauth2; `key_in`/`key_name`; `demo_key`; `links.signup`
- `api.required_headers`: send them (several APIs reject requests without a User-Agent)
- `api.cors`, `api.rate_limit.summary`, `api.pagination`, `api.max_page_size`
- `example.url` + `example.response_excerpt`: a request that worked; start from it
- `agent_notes` and `caveats`: units, id systems, coordinate order, gotchas. Read them.
- `data.license` (`commercial_use`), `data.update_frequency`, `data.coverage`

## 3. Call the upstream API

Call `api.base_url` directly, adapting `example.url`. Never send the user's keys anywhere
except the provider. The site's `/free-datasets/explore` endpoint is a rate-limited proxy for
the web page's "Try it" button, not for agents: do not use it.

## When reporting back

Give the dataset name, its page (`html_url`), auth and key signup, CORS, licence and the
request you ran. Mention `status: unstable` or an old `last_verified`.

## Without a shell

Fetch the same URLs with a web-fetch tool. Overview for humans and LLMs:
https://endtime-instruments.org/free-datasets/llms.txt (about 60 KB; prefer `q` searches).
OpenAPI 3.1: `$API/openapi.json`. Record JSON Schema: `$API/schema`.
