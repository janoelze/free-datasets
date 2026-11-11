# Architecture

Plain PHP 8.3, no database, no composer, no build tooling. Each dataset is one file
`datasets/<slug>.json`, validated against `schema/dataset.schema.json` (JSON Schema 2020-12)
plus semantic rules. The app serves a read-only REST API under `/api/v1` and HTML pages from
`templates/`.

```
public/index.php        front controller (tiny); public/.htaccess rewrites non-files to it
src/bootstrap.php       PSR-4 autoloader for Datasets\ -> src/, loads src/helpers.php
src/App.php             routing table, dispatch, common headers (CORS, Cache-Control, ETag/304)
src/Config.php          paths + env (APP_DATASETS_DIR, APP_CACHE_FILE, APP_BASE_PATH, APP_PUBLIC_URL, APP_TEMPLATES_DIR, APP_VAR_DIR)
src/Url.php             base-path-aware URL builder (url(), abs_url(), asset() delegate to it)
src/Catalog.php         loads/queries the catalogue (see below)
src/ResultPage.php      one page of results
src/Json.php            JSON encoding (API pretty-print, 2-space file format, {} restoration)
src/Schema/Validator.php     JSON Schema subset validator (throws on unknown keywords)
src/Schema/DatasetRules.php  semantic rules (slug = file name, category, related, auth, dates, https)
src/Schema/Linter.php        file/dir validation = JSON syntax + schema + rules
src/Http/{Request,Response,Http,Router,DevServer}.php
src/Api/ApiController.php    /api/v1 handlers
src/Api/ListParams.php       query-parameter parsing/validation for /datasets and /facets
src/Api/OpenApi.php          generated OpenAPI 3.1 document
src/Api/LlmsTxt.php          /llms.txt and /robots.txt
src/Web/PageController.php   HTML routes -> templates/*.php
src/Seo/{IndexPolicy,Meta,StructuredData,Sitemap,Feed}.php   indexing policy, titles, JSON-LD, /sitemap.xml, /feed.xml
src/Explore/{ExploreController,Policy,Target,Fetcher,RateLimiter,Storage,Refusal}.php   POST /explore proxy
src/Agents/{Skill,Setup}.php  agent-skills discovery + SKILL.md routes; every visitor command (panel, /agents, README)
src/Mcp/{Server,Tools,RpcError,ToolError}.php   POST /mcp, stateless MCP server
plugins/free-datasets/       Claude Code plugin holding the agent skill (served by the site too)
.claude-plugin/marketplace.json   makes the repo a Claude Code marketplace named free-datasets
public/assets/explorer.js    "Try it" explorer (dataset pages, section #example)
bin/validate.php  bin/build.php  bin/verify.php
tests/run.php     tests/*Test.php  tests/fixtures/
```

## Running things

```sh
php -S localhost:8000 -t public public/index.php     # dev server (serves public/ files too)
APP_BASE_PATH=/free-datasets php -S localhost:8000 -t public public/index.php   # mimic production prefix
php bin/validate.php [--quiet] [--dir=PATH] [FILE...] # "file: /pointer: message"; exit 1 on errors
php bin/build.php                                     # validate, then write var/catalog.json
php bin/verify.php [slug...] [--write]                # execute each example request
php tests/run.php [filter]                            # all tests (starts php -S on free ports)
```

Production: Apache with docroot path `/free-datasets` symlinked to `public/`. The base path is
detected from `SCRIPT_NAME` (`/free-datasets/index.php` -> `/free-datasets`); under `php -S` it is `""`.
`APP_BASE_PATH` overrides detection, `APP_PUBLIC_URL` overrides the absolute URL used in links,
OpenAPI `servers` and the schema `$id` (default: request scheme + host + base path).
Run `php bin/build.php` after deploying data changes; without a fresh `var/catalog.json` the
app reads and validates the files on every request (fine for a few hundred files, ~10 ms).

## Catalog API (`Datasets\Catalog`)

Datasets are plain associative arrays exactly as stored in the JSON files; computed fields
(`url`, `html_url`) are added by the API layer only.

