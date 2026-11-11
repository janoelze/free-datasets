# Architecture

Plain PHP 8.3, no database, no composer, no build tooling. Each dataset is one file
`datasets/<slug>.json`, validated against `schema/dataset.schema.json` (JSON Schema 2020-12)
plus semantic rules. The app serves a read-only REST API under `/api/v1` and HTML pages from
`templates/`.

```
public/index.php        front controller (tiny); public/.htaccess rewrites non-files to it
src/bootstrap.php       PSR-4 autoloader for Datasets\ -> src/, loads src/helpers.php
src/App.php             routing table, dispatch, common headers (CORS, Cache-Control, ETag/304)
src/Config.php          paths + env (APP_DATASETS_DIR, APP_CACHE_FILE, APP_BASE_PATH, APP_PUBLIC_URL, APP_TEMPLATES_DIR)
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
bin/validate.php  bin/build.php  bin/verify.php
tests/run.php     tests/*Test.php  tests/fixtures/
```

## Running things

```sh
php -S localhost:8000 -t public public/index.php     # dev server (serves public/ files too)
APP_BASE_PATH=/datasets php -S localhost:8000 -t public public/index.php   # mimic production prefix
php bin/validate.php [--quiet] [--dir=PATH] [FILE...] # "file: /pointer: message"; exit 1 on errors
php bin/build.php                                     # validate, then write var/catalog.json
php bin/verify.php [slug...] [--write]                # execute each example request
php tests/run.php [filter]                            # all tests (starts php -S on free ports)
```

Production: Apache with docroot path `/datasets` symlinked to `public/`. The base path is
detected from `SCRIPT_NAME` (`/datasets/index.php` -> `/datasets`); under `php -S` it is `""`.
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

- `url('/d/open-meteo', ['tab' => 'x'])` -> `/datasets/d/open-meteo?tab=x` (root-relative, base-path aware;
  list values are joined with commas, `null`/`''` dropped, booleans become `true`/`false`)
- `abs_url('/d/open-meteo')` -> `https://endtime-instruments.org/datasets/d/open-meteo`
- `asset('assets/style.css')` -> `/datasets/assets/style.css?v=<mtime>` (file in `public/`;
  `.htaccess` caches these for a year)
- `e($value)` -> HTML-escaped string
- The underlying object is `Datasets\Url::current()` (also passed to templates as `$urls`).

## Templates

`templates/<name>.php` are plain PHP files rendered with `extract()`ed variables; a missing
template yields a plain-text fallback. All templates receive:

- `$catalog` (`Catalog`), `$request` (`Http\Request`: `path`, `query`, `basePath`, `publicUrl`, `queryValue($name)`), `$urls` (`Url`)

| Route | Template | Extra variables |
|---|---|---|
| `/` | `home.php` | `$params` (`ListParams`, lenient, from the query string: same filters as the API), `$results` (`ResultPage`), `$facets` (counts over the filtered set), `$categories` (with counts), `$tags` (`tag => count`) |
| `/d/{slug}` | `dataset.php` | `$dataset` (array), `$category` (array with count), `$related` (list of datasets), `$apiUrl` (absolute) |
| `/api` | `api.php` | `$apiBase`, `$openapiUrl`, `$schemaUrl` (absolute) |
| `/about` | `about.php` | – |
| anything else, unknown slug | `404.php` (status 404) | `$path` |

HTML responses get `Cache-Control: public, max-age=300`, an ETag and `X-Content-Type-Options: nosniff`.

## REST API summary

All under `{base}/api/v1`, GET/HEAD/OPTIONS only, no auth, `Access-Control-Allow-Origin: *`,
`Cache-Control: public, max-age=300`, strong ETag (`If-None-Match` -> 304), pretty JSON.
Errors are RFC 9457 `application/problem+json` (`type`, `title`, `status`, `detail`,
`instance`, plus `errors: [{parameter, detail}]` on 400). Unknown query parameters on
`/datasets` and `/facets` are rejected with 400 so typos do not silently return everything.
URLs with a trailing slash 308-redirect to the slash-less form.

- `/api/v1`, `/datasets`, `/datasets/{slug}`, `/categories`, `/categories/{id}`, `/tags`,
  `/facets` (accepts the same filters as `/datasets`), `/schema`, `/openapi.json`
- `/llms.txt`, `/robots.txt` (outside `/api/v1`)

The OpenAPI document is generated from `schema/dataset.schema.json` and `schema/categories.json`
(`components.schemas.Dataset` is the schema verbatim minus `$schema`/`$id`), so it cannot drift.
