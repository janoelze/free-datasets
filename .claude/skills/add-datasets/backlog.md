# Backlog: verified candidates not yet added

Runner-ups that passed a live check during the first research pass (2026-11-11) but did not
make the first 92. Treat each line as a lead, not as a verified record: run the full checklist
in SKILL.md again before writing a file, because hosts move, versions change and keys become
mandatory. The notes in parentheses are things to check, not facts to copy.

When you add one, delete its line here in the same change. When one fails the inclusion bar on
re-check, delete it too and say why in your report.

## Keyless at first check

- TheCocktailDB (check which endpoints the shared test key covers)
- Wiktionary REST (Wikimedia host: send a descriptive User-Agent)
- NCBI E-utilities (check whether a free key only raises limits, i.e. `key_optional`)
- Statistics Norway PxWeb (check the current API version and the POST query format)
- Our World in Data
- ChEMBL
- USGS Water Services (NWIS)
- NASA Image and Video Library (separate host from api.nasa.gov; check that it is keyless)
- Cloudflare DNS over HTTPS (overlaps `google-dns-doh`; link the two in `related`)

## Free key required at first check

- Transitland
- Pirate Weather
- Semantic Scholar (check whether keyless use still works at a shared rate)
- DPLA (Digital Public Library of America)
- Pl@ntNet
- OpenAQ v3 (v2 is gone; v3 needs a key)