| Method | Returns |
|---|---|
| `Catalog::fromConfig(Config)` / `Catalog::load($datasetsDir, $schemaDir, ?$cacheFile)` | catalogue; uses the cache when it exists, was built from the same directory and is newer than every file in `datasets/` and `schema/`, else loads + validates files (invalid files are skipped, `related` is not enforced at load) |
| `Catalog::fromArrays($datasets, $categories, $schema)` | in-memory catalogue (tests/tools) |
| `all()` | list of all datasets, sorted by slug |
| `slugs()`, `count()`, `has($slug)`, `get($slug)` | lookup (`get` returns `null` if unknown) |
| `related($slug)` | datasets listed in `related` that exist |
| `categories()` | `[{id, name, description, count}]` in categories.json order |
| `category($id)` | one category with count, or `null` |
| `categoryIds()` | list of ids |
| `tags(?$subset)` | `tag => count`, count desc then name |
| `facets(?$subset)` | `facet => value => count` for category, tag, auth, cors, format, style, provider_type, commercial_use, status; enumerated facets list every allowed value (zeros included) |
| `enums()` | allowed values per facet (plus pagination, update_frequency), read from the schema |
| `schema()` | the dataset JSON Schema as an array |
| `filter($criteria, ?$subset)` | matching datasets (input order) |
| `sort($datasets, $sort)` | `name`, `-name`, `added`, `-added`, `verified`, `-verified`; ties by name |
| `paginate($datasets, $limit, $offset)` | `ResultPage` |
| `query($criteria, $sort = 'name', $limit = 50, $offset = 0)` | filter + sort + paginate -> `ResultPage` |
| `Catalog::facetValues($dataset, $facet)` | values of one facet for a dataset |
| `source()` | `cache`, `files` or `memory` |
| `skipped()` | `path => ValidationError[]` for files skipped at load |

Criteria keys (all optional, all must match): `q` (string; every whitespace-separated term must
occur case-insensitively in name, provider name, summary, description, tags, endpoint summaries),
`category`, `auth`, `cors`, `format`, `style`, `provider_type`, `commercial_use`, `status`
(list or comma-separated string; OR), `tag` (AND), `keyless`, `browser`, `has_openapi` (bool).

`ResultPage` has public `items`, `total`, `limit`, `offset` and `count()`, `hasPrev()`,
`hasNext()`, `prevOffset()`, `nextOffset()`, `lastOffset()`, `pageNumber()`, `pageCount()`.

`Datasets\Api\ListParams::parse($request->query, $catalog, paging: true, strict: false)` turns
raw query parameters into `->criteria`, `->sort`, `->limit`, `->offset`, `->fields` and
`->canonical` (a cleaned query array to build links with); in non-strict mode invalid values are
dropped silently, which is what the home page uses.

## URL helpers (global functions, `src/helpers.php`)

- `url('/d/open-meteo', ['tab' => 'x'])` -> `/free-datasets/d/open-meteo?tab=x` (root-relative, base-path aware;
  list values are joined with commas, `null`/`''` dropped, booleans become `true`/`false`)
- `abs_url('/d/open-meteo')` -> `https://endtime-instruments.org/free-datasets/d/open-meteo`
- `asset('assets/style.css')` -> `/free-datasets/assets/style.css?v=<mtime>` (file in `public/`;
  `.htaccess` caches these for a year)
- `e($value)` -> HTML-escaped string
- The underlying object is `Datasets\Url::current()` (also passed to templates as `$urls`).

## Templates

`templates/<name>.php` are plain PHP files rendered with `extract()`ed variables; a missing
template yields a plain-text fallback. All templates receive:

- `$catalog` (`Catalog`), `$request` (`Http\Request`: `path`, `query`, `basePath`, `publicUrl`, `queryValue($name)`), `$urls` (`Url`)

