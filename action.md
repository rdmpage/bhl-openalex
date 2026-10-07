# BHL in OpenAlex: what BHL (and OpenAlex) can do

OpenAlex harvests BHL's OAI-PMH feed (<https://www.biodiversitylibrary.org/oai>, `oai_dc`). BHL
content then appears in OpenAlex with several problems. This document lists them and, for each,
what BHL can change in its feed now and what needs a change by OpenAlex.

BHL is starting to issue DOIs, but not for everything. Many parts will not have a DOI any time
soon, so the fixes below are designed to work without DOIs.

The analysis is based on the OpenAlex code
([`ourresearch/openalex-walden`](https://github.com/ourresearch/openalex-walden), commit
`6cd36670`) and on example records in this repository (see `README.md`).

## The problems

1. **BHL content is not treated as published.** BHL locations are labelled `submittedVersion`
   (`is_published: false`), although BHL holds scans of the printed version of record.
2. **BHL content is often not treated as open access.** The licence is sometimes missing.
3. **Works often lack a journal.** When a work has no Crossref DOI, OpenAlex shows "Biodiversity
   Heritage Library" as the work's source instead of the journal.
4. **Abstracts that read "Volume: 54".** OpenAlex uses the first `dc:description` as the
   abstract, and BHL puts the `Volume:` / `Start Page:` / `End Page:` lines there.
5. **Changes are not seen unless datestamps change.** OpenAlex harvests incrementally by
   datestamp, and many BHL records have datestamps from 2013–2017.
6. **Pre-1900 dates are dropped.** OpenAlex ignores dates earlier than 1900 in repository records.
   Only OpenAlex can fix this.
7. **Smaller issues:**
   - languages are given as names ("German", "Latin") rather than ISO codes;
   - author identifiers are lost in oai_dc;
   - licences sometimes disagree between BHL's Crossref deposits and its OAI records (for example,
     part/241051 is CC BY-NC-SA in Crossref but CC BY 4.0 in `dc:rights`).

---

## 1. Making BHL content "published"

### How OpenAlex decides

OpenAlex decides a location's version in `detect_version_from_metadata` (`Repo.py:159`; the backfill
path `RepoBackfill.py:277` uses the same rules):

1. If the OAI identifier prefix is on a hard-coded list of repositories, the location is
   `acceptedVersion`. BHL is not on the list.
2. Otherwise OpenAlex lowercases the **entire record** and searches it with regular expressions:
   - **checked first:** `accepted.?version`, `version.?accepted`, `accepted.?manuscript`,
     `peer.?reviewed`. Any match makes the location `acceptedVersion`;
   - **then:** `publishedversion`, `published.*version`, `version.*published`. Any match makes it
     `publishedVersion`.
3. Anything else defaults to **`submittedVersion`**. Every BHL record currently ends up here.

Downstream, `is_published` simply means `version == publishedVersion`. One other rule marks
records as published when they come from a journal's own endpoint. BHL is a repository, so that
rule does not apply.

**So BHL needs to add one string to each record: `publishedVersion`.**

### The Dublin Core to emit

Use the standard OpenAIRE token in `dc:type`. For example, part/248626 would become:

```xml
<oai_dc:dc ...>
  <dc:title>The Bark and Ambrosia Beetles of North and Central America ...</dc:title>
  <dc:creator>Wood, Stephen L.</dc:creator>
  ...
  <dc:type>Article</dc:type>
  <dc:type>text</dc:type>
  <dc:type>info:eu-repo/semantics/publishedVersion</dc:type>
  ...
</oai_dc:dc>
```

Notes:

- **Emit it on every record:** parts, items and titles. All of BHL is scanned printed material.
- **It does not change the work type.** OpenAlex maps the token to "article", which ties with
  `Article` / `text`. More specific genres (`Chapter`, `Book`, …) still win.
  - Side effect: when types tie, OpenAlex prefers the `info:eu-repo` value as the location's
    `raw_type`, so `raw_type` would read `info:eu-repo/semantics/publishedVersion` instead of
    `Article`. This is cosmetic.
  - To keep `raw_type` meaningful, article parts can also emit `info:eu-repo/semantics/article`,
    placed before the version token.
- **Do not use the COAR form** (`http://purl.org/coar/version/c_970fb48d4fbd8a85`). It does not
  contain the string "publishedversion", so OpenAlex would still default to `submittedVersion`.
