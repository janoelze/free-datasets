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

## Astronomy leads blocked on 2026-11-13 (retry when CDS is back)

- SIMBAD TAP and VizieR TAP (CDS Strasbourg): CDS reports a cybersecurity incident on its Strasbourg
  servers. `https://simbad.cds.unistra.fr/simbad/sim-tap/sync` (also `/simbad/sim-tap/capabilities`
  and `/simbad/`) answers 404 with a static "Page unavailable" page, and simbad.cds.unistra.fr/
  shows the notice "access to SIMBAD, VizieR, Aladin image surveys, X-Match and other services hosted
  on our main site cds.unistra.fr is currently degraded". `https://tapvizier.cds.unistra.fr/TAPVizieR/tap/sync`
  timed out at 40 s in two runs (and `/capabilities` after 25 s and 206 s), while vizier.cds.unistra.fr/
  answered 200. Mirrors answer: `https://simbad.cfa.harvard.edu/simbad/sim-tap/sync` (200 in 0.4 s,
  data ingested up to 2026-10-14) and `https://simbad.idia.ac.za/simbad/sim-tap/sync` (200 in 4.6 s),
  but they are stop-gaps, so SIMBAD was not added. The canonical URL and VizieR need a re-check; the
  old simbad.u-strasbg.fr name no longer resolves.

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

## From list screening on 2026-11-11 (awesome-public-datasets, data.gov popularity, Kaggle top downloads)

Working and keyless at the check, not yet written up:

- Life sciences: EBI BioStudies (ArrayExpress), EMDB, PDBe (near-duplicate of `rcsb-pdb`), ENCODE,
  GloBI, Rfam, NeuroMorpho, OpenNEURO (GraphQL), TCIA NBIA, MeSH lookup,
  NHS ODS organisation directory, PhysioNet (slow, undocumented), STRING, EBI Search, InterPro
- Health portals: healthdata.gov (Socrata), CMS OpenPayments (DKAN), CMS Medicare Coverage API
- National open-data portals with a CKAN/udata API: Austria, Chile, Finland, Hong Kong (also
  `api.data.gov.hk` historical archive; strongest of these), Ireland, Israel, Italy, Luxembourg,
  Netherlands, Romania, Switzerland, Ukraine, Uruguay; UNESCO Data Hub (Opendatasoft);
  Inter-American Development Bank (CKAN)
- catalog.data.gov's new search API (`/search`, OpenAPI at `/openapi.json`; the CKAN paths are gone)
- US federal: USBR RISE, BLS Public Data API v2, USDA NRCS Soil Data Access (POST/SQL),
  FCC and VA Socrata portals; FBI Crime Data Explorer and NREL/NLR (api.data.gov key, DEMO_KEY worked)
- Research repositories: Figshare
- Museums: Minneapolis Institute of Art (undocumented)
- Renfe open data (CKAN, mostly static timetables)

Retry (failed for reasons that may be local or transient): ConceptNet (502), Software Heritage
(host did not resolve), FAOSTAT (API path not found), Open Targets (GraphQL POST not tried),
Allen Brain Atlas (works over http only), World Bank Climate Data API, Science Museum Group and
Walters (unreachable from the sandbox), CFPB complaints and NHTSA CrashAPI (403 to curl),
`college-scorecard` is in but its DEMO_KEY example was rate-limited at merge: re-verify

Free key required, not tested: NASS Quick Stats, SEER, Copernicus CDS, KNMI, Te Papa, ACLED,
UCDP, OpenSanctions, Libraries.io, OpenCorporates, dados.gov.br, YouTube Data API, Yelp Fusion

## Rejected (do not re-add without new evidence)

- Self-promotional micro-APIs that list aggregators pick up (watch prices, coworking price index,
  AI pricing files, theme-park news, tariff snapshots, derived indicator mirrors): not durable public data
- Single-city open-data portals, unless exceptional (NYC is in): one record per platform or
  national portal instead
- Kaggle public API: docs require a token; keyless responses are undocumented behaviour
- Road511: the "free" key is a 14-day trial
- Cooper Hewitt: API host is gone; abuse.ch URLhaus: now 401; HEPData and Brooklyn Museum: bot wall

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
- HSC-SSP (Subaru Hyper Suprime-Cam survey archive): data access requires registration and the data are non-commercial