| Route | Template | Extra variables |
|---|---|---|
| `/` | `home.php` | `$params` (`ListParams`, lenient, from the query string: same filters as the API), `$policy` (`Seo\IndexPolicy`), `$results` (`ResultPage`, the whole filtered catalogue unless `?limit=`), `$facets` (counts over the filtered set), `$categories` (with counts), `$tags` (`tag => count`) |
| `/d/{slug}` | `dataset.php` | `$dataset` (array), `$category` (array with count), `$related` (list of datasets), `$apiUrl` (absolute) |
| `/c/{id}` | `landing.php` | `$kind = 'category'`, `$landingKey`, `$category`, `$datasets`, `$categories` (404 for unknown or empty categories) |
| `/t/{tag}` | `landing.php` | `$kind = 'tag'`, `$landingKey`, `$count`, `$datasets`, `$categories`, `$nearTags` (only tags with ≥ 3 datasets; thinner tags 302 to `/?tag=`) |
| `/t` | `tags.php` | `$tags` (tags with pages), `$categories` |
| `/api` | `api.php` | `$apiBase`, `$openapiUrl`, `$schemaUrl` (absolute) |
| `/about` | `about.php` | – |
| `/agents` | `agents.php` | – (commands from `Agents\Setup`) |
| anything else, unknown slug | `404.php` (status 404) | `$path` |

HTML responses get `Cache-Control: public, max-age=300`, an ETag and `X-Content-Type-Options: nosniff`.

Head variables a template may set before including `partials/header.php`: `$title`, `$metaTitle`
(complete `<title>`), `$ogTitle`, `$description`, `$canonical` (null = none), `$robots`, `$jsonLd`
(list of schema.org nodes, rendered as one `@graph`), `$ogType`, `$headExtra`, `$rail`, `$current`, `$agentPanel` (`corner` default, `masthead`, `none`).

## SEO

The app is one URL-prefix site (`https://endtime-instruments.org/free-datasets/`) on a shared host; the
host-root `/robots.txt` is not ours (it currently 404s, which crawlers treat as "allow all"). Our
`{base}/robots.txt` is therefore advisory; the real controls are per-page.

| URL | Indexable | Canonical |
|---|---|---|
| `/` | yes | `/` |
| `/?sort=…`, `/?utm_…` and other unknown/invalid params only | yes (duplicate) | `/` |
| `/?category=x` (one category, optional sort) | yes (duplicate) | `/c/x` |
| `/?tag=x` (one tag with ≥ 3 datasets, optional sort) | yes (duplicate) | `/t/x` |
| `/?q=…`, other facets, several values/filters, `limit`/`offset`, thin tags | `noindex,follow` | itself, normalised (known params, fixed order, sorted lists) |
| `/c/{id}`, `/t`, `/t/{tag}`, `/d/{slug}`, `/api`, `/about`, `/agents` | yes | itself |
| 404 pages | `noindex` | none |
| `/api/v1/*`, `/llms.txt`, `/sitemap.xml`, `/feed.xml`, `/.well-known/agent-skills/*`, `/mcp` | `X-Robots-Tag: noindex` | – |

`?category=x` is canonicalised rather than 301-redirected because it is the in-place filter view
(home page JS, GET form); rail and row links use the clean `/c/x` URL as `href` and give the JS the
filter URL in `data-filter-href`. Case variants of page URLs 301 to lower case; `www.` 301s to the
bare host (`public/.htaccess`). Titles and descriptions come from `Seo\Meta` (unique, ≤ 70 / 160
characters, tested over the real catalogue). `/sitemap.xml` lists exactly the indexable URLs
(`Seo\Sitemap::entries()`); `lastmod` is the later of `added` and `verification.last_verified`
(listing pages: their newest record; `/api` and `/about` have none). `/feed.xml` is Atom, newest
50 by `added`.

JSON-LD (`Seo\StructuredData`): home = `WebSite` (+ `SearchAction` to `/?q=`), `Organization`,
`DataCatalog`, `CollectionPage` + `ItemList`; landing pages = `CollectionPage` + `ItemList` +
`BreadcrumbList`; dataset pages = `ItemPage` (`datePublished` = `added`, `dateModified` = record
lastmod), `Dataset` (name, description, url, sameAs, keywords, creator/publisher, license,
isAccessibleForFree, spatialCoverage, ISO-8601 `temporalCoverage` only, includedInDataCatalog,
`DataDownload` only for `links.bulk_download`), `WebAPI` (documentation, termsOfService) and
`BreadcrumbList`. Not filled because the records cannot say it truthfully: `dateModified` of the
data itself, `version`, `variableMeasured`, `measurementTechnique`, `citation`, `funder`,
`identifier`, free-text temporal coverage, and `distribution` for API-only datasets.

