# Backlog

Leads that looked worth adding but are not in the catalogue yet. Each line says what was
seen at the last check. Re-verify before adding (section 5 of SKILL.md); delete the line when
the record is added. If a lead turns out to be dead or paywalled at re-check, delete it too
and say why in your report.

## Keyless at last check (2026-11-11), not yet written up

- FreeToGame (200, CORS `*`)
- Deck of Cards API (200, CORS `*`)
- ORCID public API (200, CORS `*`, XML by default)
- Zenodo API (200, CORS `*`, 30 requests/min in headers, rejects an empty User-Agent)
- Blockchair (200, CORS `*`; docs and limits not read)
- Blockstream Esplora (200, CORS `*`; same API shape as `mempool-space`)
- Discogs API (artist and search returned 200 without auth; docs and terms not read)
- Openwhyd (tracks and playlists work, no CORS header, no licence found)
- OurAirports (static CSV on GitHub Pages; would be `style: static-file`)
- INPE queimadas (CSV directory, not an API; `static-file` at best)
- YouTube oEmbed (one narrow endpoint; utility rather than dataset)

## Could not be tested from the sandbox (retry elsewhere)

- Bundestag DIP API (TLS issuer not in the sandbox trust store; free public key documented)
- IBGE servicodados (localidades, agregados): timed out four times; `ibge-sidra` is in
- Jikan (MyAnimeList): every request timed out
- FishBase / SeaLifeBase (TLS "unrecognized name" on the rOpenSci host)
- Dailymotion: keyless host answers but is documented as "Legacy API"; check whether v2 is keyless

## Free key required (only the unauthenticated error was seen)

- Transitland (docs page is JavaScript-rendered; key name and limits unconfirmed)
- Semantic Scholar (keyless calls return 429 asking for a key)
- DPLA (docs host behind a bot wall)
- Pl@ntNet (docs are a JavaScript app)
- Biodiversity Heritage Library
- eBird, IUCN Red List, Xeno-canto v3, GeoNames (free username), Solar System OpenData
- REST Countries v5 (v3.1 is retired; v5 returns 401 without a key)
- TMDB, OMDb (CC BY-NC 4.0), Last.fm, Freesound, AcoustID, OpenSubtitles
- PageSpeed Insights (keyless quota is exhausted on the shared project), Chrome UX Report API
- The Guardian Open Platform
- RAWG, IGDB (via Twitch)

## Not attempted yet

- Destatis GENESIS, Deutsche Bahn open data, Berlin Open Data, Lebensmittelwarnung, MOSMIX
- Portal da Transparência (free key), ENTSO-E Transparency (free key), Open Power System Data
- Natural Earth, Wikimedia Enterprise free tier, Abfallnavi

## Rejected (do not re-add without new evidence)

- TheCocktailDB: docs say apps need a paid Premium key
- Lorem Picsum: placeholder images, not a dataset
- Papers with Code: API redirects to Hugging Face, gone
- BoardGameGeek XML API: now 401 without a token
- DBLP, Trakt, Library of Congress loc.gov: bot wall / Cloudflare challenge for non-browser clients
- Metaculus: API now requires an authenticated user
- airplanes.live: manual approval by e-mail
- Lobsters: robots.txt disallows crawlers and AI use; no documented API
- HTTP Archive: BigQuery only, no REST API
- Wikiquote: same MediaWiki API as `wikipedia-api`
