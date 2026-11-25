# Regulations — WM DORA ICT Register

What the plugin does for the rules it names, checked against the source on 2026-09-15. The plugin makes no legal assessment.

The previous edition stated a "mandatory annual submission of the register", named the templates "Regulatory Technical Standards (RTS)" and listed "B_01.01 (Suppliers), B_02.01 (Contracts), and B_03.01 (Subcontractors)". The sources below say otherwise.

## Sources

| Source | Where | Retrieved |
| --- | --- | --- |
| Regulation (EU) 2022/2554 (DORA) | EU Publications Office, CELEX 32022R2554, English XHTML (`http://publications.europa.eu/resource/celex/32022R2554`) | 2026-09-15 |
| Commission Implementing Regulation (EU) 2024/2956 (ITS on standard templates for the register of information) | EU Publications Office, CELEX 32024R2956 | 2026-09-15 |
| LEI records | GLEIF API `api.gleif.org/api/v1/lei-records/<LEI>` | 2026-09-15 |
| CSV injection | OWASP, "CSV Injection" | reference for the cell neutralisation |

## DORA

| Provision (quoted from the source) | What the plugin does |
| --- | --- |
| Art. 2(1): applies to credit institutions, payment institutions and the other entities in points (a) to (t) ("financial entities") and to (u) "ICT third-party service providers" | Nothing checks whether the operator is in scope. |
| Art. 3(22): "'critical or important function' means a function, the disruption of which would materially impair the financial performance of a financial entity, or the soundness or continuity of its services and activities, or …" | The admin form asks the entity for its decision; the REST heuristic only suggests one. |
| Art. 28(3), first subparagraph: financial entities "shall maintain and update at entity level, and at sub-consolidated and consolidated levels, a register of information in relation to all contractual arrangements on the use of ICT services provided by ICT third-party service providers" | One site, one list; no entity, sub-consolidated or consolidated level. |
| Art. 28(3): arrangements are documented "distinguishing between those that cover ICT services supporting critical or important functions and those that do not" | CIF yes/no per contract, with the reason. |
| Art. 28(3): "Financial entities shall report at least yearly to the competent authorities on the number of new arrangements on the use of ICT services, the categories of ICT third-party service providers, the type of contractual arrangements and the ICT services and functions which are being provided" and "shall make available to the competent authority, upon its request, the full register of information" | No report; the CSV export is a starting point, not a filing. |
| Art. 28(4)(a): before entering into an arrangement, financial entities shall "assess whether the contractual arrangement covers the use of ICT services supporting a critical or important function" | The assessment stays with the entity; the heuristic weights (40/30/20/15, threshold 50) are the plugin's own and have no source in the regulation. |
| Art. 28(9): the ESAs develop "draft implementing technical standards to establish the standard templates for the purposes of the register of information" | Adopted as Implementing Regulation (EU) 2024/2956 (implementing, not regulatory technical standards). |
| Art. 64: "It shall apply from 17 January 2025." | Stated in the admin glossary. |

## Implementing Regulation (EU) 2024/2956

| Template (quoted from Annex I) | In the plugin |
| --- | --- |
| B_01.01 "Entity maintaining the register of information" | Only the entity LEI, name and authority in the settings and the export manifest. Until 2026-09-15 the supplier table was labelled B_01.01. |
| B_01.02 "List of entities within the scope of consolidation", B_01.03 branches | Not present. |
| B_02.01 "Contractual arrangements – general information", B_02.02, B_02.03 | Contracts table with the plugin's own columns. |
| B_03.01 "Entities signing the contractual arrangements for receiving ICT service(s) …", B_03.02, B_03.03 | Not present. Until 2026-09-15 the subcontractor table was labelled B_03.01. |
| B_04.01 "Entities making use of the ICT services" | Not present. |
| B_05.01 "ICT third-party service providers" (Art. 5(1)(k): "the direct ICT third-party service providers and subcontractors") | Suppliers table with the plugin's own columns; subcontractors have no input path. |
| B_05.02 "ICT service supply chain" | Subcontractor table exists; no input path. |
| B_06.01 "Functions identification", B_07.01 "Assessments of the ICT services", B_99.01 definitions | Not present. |

The regulation does not name xBRL-CSV; the plugin makes no statement about the submission format and produces no xBRL-CSV.

## LEI

ISO 17442 identifiers have 20 characters; the last two are check digits (ISO 7064 MOD 97-10). The plugin checks format and check digits, not whether the LEI is registered or belongs to the named company. The example data that shipped until 2026-09-15 used `529900T8BM49AURSDO55` for "Hetzner Online GmbH"; GLEIF lists that LEI for Ubisecure Oy (Finland) and `391200XMWUW8G8MXV439` for Hetzner Online GmbH.