## REST API summary

All under `{base}/api/v1`, GET/HEAD/OPTIONS only, no auth, `Access-Control-Allow-Origin: *`,
`Cache-Control: public, max-age=300`, strong ETag (`If-None-Match` -> 304), pretty JSON.
Errors are RFC 9457 `application/problem+json` (`type`, `title`, `status`, `detail`,
`instance`, plus `errors: [{parameter, detail}]` on 400). Unknown query parameters on
`/datasets` and `/facets` are rejected with 400 so typos do not silently return everything.
URLs with a trailing slash 308-redirect to the slash-less form.

- `/api/v1`, `/datasets`, `/datasets/{slug}`, `/categories`, `/categories/{id}`, `/tags`,
  `/facets` (accepts the same filters as `/datasets`), `/schema`, `/openapi.json`
- `/llms.txt`, `/robots.txt`, `/sitemap.xml`, `/feed.xml` (outside `/api/v1`)

The OpenAPI document is generated from `schema/dataset.schema.json` and `schema/categories.json`
(`components.schemas.Dataset` is the schema verbatim minus `$schema`/`$id`), so it cannot drift.

## Explorer & proxy

Every dataset page has one "Try it" section (id `example`, so old `#example` links still work;
`templates/dataset.php`, `public/assets/explorer.js`, loaded only where the explorer can run). The
form comes first, prefilled with the recorded example (method, URL, POST body; an endpoint picker
fills `base_url + path` and sending is blocked while `{placeholders}` remain; "Copy as curl" always
reflects the current request). Below it, the panel starts with the *recorded* response
(`example.response_excerpt`, pretty-printed, under "200 · recorded 2026-11-11 · excerpt" from
`verification`; "recorded without a key" when `with_key` is false). Send replaces it in place with
the live result — status, duration, size, transport, a few response headers and the body
(pretty-printed JSON; at most 200 KB rendered) — and "Show recorded example" switches back and
restores the example request. Without JavaScript, and on records where the explorer is off, the
same section is static: the curl command (`View::curl`) and the recorded excerpt, plus the key or
deprecation note.
`Datasets\Explore\Policy` decides, for the page and the server alike:

| Record | Explorer |
|---|---|
| `status: deprecated` | off, with a note |
| `auth.type: none`, or a `demo_key` | proxy allowed; direct too when possible |
| own key needed (api_key without demo key, oauth2) | never proxied; direct-only if CORS allows, otherwise just a note with the signup link |

*Direct* = the browser calls the API with `fetch` (no credentials): only when `api.cors` is `any`
or `reflect`, the record has no `required_headers` and the method is GET. When direct is the
default the user can switch to the proxy; a failed direct call offers "retry via proxy".

### The proxy: `POST {base}/explore`

JSON in `{"slug", "method", "url", "body"?}`; JSON out `{status, status_text, headers, duration_ms,
size, truncated, body, content_type, final_url, redirects, cached, note?}`, or an RFC 9457 problem
with a specific `title`. Not under `/api/v1`, not in OpenAPI, never CORS-enabled (`App` routes
`/explore` before the generic dispatcher, so the global API CORS headers and the permissive
`OPTIONS` preflight never apply: every method but POST, OPTIONS included, gets 405 `Allow: POST`).
Every response: `Cache-Control: no-store`, `X-Robots-Tag: noindex`, `nosniff`; robots.txt has
`Disallow: {base}/explore`.