- **Avoid "peer reviewed" and "accepted version" anywhere in a record,** including titles and
  subjects. Those patterns are checked first and would make the location `acceptedVersion`.
- **Bump the datestamps** of every changed record. Otherwise OpenAlex will not re-harvest them.
  Records that originally came in through OpenAlex's backfill path may need OpenAlex to
  re-harvest the whole BHL endpoint.

### Effect on which location OpenAlex shows first

OpenAlex ranks a work's locations and shows the first one as `primary_location`. A
`publishedVersion` repository copy ranks higher than a `submittedVersion` one. In practice:

- **Works with a Crossref DOI:** the publisher's Crossref location still wins. No change.
- **Works without a DOI:** BHL is already primary today. The work as a whole will now show
  `is_published: true`.
- **Works with other repository copies** (Zenodo, university repositories): BHL will now rank
  above them, which is reasonable because BHL holds the scan of the printed article.

BHL can make this change now. It does not depend on DOIs or on any change by OpenAlex, and it does
not get in the way of the journal fix (section 3).

### What to ask OpenAlex

- **Treat BHL as a source of published versions.** The simplest way is for OpenAlex to default the
  BHL endpoint to `publishedVersion` rather than relying on a keyword in each record, as it
  already does per-repository for `acceptedVersion`.
- **Re-harvest the BHL endpoint** once BHL's feed has changed.

---

## 2. Making BHL content open access

### How OpenAlex decides

A repository location is open access (`is_oa: true`) if **either** (`Repo.py`, the `is_oa`
column):

- its normalised licence starts with `cc`, or is `other-oa` or `public-domain`; **or**
- its OAI identifier's host is on a hard-coded list of "trusted" open hosts
  (`TRUSTED_HOST_IS_OA_EXPR`): arXiv, PubMed Central, Europe PMC, bioRxiv, medRxiv, Zenodo and a
  few others. Everything from these hosts is OA regardless of licence. **BHL is not on the list.**

The licence comes from `dc:rights`:

1. OpenAlex takes the first `dc:rights` value containing `creativecommons.org`; otherwise it takes
   the first `dc:rights` value.
2. That value is normalised by substring matching. CC URLs map to `cc-by`, `cc-by-nc-sa` and so
   on. Text containing "public domain" maps to `public-domain`. Anything else is NULL.

So a BHL location is OA only if its record carries a CC licence URL, or text containing "public
domain", **and OpenAlex holds a copy of the record from after that was added.**

### What the data shows

OpenAlex has **929,332** BHL locations (endpoint `q6caunfjwsunh6wiqwpg`). **506,267 (54%)** have
no licence and are therefore not OA. They are almost all from the normal harvest path
(`provenance: repo`), not the older backfill.

We took a random sample of 60 of those null-licence locations and fetched each record from BHL's
live OAI feed (2026-10-07):

| Current `dc:rights` in BHL's feed | Sample | Rough total | Why OpenAlex has no licence |
|---|---|---|---|
| Title records: no `dc:rights` at all | 19 | ~160k | BHL emits no rights for titles |
| CC licence URL present | 10 | ~85k | **OpenAlex's copy is stale** (see below) |
| "NOT_IN_COPYRIGHT", "NOT IN COPYRIGHT", "Not in copyright. The BHL knows of no copyright restrictions…", "…believes that this item is not in copyright" | 13 | ~110k | Wording does not contain "public domain" |
| "Not provided. Contact Holding Institution to verify copyright status." | 10 | ~85k | No licence stated |
| "In copyright…" / "Permission to digitize granted…" with no licence URL | 5 | ~40k | No licence stated |
| Parts with no `dc:rights` (e.g. SciELO parts hosted elsewhere) | 3 | ~25k | No licence stated |

The totals are scaled from a sample of 60 and are only indicative.

By contrast, BHL locations that OpenAlex *does* mark `public-domain` have the wording "Public
domain. The BHL considers that this work is no longer under copyright protection." So the same
status is written in at least five different ways in BHL's feed, and only one of them is
recognised.

### Why OpenAlex's copy is stale

Example: part/248626. Today's record has a CC BY-NC-SA URL, but OpenAlex has no licence for it.

