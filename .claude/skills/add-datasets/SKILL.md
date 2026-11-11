---
name: add-datasets
description: >-
  Find, verify and add free datasets with free REST APIs to this catalogue (datasets/<slug>.json),
  and keep existing records correct. Use when asked to add, find, research or suggest new
  datasets or free/public APIs for the library, to work through the backlog, to re-verify or
  refresh records (bin/verify.php failures, stale examples, dead hosts), to fix
  bin/validate.php errors in dataset files, or to change the dataset schema.
---

# Adding and maintaining datasets

One record per file, `datasets/<slug>.json`, validated by `schema/dataset.schema.json` (read
the field descriptions), `schema/categories.json` and `src/Schema/DatasetRules.php`. Sibling
files: `template.json` (complete valid record), `research-brief.md` (sub-agent prompt),
`backlog.md` (verified leads), `check-format.php` (key order and formatting check).

Run everything from the repo root. Commands below were run as written; the placeholders are
`<slug>`, `<draft-dir>` and the shell variables `$URL`, `$DOCS`, `$SPEC` you set yourself. The
shell is zsh: list slugs literally, `php bin/verify.php a b`; `$SLUGS` is not word-split.

## 1. Inclusion bar

Include only if all hold, checked today with curl:

- Free: no credit card. A free-key signup is fine; freemium bait (tiny trial, then paywall) is not.
- A real HTTP API with public docs, alive today, over https.
- The documented endpoints answer as documented (not a Cloudflare challenge, not a 410/301 to
  a new version you have not checked, not stale data behind a live endpoint).

Drop dead hosts, paywalled APIs and undocumented scraping endpoints. Exception: an unofficial
endpoint that is valuable and widely used (NHL, MLB) goes in with `status: unstable`.

## 2. One or two datasets, or many?

- **One or two**: do it inline: section 4 (dedup), 5 (checklist), 6 (write the file), 8
  (done-check), and delete the candidate's line from `backlog.md`.
- **Many (5+)**: fan out. Split the work into domain buckets, for example
  science/space/weather/geo; government/economics/finance/health;
  culture/books/museums/music/language/food; developer/security/networking/transport/sports/fun;
  plus one bucket mining Hacker News (section 3). Launch one Sonnet sub-agent per bucket in a
  single message (Agent tool, `model: sonnet`), each with the prompt in `research-brief.md`
  filled in and its own draft directory. Ask for 12-16 candidates each. Then run the merge in
  section 7.

## 3. Where to look

- `backlog.md` first (verified leads), then github.com/public-apis/public-apis, api.data.gov,
  data.gov and data.europa.eu, APIs.guru (openapi-directory), awesome-public-datasets,
  national statistics offices and central banks, museum open-access programmes.