**Threat model.** The proxy runs on a shared host next to other sites. An attacker controls the
whole request (and can mint DNS records), so the proxy must not become: an SSRF into the host or
its network (metadata, localhost services, other tenants); an open relay for arbitrary hosts; a
way to launder personal API keys or our visitors' cookies; a CSRF/cross-site gadget; a
bandwidth/CPU amplifier; or a way to make upstream APIs block this server.

Controls, in order (each has a test in `tests/ExploreTest.php`):

1. **Origin.** `Sec-Fetch-Site` present and not `same-origin` → 403; `Origin` present and not this
   site's origin → 403. Requests with neither (curl) are allowed and left to the rate limit.
2. **Format.** `Content-Type: application/json` (else 415), body ≤ 8 KB (else 413), a JSON object
   with string `slug`, `method`, `url` (else 400).
3. **Rate limit** (`RateLimiter`, before any parsing work): per client 20/minute and 200/day,
   globally 300/minute; fixed windows in `var/ratelimit/` (one counter file per window, `flock`,
   stale windows deleted on ~2% of requests); 429 with `Retry-After`. Clients are an HMAC-SHA256
   of the address with a random per-install salt (`var/ratelimit/.salt`), so no raw IPs are
   stored; IPv6 clients are keyed by their /64. The client address is `REMOTE_ADDR` only: on the
   production host (Uberspace: nginx → Apache → PHP-FPM) `REMOTE_ADDR` is already the real client
   (v4 and v6, checked 2026-11-11), while `X-Forwarded-For` is passed through from the client
   unmodified and therefore spoofable — it is never read.
4. **Eligibility.** Unknown slug 404; deprecated or own-key records 403. With a query-string key
   (`auth.key_in: query`), any value of `key_name` other than the documented demo key is refused
   (403), so personal keys never pass through; the server never adds credentials.
5. **Method.** GET always; POST only if the record's example or an endpoint is POST, body ≤ 4000
   bytes, sent as the example's `Content-Type` or `application/x-www-form-urlencoded`.
6. **URL** (`Target::parse`). ≤ 2000 chars; printable ASCII only (no whitespace, CR/LF, NUL,
   non-ASCII — the page percent-encodes spaces and non-ASCII for you), no backslash; scheme `https`;
   authority must be a plain `host[:port]` (no `user@`, no IP literals/brackets, no `%`, no trailing
   dot); our own split must agree with `parse_url` on scheme/host/port/path/query; host must equal
   (case-insensitively) a host of *that record's* `api.base_url`, `example.url` or absolute
   endpoint paths — no wildcards, no subdomains; port 443 or one the record's URLs name. The URL
   sent is rebuilt from the validated parts (fragment dropped).
7. **Address** (`Fetcher::vet`). Resolve A and AAAA; refuse if *any* address is not global unicast:
   `filter_var(NO_PRIV_RANGE | NO_RES_RANGE)` plus explicit IPv4 blocks (0/8, 10/8, 100.64/10,
   127/8, 169.254/16, 172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16, 198.18/15,
   198.51.100/24, 203.0.113/24, 224/4, 240/4 …) and IPv6 allowed only inside 2000::/3 minus
   2001::/23 (Teredo etc.), 2001:db8::/32, 2002::/16 (6to4), 3fff::/20 — which also refuses ::1,
   IPv4-mapped/compatible, NAT64, ULA, link-local and multicast. curl is then pinned to exactly the
   vetted addresses with `CURLOPT_RESOLVE` (no DNS rebinding between check and use; curl still
   chooses between IPv4 and IPv6 itself, which matters for hosts with a dead IPv4 path).
8. **Fetch** (`Fetcher`). HTTPS only (`CURLOPT_PROTOCOLS_STR`/`REDIR_PROTOCOLS_STR`), TLS verified,
   proxies disabled (`CURLOPT_PROXY ''`, `NOPROXY *`), no cookies. curl never follows redirects:
   up to 3 hops are followed by hand, each re-validated against the same record (steps 6–7, fresh
   resolve and pin); a redirect elsewhere is returned as the final 3xx with its `location` and a
   `note`. Connect timeout 5 s, 12 s total across hops. Body capped at 512 KB (decoded; the write
   callback aborts the transfer, `truncated: true`); response headers capped at 64 KB.