- The copy OpenAlex holds is old. Its publisher reads "[Provo, Utah]Brigham Young
  University,1976-1992.", while the feed now gives "[Provo, Utah], Brigham Young University,
  1976-1992". The same old publisher formatting appears on all the example records, including
  ones with 2021 datestamps. So BHL's output has changed without the datestamps changing.
- **A part inherits its licence from its volume.** In BHL's `vwSegment` view, `RightsStatus` and
  `LicenseUrl` come from the parent volume (`Book`) when it has one, and from the part only
  otherwise.
- **A part is listed for harvesting only when the part itself changes.**
  `OAIIdentifierSelectSegments` filters `from`/`until` on `Segment.LastModifiedDate`. A change to
  the volume, including adding a licence, does not put its parts back in the harvest.
- **Even the volume's own datestamp did not change.** Volume item/33485 has the CC URL today, but
  its datestamp is still 2011-03-08, and OpenAlex's copy of item/33485 also has no licence. The
  licence appears to have been added without updating `LastModifiedDate`, for example by a bulk
  update.

OpenAlex harvests incrementally by datestamp, so it never sees changes like these.

Two smaller datestamp problems in BHL's feed:

- **GetRecord and ListIdentifiers disagree.** part/248626 is `2017-11-16T18:32:03Z` in GetRecord
  but `2017-11-17T00:32:03Z` in ListIdentifiers. One of them is local time labelled as UTC.
- **The part datestamp shown is the later of the part's and the volume's dates.** But harvesting
  selects on the part's date alone, so a part can show a datestamp that a `from`/`until` harvest
  for that date will not return.

The reverse also happens. Some records that OpenAlex marks `public-domain` now say "Not provided"
(item/276686) or have no rights at all (title/10747). OpenAlex is showing licence information
BHL has since changed.

### What BHL can do in its feed

1. **Use one machine-readable value for public domain.** For everything BHL treats as not in
   copyright, emit the Public Domain Mark URL first:

   ```xml
   <dc:rights>http://creativecommons.org/publicdomain/mark/1.0/</dc:rights>
   <dc:rights>Public domain. The BHL considers that this work is no longer under copyright protection.</dc:rights>
   ```

   OpenAlex prefers a `dc:rights` value containing `creativecommons.org`. This URL normalises to
   `public-domain` and so sets `is_oa`. The text form also works, but only if it contains the
   words "public domain". "NOT_IN_COPYRIGHT" looks like an internal code leaking into the feed.
2. **Put the licence URL first** where there is one. OpenAlex already prefers CC URLs, so this is
   a safeguard.
3. **Emit rights on title records,** or accept that titles will only become OA through the
   trusted-host route below.
4. **Make datestamps reliable:**
   - when a volume's rights, licence or metadata change, update `LastModifiedDate` on its parts
     too, or select parts for harvesting on the later of the part's and the volume's dates;
   - make bulk updates set `LastModifiedDate`;
   - report the same datestamp, in UTC, from GetRecord and ListIdentifiers.
5. **After changing the feed, bump the datestamps of every affected record,** or ask OpenAlex to
   re-harvest the endpoint from scratch. Without this, none of the above reaches OpenAlex.
6. **Make licences agree between BHL's Crossref DOIs and its OAI feed.** For example, part/241051
   is CC BY-NC-SA in Crossref but CC BY 4.0 in `dc:rights`.

### What to ask OpenAlex

- **Add BHL to the trusted open hosts** (`TRUSTED_HOST_IS_OA_EXPR`, by OAI identifier host
  `biodiversitylibrary.org`). Everything on BHL is free to read, which is OpenAlex's definition of
  OA. This one change makes all ~929k BHL locations OA, including those with "Not provided",
  "In copyright" (digitised with permission) or no rights statement, which no feed change can fix.
  The licence field would still show the specific licence where BHL gives one.
- **Re-harvest the BHL endpoint from scratch.** OpenAlex's copies are stale in both directions.

## 3. Giving works their journal

### How OpenAlex decides

A work's "source" (journal) is the source of its `primary_location`, the best-ranked of its
locations. Two separate things go wrong for BHL content.

**1. A BHL location's source is always "Biodiversity Heritage Library".** In
`CreateLocationsWithSources.ipynb`, a repository location gets the source mapped to its harvesting
endpoint (`endpoint_to_source`). Nothing in the record is used to choose the source:

