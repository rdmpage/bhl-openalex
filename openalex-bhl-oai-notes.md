# OpenAlex and the BHL OAI-PMH feed: investigation notes

Notes from an investigation (October 2026) into why OpenAlex misrepresents Biodiversity Heritage
Library (BHL) content harvested from <https://www.biodiversitylibrary.org/oai>.

Sources read:

- **OpenAlex code**: [`ourresearch/openalex-walden`](https://github.com/ourresearch/openalex-walden)
  at commit `6cd36670`.
- **BHL's OAI-PMH provider**: `OAI2/` and `OAIDC/` in the bhl-us repo.
- **OpenAlex help pages**:
  [repositories](https://help.openalex.org/data/sources/repositories/) and
  [locations](https://help.openalex.org/data/locations/).
- **The live OpenAlex API**, queried on 2026-10-07.

Paths given as `Repo.py:NNN` refer to openalex-walden.

Example files in this repo:

- `248626.xml`: BHL oai_dc record for `part/248626`.
- `W114704572.json`: the corresponding OpenAlex work.
- `bhl_openalex.php`: downloader for every work with a BHL location.

## The problem

Example: [W114704572](https://openalex.org/works/W114704572), Wood (1982) *The bark and ambrosia
beetles of North and Central America (Coleoptera: Scolytidae), a taxonomic monograph*, published in
*Great Basin naturalist memoirs* 6: 1–1359
([BHL part/248626](https://www.biodiversitylibrary.org/part/248626)).

OpenAlex shows:

- **Source**: "Biodiversity Heritage Library (Smithsonian Institution)"
  ([S4306402618](https://openalex.org/sources/S4306402618), type `repository`) instead of the
  journal.
- **Not open access**: `is_oa: false`, `license: null`, although BHL gives a CC BY-NC-SA licence.
- **Not published**: `version: submittedVersion`, `is_published: false`, although BHL holds a scan
  of the printed version of record.
- **Spurious issue number**: `biblio.issue` is "6" (looks like the volume duplicated).

## Pipeline

There is no BHL-specific code in OpenAlex. BHL is handled by the generic OAI-PMH repository
pipeline.

1. **Harvester**: [`ourresearch/openalex-ingest`](https://github.com/ourresearch/openalex-ingest),
   file `repositories.py`.
   - Runs daily over all OAI-PMH endpoints, using the Sickle library.
   - Endpoints are listed in the Postgres table `oai_pmh_endpoint`, which records `pmh_url`,
     `pmh_set`, `metadata_prefix` (default `oai_dc`) and `most_recent_date_harvested`.
   - Harvests incrementally by datestamp.
   - Raw XML is written to S3 at `repositories/{endpoint_id}/YYYY/MM/DD/{hash}.xml.gz`. No parsing
     happens at this stage.
   - In the walden code, endpoints appear only as hashed `endpoint_id`s, so BHL is never named.
2. **Parsing**: `notebooks/ingest/Repo.py`, a Databricks DLT pipeline.
   - `repo_items` → `repo_parsed` reads only `oai_dc`.
   - The record key (`native_id`) is the OAI header id, e.g.
     `oai:biodiversitylibrary.org:part/12345`.
   - Older records come through a parallel backfill path (`notebooks/ingest/RepoBackfill.py`),
     which reads `openalex.repo.repo_items_backfill` and applies equivalent rules.
   - Shared helpers live in `libraries/dlt_utils/openalex/dlt/`:
     - `repo_ids.py`: identifier extraction;
     - `repo_types.py`: type ranking;
     - `repo_filters.py`: endpoint and policy filters.
3. **`repo_enriched` → `repo_works`**:
   - all repository streams are combined and filtered;
   - the newest version of each OAI id wins.
4. **Work assembly**: `notebooks/end2end/CreateWorksBase.ipynb`.
   - Locations from all providers (Crossref, MAG, repositories, …) are merged into works.
   - The locations are sorted, and `primary_location` = `locations_sorted[0]`.

## What the data shows

### BHL oai_dc record (`oai:biodiversitylibrary.org:part/248626`)

- The record contains **no journal title** and no `dc:source`. BHL's `Convert.ToString()` has "No
  mapping" for Source.
- The only upward link is `<dc:relation>`, which points to `item/33485` (the scanned volume). It
  does not point to the title or bibliography record.
- The publisher statement is "[Provo, Utah], Brigham Young University, 1976-1992".
- Volume and pages appear only as free text in `<dc:description>`: "Volume: 6", "Start Page: 1",
  "End Page: 1359".
- The record has no ISSN and no DOI.
- `<dc:type>` has the values `Article` and `text`, with no version token.
- `<dc:rights>` has two values:
  1. "In copyright. Digitized with the permission of the rights holder."
  2. `http://creativecommons.org/licenses/by-nc-sa/3.0/`
- The datestamp is **2017-11-16**.

### OpenAlex work record

- `ids` contains only `openalex` and `mag`, so this is a legacy Microsoft Academic Graph (MAG) work.
- The work has two locations:
  1. `pmh:oai:biodiversitylibrary.org:part/248626`: source is the BHL repository, `raw_type`
     `Article`, `submittedVersion`, no licence. **This location is chosen as `primary_location`.**
  2. `mag:114704572`: source is *Great Basin naturalist memoirs*
     ([S2764587021](https://openalex.org/sources/S2764587021), ISSN 0160-239X, journal), with
     `version: null`. Its landing page is also the BHL part URL.

OpenAlex already knows the right journal, but the ranking described below puts the BHL location
first.

## Analysis of each problem (confirmed against the code)

### 1. Wrong source: why BHL wins `primary_location`

`CreateWorksBase` sorts locations by a `sort_score`, then `url_sort_score`, then publisher before
repository, then provenance and native_id. The `sort_score` values are:

| sort_score | location |
|---|---|
| 0 | Crossref, has a DOI, `publishedVersion` |
| 1 | Crossref, has a DOI |
| 2 | Crossref, no DOI |
| 3 | `publishedVersion` with a PDF URL |
| 4 | `publishedVersion` |
| 5 | `acceptedVersion` with a PDF URL |
| 6 | `acceptedVersion` |
| 7 | `submittedVersion` with a PDF URL |
| 8 | `submittedVersion` |
| 9 | anything else, **including version NULL** |

The BHL location is `submittedVersion` (score **8**). The MAG journal location has no version
(score **9**). The repository location therefore wins on the first term, and the
publisher-before-repository tie-break is never reached.

This is the designed ranking, not a random bug. It does produce a bad result when the only
"publisher" evidence is a version-less MAG location. Consequences:

- **Adding `publishedVersion` to BHL's feed would *strengthen* BHL's claim to primary** (score 4).
  It fixes `is_published`, but not the source.
- The only thing that reliably outranks a BHL location is a **Crossref record** (scores 0–2). In
  practice that means a DOI in the BHL record that matches a Crossref work.
- Worth raising with OpenAlex: a MAG location with a journal source should rank above a
  repository copy, or at least not below it.

### 2. Not open access / no licence

The current code would detect this licence:

- `Repo.py:413-423` picks the first `dc:rights` value containing `creativecommons.org`. Otherwise
  it takes the first value.
- `RepoBackfill.py:485-493` does the same with a regex.
- In both paths `http://creativecommons.org/licenses/by-nc-sa/3.0/` normalises to `cc-by-nc-sa`,
  and that sets `is_oa`.

The null licence on W114704572 therefore points to one of two causes. **Not yet resolved.**

- The stored location predates these rules and was never re-parsed. The datestamp is 2017, and
  re-parsing happens only when a record is re-harvested or the backfill is re-run.
- Something downstream overrides the licence. Repository location `is_oa` =
  `is_oa_raw OR composite_is_oa` in CreateWorksBase.

Elsewhere, newer BHL parts *do* get licences. For example, W3184516323 has `cc-by-nc-sa`, and BHL
items whose `dc:rights` says "Public domain…" get `public-domain`.

### 3. Not treated as published

`detect_version_from_metadata` (`Repo.py:159-218`) runs regexes over the whole stringified oai_dc
record:

- `accepted.?version`, `accepted.?manuscript`, `peer.?reviewed` → `acceptedVersion`;
- `publishedversion`, `published.*version` → `publishedVersion`;
- otherwise **`submittedVersion` by default**.

BHL's record matches none of these, so it falls through to the default. A
`<dc:type>info:eu-repo/semantics/publishedVersion</dc:type>` would match. That `dc:type` value
maps to "article" in the type ranking, which is harmless: a specific genre such as `Chapter` still
outranks it.

### 4. Spurious issue "6"

The repository parser hard-codes `issue`, `volume`, `first_page` and `last_page` to NULL
(`Repo.py:504-507`). The "6" therefore comes from the MAG location, not from BHL.

### 5. Abstracts that read "Volume: NN"

BHL serialises a part's `Volume:` / `Start Page:` / `End Page:` lines as `dc:description`. The real
abstract (`segment.Summary` → `OAIRecord.Abstract`) is **never written to oai_dc**. OpenAlex takes
the first `dc:description` as the abstract (`Repo.py:509-511`). Confirmed live, e.g.
[W3184516323](https://openalex.org/W3184516323) and
[W7217437185](https://openalex.org/W7217437185) have the abstract "Volume: 54" / "Volume: 55".
W114704572 escapes this only because its abstract comes from another provider.

## Field-by-field: BHL oai_dc versus the OpenAlex parser

BHL side: `OAIDC/Convert.cs` (`ToString()`) and `OAI2/OAIRecord.cs` (`LoadItem`, `LoadTitle`,
`LoadSegment`).

| BHL emits | OpenAlex does | Effect |
|---|---|---|
| Part `dc:description` = `Volume:`/`Start Page:`/`End Page:`; abstract not emitted | abstract = first `dc:description` | "Volume: 54" abstracts |
| `dc:date` = `1885` | dates with year < 1900 dropped (`Repo.py:495`, `RepoBackfill.py:453`) | No date from the repository record for pre-1900 material. Pre-1900 years still appear on DOI-less BHL works, so they come from elsewhere (MAG?). |
| Title records: `dc:date` = `1880-1920` | parses only full ISO dates, `yyyy-MM` or `yyyy` | No date |
| No `dc:source`; volume and pages only in descriptions | `source_name` comes from `dc:source`; volume, issue and pages are NULL | No container or pagination data from BHL |
| `dc:type`: part = GenreName + `text`; item = `text`; title = `Journal`/`Book` + `text` | best-ranked type wins: `text`→article, `journal`→unmapped, `book`→book, `chapter`→book-chapter, `issue`→paratext | Journal title records rank as "article" (`text` beats the unmapped `Journal`). Treatments fall back to `text`. |
| `info:doi/10.…`, `urn:ISSN:…` in `dc:identifier` | DOI regex `10\.\d{4,9}/\S+` matches anywhere in the string; ISSN regex matches | DOIs and ISSNs are picked up. ISBNs are ignored. |
| `dc:identifier` URLs | a URL containing "pdf" gets `content_type: pdf` | A `partpdf` URL would register as a PDF |
| `dc:rights` = statement + licence URL | Creative Commons URL preferred, otherwise first value; "public domain" text → `public-domain` | Works for current parses |
| `dc:language` = full name ("German", "Latin") | maps only 10 language names to codes (`Repo.py:89`) | Latin, Dutch, Swedish and others → NULL |
| Creators (with ORCID etc. in BHL's data) | oai_dc names only | Author identifiers lost |
| `dc:relation` = parent item/title URL | used as a landing page only when `dc:identifier` has no URL | Not an issue |

## What the live API shows (2026-10-07)

Source S4306402618 is typed as a repository. A second source, S4306466476 "Biodiversity Heritage
Library eBooks", has 4 works.

- **Volume** (filtered on `locations.source.id:S4306402618`):
  - about 475k works carry a BHL location;
  - about 149k have BHL as their primary source;
  - about 80k have no DOI.
- **Work types:** article 300,629; book 149,909; letter 17,642; paratext 2,053; book-review 1,723.
- **Locations per record set:** `/item/` and `/bibliography/` URLs both appear as separate
  locations. That means OpenAlex harvests the item and title sets as well as parts.
- **Duplicate location:** some works carry the same `/part/` URL twice: once as the pmh location
  (with licence) and once as a version-less MAG location.

## Suggested changes to BHL's oai_dc feed

```xml
<dc:title>…</dc:title>
<dc:creator>…</dc:creator>
<dc:description>{real abstract, if any}</dc:description> <!-- first, or drop the Volume/Page lines -->
<dc:source>Great Basin naturalist memoirs, 6: 1-1359</dc:source>
<dc:type>Article</dc:type>
<dc:type>text</dc:type>
<dc:type>info:eu-repo/semantics/publishedVersion</dc:type>
<dc:identifier>https://www.biodiversitylibrary.org/part/248626</dc:identifier>
<dc:identifier>https://www.biodiversitylibrary.org/partpdf/248626</dc:identifier> <!-- URL pattern to verify -->
<dc:identifier>info:doi/10.xxxx/...</dc:identifier> <!-- only where a Crossref DOI exists -->
<dc:language>en</dc:language>
<dc:rights>http://creativecommons.org/licenses/by-nc-sa/3.0/</dc:rights> <!-- CC URL first -->
<dc:rights>In copyright. Digitized with the permission of the rights holder.</dc:rights>
```

1. **Abstract**: emit `segment.Summary` as the first `dc:description`, or stop emitting the
   Volume/Page lines in oai_dc. This is the cheapest, highest-value fix.
2. **Version**: add `publishedVersion`. It fixes `is_published`, but see §1: it also makes BHL win
   primary more firmly.
3. **Journal**: include the DOI where one exists, so the record matches the Crossref work. That is
   the only route that outranks the BHL location for primary. `dc:source` is read as
   `source_name` but does not set the source, so it is good practice but has little effect.
4. **Licence**: put the CC URL first. The current parser already prefers CC URLs, so this is
   belt-and-braces.
5. **Language**: emit ISO 639 codes. Three-letter codes are mapped; most names are not.
6. **Full text**: a direct PDF URL as a second `dc:identifier`.

### Important: datestamps

Harvesting is incremental by datestamp. Changes to the feed will **not** be seen unless the
datestamps change. BHL should either bump the datestamps or ask OpenAlex to re-harvest the
endpoint from scratch (reset `most_recent_date_harvested`).

## Reports to make

**To OpenAlex** (support@openalex.org), with W114704572 as the example:

- **Ranking**: a version-less MAG location with a journal source ranks *below* a
  `submittedVersion` repository copy (sort_score 9 vs 8 in CreateWorksBase). As a result BHL
  becomes primary on about 149k works. A journal-sourced location should beat a repository copy.
- **Version**: BHL locations are scans of published material and should be `publishedVersion`. The
  version detector defaults to `submittedVersion` when no keyword matches.
- **Licence**: the CC BY-NC-SA licence in `dc:rights` is null on this work, although the current
  parser rules would detect it. Is this a stale parse?
- **Issue**: `biblio.issue` = "6" is spurious and comes from the MAG location.

**To BHL**: apply the feed changes above (the abstract first), and bump datestamps after the
change.

## Measuring the problem

The OpenAlex API has required a free API key since February 2026. List calls cost $0.10 per 1,000,
and the free allowance is $1/day.

Quick counts (add `&api_key=KEY`):

```
https://api.openalex.org/works?filter=locations.source.id:S4306402618&per_page=1
https://api.openalex.org/works?filter=primary_location.source.id:S4306402618&per_page=1
https://api.openalex.org/works?filter=locations.source.id:S4306402618&group_by=type
https://api.openalex.org/works?filter=locations.source.id:S4306402618,has_doi:false&sample=6&seed=7
https://api.openalex.org/locations?filter=source_id:S4306402618&group_by=version
https://api.openalex.org/locations?filter=source_id:S4306402618&group_by=license
```

Full download: `bhl_openalex.php` (PHP 7, cursor paging, JSON Lines output):

```
php bhl_openalex.php YOUR_KEY > bhl_works.jsonl
```

Find works with BHL as primary despite also having a journal location:

```
jq -c 'select(.primary_location.source.id == "https://openalex.org/S4306402618"
  and any(.locations[]; .source.type == "journal"))
  | {id, title: .display_name,
     journals: [.locations[] | select(.source.type == "journal") | .source.display_name]}' bhl_works.jsonl
```

Find "Volume: NN" abstracts (need `abstract_inverted_index` in the download):

```
jq -c 'select(.abstract_inverted_index.Volume? and (.abstract_inverted_index | length) <= 2) | .id' bhl_works.jsonl
```

## Open questions / next steps

- [x] Locate the oai_dc parser and primary-location logic in `openalex-walden`
      (`Repo.py`, `RepoBackfill.py`, `CreateWorksBase.ipynb`).
- [x] Why `submittedVersion`? The regex default (§3).
- [x] Why BHL is primary? sort_score 8 vs 9 (§1).
- [ ] Why the CC licence is null on W114704572: a stale parse, or a downstream override?
- [ ] Where pre-1900 publication years on DOI-less BHL works come from, given the parser drops
      them.
- [ ] Which sets the harvester requests from BHL: the `pmh_set` value in `oai_pmh_endpoint`.
- [ ] Check whether BHL part pages include a `citation_pdf_url` meta tag.
- [ ] Confirm the BHL direct-PDF URL pattern.
- [ ] Check which BHL parts have Crossref DOIs.
- [ ] Run the counts above to size each problem before reporting.