9. **Sent upstream:** `User-Agent: datasets-library-explorer/1.0 (+https://endtime-instruments.org/free-datasets/)`,
   `Accept: application/json, */*;q=0.5` (unless the record sets Accept), the record's own
   `example.headers` / `required_headers` examples (never User-Agent, Host, Cookie, Authorization,
   hop-by-hop or forwarding headers; values must be printable), `Content-Type` for POST. Nothing
   from the visitor's request.
10. **Returned:** only `content-type`, `content-length` (dropped when the upstream body was
   compressed), `location`, `retry-after`, `cache-control`, `etag`, `last-modified` and
   `x-ratelimit*`/`ratelimit*`/`x-rate-limit*`. Binary or non-UTF-8 bodies are not returned, only
   a note with type and size.
11. **Cache.** Identical GETs (slug + rebuilt URL) within 30 s are served from
   `var/explore-cache/` (`"cached": true`); at most 64 files / 16 MB, expired and oldest entries
   pruned on every write. Upstream errors (problems) are not cached.

`var/ratelimit` and `var/explore-cache` are created on demand with mode 0700 (files 0600) by the
app (PHP-FPM runs as the site user); `var/` is outside the web root and `bin/deploy.sh` never
sends or deletes it. `APP_VAR_DIR` relocates it.

**Test mode.** `APP_EXPLORE_TEST_MODE=1` is honoured only when `PHP_SAPI === 'cli-server'` (never
under Apache/FPM; a unit test asserts it is ignored elsewhere). It accepts `http://` and loopback
addresses (other private ranges stay blocked), reads fake DNS from
`APP_EXPLORE_TEST_RESOLVE="up.test=127.0.0.1,private.test=10.0.0.1"` and limits from
`APP_EXPLORE_TEST_LIMITS="minute,day,global"`. The HTTP tests run a fake upstream
(`tests/fixtures/explore-upstream.php`) under `php -S`; `Target` and the IP classifier are
unit-tested directly without relaxation.

## Agents: skill, discovery, panel, MCP

Visitors get four ways to point an agent at the catalogue, shown by `templates/partials/agent-panel.php`
("Use with your agent": a `<details>` with CSS-only radio tabs, so it works without JavaScript;
`app.js` adds the Copy buttons and Escape-to-close; rendered at the top of `<main>` on every page,
in the masthead on `/`, not on `/agents`) and in full on `/agents` (indexable, in the sitemap; it
exists so README, llms.txt and the panel can link to a permanent page with the per-client notes).
All commands come from `Agents\Setup`, built from the public URL, so they are base-path aware;
`tests/AgentsTest.php` checks the README shows the production versions.

- **Prompt**: "Read `{base}/.well-known/agent-skills/free-datasets/SKILL.md` and follow it …". Always current.
- **Skill**: one file, `plugins/free-datasets/skills/free-datasets/SKILL.md` (thin: how to query the
  live API, no data). Distributed three ways, all from that file: the Claude Code plugin
  (`.claude-plugin/marketplace.json` → `plugins/free-datasets`; `claude plugin install free-datasets
  --marketplace https://github.com/janoelze/free-datasets`), the skills CLI (`npx skills add {base}`
  reads `{base}/.well-known/agent-skills/index.json`, Agent Skills discovery draft 0.2.0; the repo
  URL works too) and by hand. The maintainer skill `.claude/skills/add-datasets` carries
  `metadata.internal: "true"` so installers skip it. Installed copies do not auto-update:
  `claude plugin update free-datasets@free-datasets` only sees a change when
  `plugins/free-datasets/.claude-plugin/plugin.json` `version` is bumped (a test pins the SKILL.md
  hash per version and fails until you bump it); `npx skills update` compares content.