- ISSNs in `dc:identifier` are extracted, but ISSN-to-journal matching is applied only to
  Crossref/MAG-style locations and, since August 2026, to DataCite DOIs. It is not applied to
  repository records.
- `dc:source` is stored as `raw_source_name` only. Matching by journal name happens only for
  locations that have no source, which never applies to a repository location.
- `aggregator_source_overrides` can re-point a whole OAI namespace or prefix to one source. It
  cannot send individual BHL parts to different journals.

**2. Journal locations from elsewhere lose the ranking.** Locations are sorted by a `sort_score`
(`CreateWorksBase.ipynb`):

| sort_score | location |
|---|---|
| 0–2 | Crossref |
| 3–4 | `publishedVersion` |
| 5–6 | `acceptedVersion` |
| 7–8 | `submittedVersion` (BHL today: 8) |
| 9 | no version |

For BHL works without a Crossref DOI, the only journal location usually comes from the frozen
Microsoft Academic Graph (MAG). MAG locations have no version, so they score 9 and lose to BHL's 8.
The "publisher before repository" tie-break is never reached. MAG stopped in 2021, so BHL articles
added since then have no journal location at all.

So a work gets its journal only if it has a Crossref DOI. Examples:

| BHL part | DOI | Primary source in OpenAlex |
|---|---|---|
| 110457, 175696 | publisher's Crossref DOI | journal |
| 241051 | BHL's own Crossref DOI (10.5962/p.241051) | journal (*Contributions in science*) |
| 76326, 248626 | none | BHL (journal only on the MAG location) |
| 424526 | Zenodo (DataCite) DOI | BHL (no journal anywhere) |

### What the data shows

From the OpenAlex API, 2026-10-07:

- **150,186** works have BHL as their primary source, and **100,001** of these are articles.
- **64,570** of them (60,623 articles) already have a journal location, almost always from MAG,
  which loses the ranking. A ranking change would fix these at once.
