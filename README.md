# bhl-openalex
Exploring the relationship between BHL and OpenAlex


## BHL OAI

To get a record for part/248626: https://www.biodiversitylibrary.org/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:biodiversitylibrary.org:part/248626

## OpenAlex

Same record in OpenAlex https://openalex.org/W114704572 (or https://api.openalex.org/w114704572).


## Pairs to look at

|OpenAlex | BHL | Title | Notes | OpenAlex primary source |
|--|--|--|--|--|
| W2081693829.json | 110457.xml | The role of macrophytes in habitat… | BHL has no DOI, part is external (and has DOI) | Journal (Crossref DOI) |
| W2257702110.json | 76326.xml | Descriptive catalogue of South African Decapod Crustacea | No DOI (part from BioStor) | BHL (journal only on MAG location) |
| W114704572.json | 248626.xml | The Bark and Ambrosia Beetles |  no DOI | BHL (journal only on MAG location) |
| W2140457195.json | 175696.xml | Biosystematics of Australian mygalomorph spiders | CrossRef DOI (but not BHL’s) | Journal (Crossref DOI) |
| W2480131134.json | 424526.xml | Thrips (Insecta: Thysanoptera) Of India… | Zenodo DOI (in BioStor but not BHL) | BHL (DataCite DOI has ISSN 2348-7372, but OpenAlex has no source for *Halteres*) |
| W2910742455.json | 241051.xml | A walrus and a sea lion…| BHL issued DOI | Journal (BHL's Crossref DOI 10.5962/p.241051) |
| W2495826613.json | 385640.xml | Skin morphology in larval, paedomorphic and metamorphosed Alpine newts | Copied by Plazi into Zenodo, has Zenodo DOI | BHL (Zenodo DOIs are the work's DOI; abstract is Plazi's "No abstract provided" note; *Alytes* is in OpenAlex but without an ISSN) |