- **Discovery routes** (`Agents\Skill`): `{base}/.well-known/agent-skills/index.json`
  (`$schema`, one `skill-md` entry, `url: "free-datasets/SKILL.md"`, `digest: "sha256:…"` computed per
  request from the served bytes) and `{base}/.well-known/agent-skills/free-datasets/SKILL.md`
  (`text/markdown; charset=utf-8`, read from `plugins/…`; no copy in `public/`). Both get the API's
  headers: CORS `*`, `Cache-Control: public, max-age=300`, ETag, `X-Robots-Tag: noindex`.
  `public/.htaccess` denies dotfiles except the single name `.well-known` (Apache maps the URL to that
  missing path component and would deny it before the rewrite; checked under Docker `httpd:2.4` +
  PHP-FPM). Host-root `/.well-known/` is not ours.

### MCP server: `POST {base}/mcp`

`Mcp\Server`, routed by `App` before the generic dispatcher (like `/explore`) and sending its own
headers. Stateless Streamable HTTP with one `application/json` response per request (no SSE, no
sessions, no GET stream). Dual-era:

| | Modern (2026-07-28) | Legacy (2025-03-26, 2025-06-18, 2025-11-25) |
|---|---|---|
| detected by | `params._meta["io.modelcontextprotocol/protocolVersion"]` or a modern `MCP-Protocol-Version` header | neither |
| handshake | none; `server/discover` | `initialize` answered (supported version echoed, else 2025-11-25), no `Mcp-Session-Id`; notifications → 202 |
| checks | `MCP-Protocol-Version` and `Mcp-Method` (and `Mcp-Name` for `tools/call`, base64 sentinel decoded) must match the body → else 400 / -32020; `_meta` protocolVersion + clientCapabilities required → else 400 / -32602; unknown version → 400 / -32022 `{supported, requested}` | unknown `MCP-Protocol-Version` header → 400 / -32022 |
| results | `resultType: "complete"`, `_meta` serverInfo; `tools/list` and `server/discover` carry `ttlMs`/`cacheScope` | plain |
| unknown method | 404 / -32601 | 200 / -32601 (a 404 would make old clients think their session expired) |

Methods: `ping`, `server/discover`, `tools/list`, `tools/call`. Transport errors: non-POST → 405
`Allow: POST, OPTIONS` (OPTIONS is a CORS preflight, 204); non-JSON content type 415; body over
64 KB 413; invalid JSON 400 / -32700; batches (JSON arrays) 400 / -32600 (both current revisions
forbid them); malformed envelopes 400 / -32600. Errors whose id could not be read omit `id`.

Tools (`Mcp\Tools`, all annotated read-only/idempotent/closed-world, results as `structuredContent`
plus the same JSON in a text block; argument problems are `isError: true` results so the model
can correct itself, an unknown tool name is -32602): `search_datasets {q, category, tag, keyless,
browser, has_openapi, limit 1–50 (10), offset}` → `{total, offset, items: [{slug, name, summary,
category, auth, cors, status, html_url, api_url}]}`, validated by `Api\ListParams` exactly like
`GET /api/v1/datasets`; `get_dataset {slug}` → the REST record (`ApiController::present`, with
`url` and `html_url`); `list_categories {}` → non-empty categories with counts. `tools/list` is
about 2.2 KB (a test caps it at 4.5 KB). Reads the in-memory `Catalog` only: no upstream requests, so no
new SSRF surface; no auth, no cookies.

Origin policy: the spec asks servers to validate `Origin` against DNS rebinding, which threatens
local servers. This one is public, read-only and credential-free, so any well-formed origin (or
`null`, or none) is accepted and browsers may call it cross-origin (`Access-Control-Allow-Origin: *`,
never `Allow-Credentials`); malformed `Origin` values get 403. Rate limit: `Explore\RateLimiter`
with its own bucket `var/ratelimit-mcp/`, 120/minute and 5000/day per client, 1200/minute overall;
429 / -31429 with `Retry-After` (test override `APP_MCP_TEST_LIMITS`, honoured under `php -S`
only). Every response: `Cache-Control: no-store`, `X-Robots-Tag: noindex`, `nosniff`; robots.txt
disallows `{base}/mcp`. Clients checked: MCP Inspector CLI 2.14 (modern, legacy, auto) and
Claude Code 2.1.322 (uses the modern era: `server/discover`, `tools/list`, `tools/call`).