- **71,239** of them have a DOI but still have BHL as primary. In a sample of 200, 190 were Zenodo
  DataCite DOIs uploaded by Plazi from BHL in 2025 ("Uploaded by Plazi from the Biodiversity
  Heritage Library"). These records carry no journal ISSN, and a DataCite location with no
  version scores 9, so it would lose to BHL anyway.

We took a random sample of 200 articles with BHL as primary. **75 (38%) have no journal location
at all.** We looked up the journal of each of the 64 that have a BHL part, using BHL's own
metadata (container title, then the title's ISSN):

| | Parts | Share |
|---|---|---|
| BHL has an ISSN, and OpenAlex has a journal with that ISSN | 29 | 45% |
| BHL has an ISSN, but OpenAlex has no such journal (e.g. *Hedwigia*, *Journal de conchyliologie*, *Jahrbücher der Deutschen Malakozoologischen Gesellschaft*) | 20 | 31% |
| BHL has no ISSN for the journal | 15 | 23% |

The other 11 of the 75 had only BHL item or title locations, not parts. This is a small sample, so
the shares are only indicative.

Two data issues showed up:

- **Items bound with more than one title.** Part 315631 has container title *Bulletin of the
  Museum of Comparative Zoology*, but its item's primary title is *Animal keepers' forum*. The
  journal ISSN must come from the part's container title (`PreferredContainerTitleID`), not from
  the item's primary title.
- **Journals in OpenAlex without an ISSN.** *Alytes* (part 385640; ISSN-L 0753-4973) exists in
  OpenAlex as [S4306501495](https://openalex.org/sources/S4306501495) with no ISSN, so no ISSN match
  can find it. The list BHL sends OpenAlex should include journals like this, not just missing
  ones. BHL's own record for *Alytes* also has a malformed duplicate ISSN (`07534973`).
- **Successor titles.** ISSN 0037-8844 (*Atti della Società Italiana di Scienze Naturali…*)
  matches OpenAlex's *Natural History Sciences*, the journal's current name. Some old journals in
  OpenAlex have very few works (e.g. *The Ottawa naturalist*, 2 works).

### What BHL can do in its feed

BHL already knows the journal for each part. `OAIRecord.LoadSegment` sets `JournalTitle =
segment.ContainerTitle`, and titles carry ISSNs, including a Linking ISSN. But none of this is
written to oai_dc: `Convert.ToString()` has "No mapping" for Source. BHL should emit it:

```xml
<dc:title>The Bark and Ambrosia Beetles of North and Central America ...</dc:title>
...
<dc:source>Great Basin naturalist memoirs, 6: 1-1359, ISSN 0160-239X</dc:source>
<dc:identifier>https://www.biodiversitylibrary.org/part/248626</dc:identifier>
<dc:relation>https://www.biodiversitylibrary.org/bibliography/8018</dc:relation>
```

- **`dc:source`** is the Dublin Core element for the resource this one is part of. Give journal,
  volume, pages and the ISSN. OpenAlex reads `dc:source` as `raw_source_name` today.
- **The ISSN** should be the Linking ISSN (ISSN-L) of the part's **container title**, not of the
  item's primary title.
- **`dc:relation`** links to the journal's BHL record, which carries all its ISSNs. This makes the
  link to the journal unambiguous.
- **Do not put the journal's ISSN in the part's `dc:identifier`.**
  - OpenAlex currently extracts ISSNs only from `dc:identifier`, and BHL already uses
    `urn:ISSN:` there for *title* records, where it is correct.
  - On a *part* record it would say the article is the journal, which is wrong and could mislead
    other harvesters.
  - It would also achieve nothing today, because OpenAlex does not match repository ISSNs to
    journals. Since OpenAlex must change its code anyway (ask B), it can read the ISSN from
    `dc:source`.
- **This does not change the source on its own.** It supplies the evidence OpenAlex needs for ask B,
  and costs nothing.

#### OpenAlex reads only `oai_dc`, not MODS

BHL already serves MODS (`OAIMODS/` in BHL's code). MODS can express the journal properly
(`<relatedItem type="host">` with title, ISSN, volume and pages). But there is no evidence that
OpenAlex reads it, and good evidence that it does not:

- **OpenAlex's documentation** says the harvester sends "`ListRecords` with `metadataPrefix=oai_dc`,
  plus `set=` when the endpoint is registered with one"
  ([help.openalex.org/data/sources/repositories](https://help.openalex.org/data/sources/repositories/)).
- **The harvester** (`ourresearch/openalex-ingest`, `repositories.py`) stores a `metadata_prefix`
  for each endpoint, defaulting to `oai_dc`. So one prefix per endpoint is technically possible.
- **The parser** (`openalex-walden`, `notebooks/ingest/Repo.py`) has a fixed schema of `oai_dc`
  elements (`dc:title`, `dc:creator`, `dc:source`, `dc:identifier`, `dc:rights`, …). There is no
  MODS, MARCXML or other format parser anywhere in `openalex-walden`. A MODS harvest would yield
  records with no title and they would be filtered out.
- **The stored BHL records** have fields (e.g. `raw_type: "Article"` from `dc:type`) that match
  the `oai_dc` harvest.

So **BHL's `oai_dc` is the only thing OpenAlex sees**, and everything in this document is aimed
at it. Asking OpenAlex to support MODS would be the clean long-term route for journal, volume,
pages and author identifiers, but it would be a new feature for them.

Beyond the feed:

- **Send OpenAlex a list of BHL serials that are missing from OpenAlex** (title, ISSN-L, dates,
  publisher). In the sample, a third of the journals were missing. OpenAlex's source registry is
  maintained separately (`openalex-sources`) and can add sources on request. *Halteres* (part
  424526) is another example.
- **Ask Plazi to add the journal to its Zenodo uploads** of BHL parts: `IsPartOf` with
  `relatedIdentifierType: ISSN`. OpenAlex's DataCite container-ISSN step would then attach these
  ~68k DOIs to their journals today. This adds a journal location but does not make it primary,
  because DataCite locations without a version still score 9. See section 5 for the other problems
  these uploads cause.
- **Issue Crossref DOIs where possible.** This is the only route that works today with no change by
  OpenAlex (part 241051). It is not available for most parts, which is why the asks below matter.

### What to ask OpenAlex

- **A. Fix the ranking.** A location whose source is a journal should outrank a repository copy
  even when its version is unknown. The narrowest change would be to treat MAG locations that have a
  journal source as `publishedVersion`. They then score 4, beat BHL's 8, and still win the
  "publisher before repository" tie-break if BHL also becomes `publishedVersion`. This alone gives
  ~64.5k BHL works their journal. For these works the MAG location's landing page is usually the
  BHL part page, so readers still land on BHL.
- **B. Match repository records to journals by ISSN.** Apply the ISSN match that already exists
  for Crossref and DataCite to repository records, at least for trusted endpoints such as BHL.
  Read the ISSN from `dc:source` (and `dc:relation`), not only `dc:identifier`. Better still, keep
  BHL as a repository location and add a journal location built from BHL's metadata, as MAG did.
  Longer term, reading MODS (`<relatedItem type="host">`) would give journal, volume and pages
  without parsing free text.
- **C. Add the missing journals.** Create sources for BHL serials not yet in OpenAlex, using the
  list BHL supplies.
- **D. In the meantime, use curations.** OpenAlex's curation mechanism (`ApplyLocationCurations`)
  can add a new location to a work with a given `source_id` and `version`. OpenAlex has already
  used it on a few BHL works (`oxjob747`). A curated journal location with `publishedVersion`
  scores 4 and becomes primary with no code change. BHL could supply a mapping file (OpenAlex work
  ID, BHL part, journal source ID) if OpenAlex will accept curations in bulk.
- Also: `biblio.issue` on MAG-derived works is sometimes spurious (W114704572 has issue "6", which
  duplicates the volume).

## 4. Other feed fixes

BHL's `oai_dc` is produced by `OAIDC/Convert.cs` (`ToString()`) from an `OAIRecord` built in
`OAI2/OAIRecord.cs`. Several things BHL already has in `OAIRecord` never reach the feed.

### 4.1 Abstracts that read "Volume: 54"

**Problem.** OpenAlex takes the **first** `dc:description` as the abstract (`Repo.py`:
`dc:description[0]`). For parts, BHL's first three `dc:description` values are `Volume: NN`,
`Start Page: NN` and `End Page: NN`. Works whose abstract comes only from BHL therefore get the
abstract "Volume: 54" (e.g. [W3184516323](https://openalex.org/W3184516323), "Bethylus hyalinus:
a freak after all!").

The real abstract is loaded (`this.Abstract = segment.Summary` in `LoadSegment`) but
`Convert.ToString()` never writes it.

**Fix (BHL).**

- Emit the abstract, when there is one, as the **first** `dc:description`.
- Stop emitting the `Volume:` / `Start Page:` / `End Page:` lines as descriptions. Put that
  information in `dc:source` instead (section 3).

```xml
<dc:description>{segment.Summary, if any}</dc:description>
<dc:source>Great Basin naturalist memoirs, 6: 1-1359, ISSN 0160-239X</dc:source>
```

If a part has no abstract, emit no `dc:description` at all. No abstract is better than a wrong one.

### 4.2 Journals and volumes turned into "articles"

**Problem.** OpenAlex harvests all three of BHL's sets: `title`, `item` and `part`.

- **Serial title records** have `dc:type` values `Journal` and `text`. OpenAlex does not map
  `Journal`, but maps `text` to "article", so the record becomes an article.
- **Serial volumes (items)** have only `text`, so they also become articles.
- OpenAlex then merges them by title. For example, [W7028767614](https://openalex.org/W7028767614)
  is an "article" called *Great Basin naturalist memoirs* (1976). Its 15 locations are the title
  record and 14 scanned volumes.

These fake works add noise and can attract matches that belong to real articles.

**Fix (BHL).** For **serial** title and item records, emit `Periodical` as the only `dc:type`:

```xml
<dc:type>Periodical</dc:type>
```

- `periodical` is on OpenAlex's list of types that are never turned into works (`TYPES_TO_DELETE`
  in `repo_types.py`), so these records would be dropped.
- **Do not also emit `text`.** OpenAlex keeps the best-ranked type, and `text` ("article")
  outranks it.
- Monograph title and item records should keep `Book`. Those are real works.

**Alternative (OpenAlex).** OpenAlex can drop a set for a given endpoint
(`ENDPOINT_SETSPEC_DELETE` in `repo_filters.py`). It could drop BHL's `title` set, but that would
not remove serial items, and it would also drop monograph title records. The BHL-side fix is more
precise.

### 4.3 Language

**Problem.** BHL emits language **names** (`<dc:language>German</dc:language>`). OpenAlex maps
only ten names (English, Spanish, French, German, Chinese, Russian, Japanese, Arabic, Portuguese,
Italian), so Latin, Dutch, Swedish, Danish and others become NULL.

OpenAlex does map ISO 639 three-letter codes, which BHL already has: `Convert.ToString()` writes
`language.Name` but ignores `language.Code`.

Parts emit no `dc:language` at all: only `LoadItem` and `LoadTitle` add languages, not
`LoadSegment`, although segments have a `LanguageCode`.

**Fix (BHL).**

- Emit the ISO 639 code, e.g. `<dc:language>lat</dc:language>`.
- Add the part's language to part records.

### 4.4 Dates before 1900 are dropped

**Problem.** OpenAlex discards any repository date earlier than 1900 (`Repo.py`:
`year(d) >= 1900`). Much of BHL is older than that, so no publication date comes from BHL for
those works.

Separately, serial title records have date ranges such as `1880-1920`. OpenAlex parses only full
dates, `yyyy-MM` or `yyyy`, so they get no date either.

**Fix (OpenAlex).** Accept pre-1900 dates from BHL. The cut-off presumably guards against junk
dates in ordinary repositories, but it is wrong for a historical library. This could be an
exception for specific endpoints.

Nothing BHL can do in its feed will get around this.

### 4.5 Full text: PDFs and blocked landing pages

**Problem.** OpenAlex looks in `dc:identifier` for a URL to a PDF or to an HTML landing page. On a
landing page, it looks for a `citation_pdf_url` meta tag and for licence evidence. BHL's records
give only the landing page (`/part/NNN`), so BHL locations have `pdf_url: null`.

From a script, BHL's landing pages and PDFs return **HTTP 403** (Cloudflare), both for
`/part/248626` and `/partpdf/248626`, including with OpenAlex's harvester User-Agent. If OpenAlex's
full-text fetcher is blocked the same way, OpenAlex cannot find PDFs or licence evidence on BHL's
pages.

**Fix (BHL).**

- Add a direct PDF URL as a second `dc:identifier`. Confirm the URL pattern first; it should return
  the PDF itself, not a page.
- Add `citation_pdf_url` (and the other `citation_*` tags) to part pages if they are missing.
- Allow OpenAlex through Cloudflare:
  - the OAI-PMH harvester uses a single IP, `18.213.181.255`, with User-Agent
    `OpenAlexHarvester/1.0 (+https://help.openalex.org/how-to/repositories/; …)`;
  - full-text fetching uses other addresses, so ask OpenAlex for them (see OpenAlex's "Allowlisting
    details").
- Check the **Harvest tab** on BHL's OpenAlex source page
  ([S4306402618](https://openalex.org/sources/S4306402618)). It shows the last harvest's status and
  any errors.

### 4.6 DOIs

BHL emits DOIs as `info:doi/10.…`. OpenAlex's parser matches any `10.NNNN/…` string, so this
works. OpenAlex's documentation asks for the form `doi:10.…` and only **one** DOI per record in
`dc:identifier`.

**Fix (BHL).**

- Keep **one** DOI per part record.
- Where a part has both a publisher DOI and a BHL DOI, emit the **publisher's** DOI. OpenAlex's
  documentation says a record with only a repository-minted DOI "looks to us like a different
  paper".

### 4.7 Authors

`oai_dc` carries author names only. BHL's creator identifiers (ORCID, VIAF, Wikidata, …) are lost,
and OpenAlex can only match authors by name.

There is no good way to put identifiers in `dc:creator`. This is another reason to ask OpenAlex,
longer term, to read MODS (section 3).

### 4.8 Datestamps and re-harvesting

Covered in sections 1 and 2. In short:

- after any of these changes, bump the datestamps of every affected record, or ask OpenAlex to
  re-harvest the BHL endpoint from scratch;
- fix the datestamp problems listed in section 2 so future changes reach OpenAlex.

OpenAlex's own documentation says: "Because we ask by datestamp, a deposit whose datestamp is in
the past is not 'new' to us."

### 4.9 Questions for OpenAlex

- **Expansion corpus.** OpenAlex says repository records that created new works at the November
  2025 cutover "landed in the expansion corpus, which is excluded from API results by default".
  How many BHL works are in it, and are they visible to users? The counts in this document come
  from the default API and may be low.
- **Licence on 248626.** Confirm that OpenAlex's copy predates the licence (section 2), rather than
  being dropped downstream.
- **Allowlisting.** Which addresses does full-text fetching use?

---

## 5. Plazi's Zenodo copies of BHL parts

In 2025 Plazi uploaded BHL parts to Zenodo, each with two DataCite DOIs: a concept DOI and a
version DOI. These copies are not under BHL's control, but they now shape how BHL content appears in
OpenAlex. **71,347** works with a BHL location have a Zenodo DOI.

Example: part/385640, Andreone et al. (1993), *Skin morphology in larval, paedomorphic and
metamorphosed Alpine newts*, in *Alytes* 11: 25–35
([W2495826613](https://openalex.org/W2495826613)).

| OpenAlex shows | Why |
|---|---|
| **DOI** `10.5281/zenodo.16872895` | The Zenodo copy's DOI becomes the work's DOI, because no other DOI exists. Anyone citing or resolving the work is sent to the Zenodo copy, not to BHL or the journal. |
| **Abstract** "(Uploaded by Plazi from the Biodiversity Heritage Library) No abstract provided." | Plazi's DataCite records put this note in `descriptions` with `descriptionType: Abstract`. **77,941** works in OpenAlex have this text as their abstract. |
| **Three locations**: BHL plus both Zenodo DOIs | The concept DOI and the version DOI each become a location. |
| **Source** BHL; no volume or pages | The Zenodo records give the publisher ("Museum national d'histoire naturelle") but no journal or ISSN (`container` is empty). The work's MAG location has also gone, so no journal appears at all. |

**What Plazi could do** (BHL could pass this on):

- **Stop putting the placeholder in the abstract.** If there is no abstract, omit the description,
  or give the note `descriptionType: Other` (or `TechnicalInfo`), not `Abstract`.
- **Add the journal** as a `relatedItem` (`relationType: IsPublishedIn`, `relatedItemType:
  Journal`) with title, ISSN, volume, issue and pages, plus `IsPartOf` with the ISSN. OpenAlex's
  DataCite container-ISSN step would then attach the Zenodo copies to the journal, where the
  journal exists in OpenAlex with that ISSN.
- **Declare the original.** Add `IsIdenticalTo` (or `IsVariantFormOf`) pointing to the BHL part
  URL and, where one exists, the publisher's DOI. At present the records say only `IsDerivedFrom`
  the BHL part.
- **Correct the backlog**, not just new uploads, since DataCite metadata can be updated.

**What to ask OpenAlex:**

- **Add the Plazi placeholder to the junk-abstract list** (`CreateSuperLocations`, oxjob 682), so
  it never becomes an abstract.
- **Don't use a repository copy's DOI as the work's DOI.** Where a work's only DOIs are Zenodo
  copies of a BHL part, the DOI should be left empty, or at least not presented as the article's
  DOI. OpenAlex's own documentation says a repository-minted DOI "looks to us like a different
  paper".
- **Merge concept and version DOIs** of the same Zenodo record into one location.

This does not change the earlier findings. The ranking, ISSN-matching and missing-journal problems
in section 3 apply to these works as well.

---

## Summary

| Problem | BHL can do now | Needs OpenAlex |
|---|---|---|
| Not "published" | Add `info:eu-repo/semantics/publishedVersion` to `dc:type` | Treat BHL as `publishedVersion` by default |
| Not open access | Public Domain Mark URL; CC URL first; rights on titles | Add BHL to trusted open hosts |
| Stale records | Fix datestamps; bump them after changes | Re-harvest the BHL endpoint from scratch |
| No journal | `dc:source` with journal, volume, pages, ISSN; list of missing journals; Crossref DOIs where possible | Ranking fix; ISSN match for repository records; create missing journals; bulk curations meanwhile |
| "Volume: 54" abstracts | Real abstract first; no Volume/Page descriptions | |
| Journals as "articles" | `Periodical` as the only type for serial titles and volumes | Or drop BHL's `title` set |
| Language | ISO 639 codes; language on parts | |
| Pre-1900 dates | | Accept pre-1900 dates from BHL |
| Full text | PDF URL; `citation_pdf_url`; allow OpenAlex through Cloudflare | |
| Author IDs | | Read MODS (long term) |
| Plazi's Zenodo copies | Pass on to Plazi: no placeholder abstracts; add journal and ISSN; declare the BHL original | Treat the placeholder as junk; don't use copy DOIs as the work's DOI; merge concept and version DOIs |