- Hacker News via the keyless Algolia API. Pipe any search into this printer
  (story id, points, title, url; for comment hits the id is the parent story and the points are
the comment's, so look the story up with the items call below):

```sh
curl -s 'https://hn.algolia.com/api/v1/search?query=free%20api%20for&tags=show_hn&numericFilters=points%3E25&hitsPerPage=8' \
  | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo "hits: ", $j["nbHits"], "\n"; foreach ($j["hits"] as $h) printf("%s\t%d\t%s\t%s\n", $h["story_id"] ?? $h["objectID"], $h["points"] ?? 0, $h["title"] ?? $h["story_title"] ?? "", $h["url"] ?? "");'
```

Useful queries (swap the URL in the command above):

- Discovery: `query=` "free api for", "free weather api", "free geocoding api", "open data api",
  "no api key", with `tags=show_hn&numericFilters=points%3E25`. Generic queries ("free api",
  "public api") are mostly SaaS noise: keep titles that mention api or dataset.
- Story for a known API: `?query=clevelandart.org&restrictSearchableAttributes=url&typoTolerance=false&hitsPerPage=5`
- Recommendations buried in threads: `?query=%22poetrydb%22&tags=comment&typoTolerance=false&hitsPerPage=5`
- Always add `typoTolerance=false` for names: without it "rxnav" matches RxJava and
  "poetrydb.org" matches python-poetry.org.
- One item with its points: `curl -s https://hn.algolia.com/api/v1/items/18988308 | head -c 300`

Good APIs often have 1-5 point stories; do not filter on points alone. Record a story in `hn`
when it is about the API itself or has a substantive thread about it.

## 4. Dedup before researching

```sh
ls datasets/ | grep -i -E 'poetry|canada'
grep -il 'bankofcanada.ca' datasets/*.json
```

Also search the catalogue API, which matches name, provider, summary, tags and endpoints.
Locally, start the dev server in the background (any free port; 8000 is often taken), then:

```sh
php -S localhost:8765 -t public public/index.php
curl -s 'http://localhost:8765/api/v1/datasets?q=museum&fields=slug,name'
```

The same query works on the live site at `https://endtime-instruments.org/datasets/api/v1/datasets?q=museum`
once it is deployed. Same API under another name or host counts as a duplicate.

## 5. Per-candidate verification checklist

```sh
UA='datasets-library/1.0 (+https://endtime-instruments.org/datasets/)'
URL='https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=2'
T=$(mktemp -d)
# a. alive, status, type, time, CORS and rate-limit headers
curl -sS -D - -o "$T/body" -A "$UA" -H 'Origin: https://example.com' -w '== %{http_code} %{content_type} %{time_total}s\n' "$URL" | grep -i -E '^(HTTP|access-control|x-rate|ratelimit|retry-after|== )'
head -c 1500 "$T/body"
# b. '*' or reflected origin? (a reflected value echoes this second origin)
curl -s -D - -o /dev/null -H 'Origin: https://other.example.org' "$URL" | grep -i 'access-control-allow-origin'
# c. is a User-Agent required? compare with (a)
curl -s -o /dev/null -w 'no UA: %{http_code}\n' -A '' "$URL"
curl -s -o /dev/null -w 'curl default UA: %{http_code}\n' "$URL"
```

- POST APIs: `curl -sS -o "$T/body" -w '%{http_code}\n' -A "$UA" -d 'data=...' "$URL"` (form
  body, same as `bin/verify.php` sends). JSON bodies need `-H 'Content-Type: application/json'`
  and the same header in `example.headers`.
- Never put `example.com` in a User-Agent: Overpass answers 406 to it (and to curl's and
  browsers' default strings). Use the `$UA` above in `example.headers` and in
  `required_headers[].example`.
- `time_total` above ~8 s: pick a lighter example; `bin/verify.php` times out at 15 s.
- Timeout or 5xx: wait 10-30 s and retry once before dropping. A TLS/DNS error from the
  sandbox is not proof the host is dead; retry with `curl -v` and report it.
- Watch for: hosts that no longer resolve; silent version moves (410, 301 to /v2); Cloudflare
  bot walls (HTML "Just a moment" with 403/503); keys that became mandatory (401/403 now);
  "not found" returned as HTTP 200 with an error body; data stale behind a live endpoint
  (check the newest timestamp).
- Docs, terms and license: fetch the pages and grep instead of recalling, e.g.
  `curl -s "$DOCS" | sed 's/<[^>]*>/ /g' | tr -s ' \n' | grep -i -E 'licen|rate|limit|commercial|attribut'`.
  Take endpoint parameter names from the docs, per endpoint (Cleveland uses q, name or title
  depending on the endpoint).
- OpenAPI: set `links.openapi` only after the URL returned a spec:
  `curl -s "$SPEC" | head -c 200` must start with `openapi`/`swagger`. Swagger UI pages hide the
  spec URL in their initializer script (Bank of Canada: `static/swagger/api-en.yml`).
- Numbers (rate limits, record counts, coverage years) only from a page or response you saw
  today; otherwise hedge in prose ("not re-confirmed") or leave the field out.

## 6. Field mapping: the judgement calls

Start with `cp .claude/skills/add-datasets/template.json datasets/<slug>.json`, set `slug` to
the file name, replace every value, and delete optional fields you cannot fill truthfully.

- `auth.type`: `none` when the documented endpoints work without any credential; `api_key`
  when a key is needed for ordinary use (then `key_in`, `key_name` and `links.signup` are
  required by the rules); `oauth2` only when user-level OAuth is unavoidable.
- `key_optional: true` only with `none`, when a free key raises limits (RxNav, Crossref).
  `demo_key` only for a publicly documented shared key (NASA `DEMO_KEY`); use it in the
  example and set `verification.with_key: true` plus a note.
- `verification.with_key: false` when a key is required and you only saw the unauthenticated
  error: record that status (401/403) in `http_status`; `bin/verify.php` then reports it as
  EXPECTED instead of FAIL.
- `cors`: from checks 5a/5b, run on the example URL and on one other endpoint. `any` = `*`;
  `reflect` = echoes your origin; `restricted` = a fixed origin list; `none` = no header;
  `unknown` = not confirmed, including when endpoints disagree (Cleveland sends `*` on some
  queries and nothing on others): say what you saw in `caveats`.
- `required_headers`: only headers without which requests fail (5c), with a working example.
- `rate_limit`: `summary` always ("Not documented." is fine). `requests`/`per`/`scope` only when
  a number is documented or sent in headers; they describe the keyless or free tier.
- `pagination`: what the main list endpoint does; `max_page_size` only if documented.
- `data.license`: `name` as the provider states it; `spdx` only for a real SPDX id;
  `commercial_use` `allowed`/`restricted`/`prohibited` only when the terms say so, else
  `unknown`. Separate data license from code license.
- `status`: `active`; `unstable` for unofficial, undocumented or flaky; `deprecated` once an
  end of life is announced.
- `tags`: 2-8 kebab-case topics; reuse existing tags (count them with
  `php -r '$t=[]; foreach (glob("datasets/*.json") as $f) foreach (json_decode(file_get_contents($f), true)["tags"] as $x) $t[$x] = ($t[$x] ?? 0) + 1; arsort($t); echo json_encode($t), "\n";'`).
  Never tags that repeat other fields: no `free`, `no-key`, `cors`, `json`, `api`.
- `category`: exactly one id from `schema/categories.json`, the one a user would browse for
  first. A new category needs the user's approval and at least three datasets that fit
  nowhere else; it changes the public API, so add it to `schema/categories.json` and update
  the category count asserted in `tests/HttpTest.php` in the same change.
- `related`: existing slugs only (the validator rejects unknown ones), and reciprocal: when
  the new record links to B, add the new slug to B's `related` too.
- `hn`: up to 5 stories, URL `https://news.ycombinator.com/item?id=N`, title and points as on HN.
- `example`: the exact request you ran; `response_excerpt` is a trimmed real response (cut
  long arrays and strings with `...`, keep the shape). Prefer a small, stable query.
- `added` and `verification.last_verified`: today.

## 7. Merging fan-out drafts

1. Read every agent's report. For each draft run `php bin/validate.php <draft-dir>/<slug>.json`.
2. Dedup across buckets and against the catalogue by API host:

```sh
php -r '$h=[]; foreach (array_slice($argv,1) as $f) { $d=json_decode(file_get_contents($f),true); $h[parse_url($d["api"]["base_url"], PHP_URL_HOST)][]=basename($f); } foreach ($h as $host=>$fs) if (count($fs)>1) echo $host, ": ", implode(" ", $fs), "\n";' datasets/*.json
```

   (append the draft paths, e.g. `"$DRAFTS"/*/*.json`). It prints nothing when every host is
   unique. A shared host is a strong hint, not proof (one host can serve two distinct
   datasets), and a duplicate can hide behind a different host or name, so also compare names
   and providers.
3. Spot-check one claim per draft with curl yourself: agents state numbers from memory.
4. Move keepers into `datasets/`, add reciprocal `related`, remove their `backlog.md` lines,
   add verified-but-skipped candidates to `backlog.md`.

## 8. Done-check (every change)

```sh
php bin/validate.php
php .claude/skills/add-datasets/check-format.php datasets/<slug>.json
php bin/verify.php <slug> <slug>
php tests/run.php
```

- `validate`: `OK: N files valid.` for the whole directory. `check-format.php` must print
  `OK`; `--fix` rewrites a file into canonical form (2-space JSON, keys in schema order,
  trailing newline). Use it only on files you touched; a few older files are not canonical.
- `verify`: every touched slug `OK` (or `EXPECTED (no key)`).
- Reciprocal `related` check (prints every one-way link; none may involve a slug you touched):

```sh
php -r '$a=[]; foreach (glob("datasets/*.json") as $f) { $d=json_decode(file_get_contents($f),true); $a[$d["slug"]]=$d["related"] ?? []; } foreach ($a as $s=>$rs) foreach ($rs as $r) if (!in_array($s, $a[$r] ?? [], true)) echo "$s -> $r is not reciprocal\n";'
```

Commit only when the user asks. CI validates and tests every push and deploys `main` to the
server, so never push to `main` without the user's explicit go-ahead.

## 9. Maintenance

- Re-verify everything: `php bin/verify.php --quiet` (prints failures only). After fixing,
  `php bin/verify.php --write` sets `last_verified` and `http_status` in every file that got
  any HTTP response, failures included: re-run until clean and review `git diff` before
  committing.
- For each failure, in order: retry once after a pause (transient 5xx and timeouts: Ensembl,
  Gutendex, Overpass at capacity); check headers (406/403 often means a blocked User-Agent);
  if the request is slow or the version moved, fix `example` (and `endpoints`) after
  re-checking with curl; if the API now needs a key, switch `auth` and `with_key`; if it is
  unofficial or keeps flaking, `status: unstable`; if end of life is announced,
  `status: deprecated`; if the host is gone, delete the file and remove its slug from every
  `related` list (`grep -l '"<slug>"' datasets/*.json`).
- Validation errors print as `file: /json/pointer: message`; the pointer is the field to fix.

## 10. Changing the schema

One change, all of these together, then the done-check on every file:

1. `schema/dataset.schema.json`: the new field with a `description`, in the right position.
2. `src/Schema/Validator.php` supports only the keywords in its `KEYWORDS` list and throws on
   any other; add support plus a test in `tests/ValidatorTest.php` before using a new keyword.
3. Semantic rules that JSON Schema cannot express go in `src/Schema/DatasetRules.php` with a
   test in `tests/RulesTest.php`.
4. The OpenAPI document is generated from the schema (`src/Api/OpenApi.php`); a new
   enumerated facet also needs `Catalog::FACETS`/`ENUM_PATHS` and `src/Api/ListParams.php`.
   Check that `curl -s http://localhost:8765/api/v1/openapi.json` still renders.
5. Update `tests/fixtures/` and migrate every file in `datasets/` (script it with
   `Datasets\Json::encodeFile` on `json_decode($json, false)` to keep formatting), then
   `template.json` and this skill. The template lives outside `datasets/` so the catalogue never
   loads it; validate it against a scratch copy of the catalogue (its `related` slugs must
   resolve):

```sh
T=$(mktemp -d) && cp datasets/*.json "$T"/ && cp .claude/skills/add-datasets/template.json "$T/your-dataset-slug.json" && php bin/validate.php "$T/your-dataset-slug.json"; rm -rf "$T"
```