## Deployment

`bin/deploy.sh` is the only deploy path, used by hand and by CI. It runs `php bin/validate.php`
(fail fast), rsyncs `public src templates datasets schema bin docs deploy plugins` to
`janoelze@aquila.uberspace.de:/var/www/virtual/janoelze/free-datasets/` (`--delete` inside those
directories; `var/` is never sent or deleted), runs `php bin/build.php` on the server (the cache
records the directory it was built from, so it must be built there), creates the docroot symlink
`/var/www/virtual/janoelze/endtime-instruments.org/free-datasets -> /var/www/virtual/janoelze/free-datasets/public`
if it is missing (only after checking the target exists and Apache can traverse it; it refuses to
replace anything else at that path), then (re)installs the redirect at the former path (below), then
smoke-tests the public URL and exits non-zero if any
check fails (including `POST /explore` with the open-meteo example → 200, and a foreign host →
4xx problem; the agent-skills index whose digest must match the served SKILL.md; and a
2026-07-28 `tools/list` POST to `/mcp`, which also proves the `MCP-*` headers reach PHP). Host, user, paths and URL are overridable: `DEPLOY_HOST`, `DEPLOY_USER`,
`DEPLOY_APP_DIR`, `DEPLOY_DOCROOT`, `DEPLOY_MOUNT`, `DEPLOY_PUBLIC_URL`, `DEPLOY_SSH_OPTS`,
`DEPLOY_LEGACY_MOUNT` (empty = skip the redirect), `DEPLOY_SKIP_SMOKE=1`. The app lives under `/var/www/virtual/janoelze/`, not `~/`, because the
home directory is `0700` and Apache cannot follow a symlink into it (403, `AH00037`).

Former path: until 2026-11-11 the app was served at `/datasets/` (repo `janoelze/datasets`).
`<docroot>/datasets` is now a real directory holding only `deploy/legacy-redirect/.htaccess`:
GET/HEAD to `/datasets[/<path>][?<query>]` answers 301 to `/free-datasets/<path>?<query>` (path taken
from the raw request line, so percent-encoding survives), other methods 308 (method and body kept),
a CORS preflight 204; every response carries `Access-Control-Allow-Origin: *` so cross-origin
`fetch()` follows the redirect. `bin/deploy.sh` installs it idempotently: it rewrites the file only
in a directory that holds exactly that file with its marker first line, replaces only a symlink
ending in `/datasets/public` (the old mount), creates it if absent, and refuses anything else. The
smoke test checks three redirects. Rolling back to a commit from before the rename would target the
old paths: its deploy script would rsync into a recreated `/var/www/virtual/janoelze/datasets/` and
then stop at the symlink check (`/datasets` is now a directory), leaving the live site untouched;
roll back by reverting commits on `main` instead.

CI (`.github/workflows/ci.yml`): every push and pull request runs `php -l`, `php bin/validate.php`
and `php tests/run.php` on PHP 8.3. A push to `main` (or a manual `workflow_dispatch` on `main`)
then runs the `deploy` job (environment `production`, concurrency group `deploy`, never cancelled
mid-run). It loads the repository secret `DEPLOY_SSH_KEY` (a dedicated ed25519 key, comment
`github-actions-datasets-deploy`, listed in the server's `~/.ssh/authorized_keys`), trusts a
pinned host key for aquila.uberspace.de, and runs `bin/deploy.sh`.

- Deploy by hand: `bin/deploy.sh` from a clean checkout, with an SSH identity that can log in
  as `janoelze@aquila.uberspace.de`.
- Roll back: check out (or re-run the workflow on) an older commit and deploy again; the rsync
  makes the server match that tree and the catalogue is rebuilt from it.
- Rotate the key: generate a new pair, replace the `github-actions-datasets-deploy` line in
  `~/.ssh/authorized_keys`, `gh secret set DEPLOY_SSH_KEY --repo janoelze/free-datasets < key`.
- Direct requests to `/free-datasets/index.php` return 404 on this host (same for other symlinked
  apps); use the clean URLs.
