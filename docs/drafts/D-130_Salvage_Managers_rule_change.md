# Draft rule change — Salvage Managers is the one CoCo TradeSphereX

**Status:** DRAFT for the project owner's approval. Once approved, this
text replaces the matching passages in `ADWITIX_Master.docx` (v2 → v3).
The code already follows it (D-130).

**Decision (project owner, 22 Oct 2026):** "There will only be one COCO
tenant and that is Salvage Managers."

---

## 1. BR-12 — add after "…this is the same designation."

> There is exactly one Salvage Managers TSX. It alone holds the CoCo plan
> and the Company Shop class, and the two always go together: the
> Custodian cannot whitelist a second CoCo or Company Shop TSX, move
> another TSX onto CoCo, or give the Company Shop class to any other TSX.
> Salvage Managers is listed first wherever TradeSphereX are listed.

## 2. Section 6.2 Product Portfolio — replace the "CoCo Starter (Free)" paragraph

> **Salvage Managers (CoCo):** The platform's own TradeSphereX — not a plan
> any other Tenant can buy. Individual and one-time Market Makers sell
> here free of any subscription: unlimited Lots, Trading Sessions, Market
> Makers and Traders; all three automated Sale Systems (Buy-Now, Express,
> Easy Auction); and Tender Auctions through CoCo Concierge (below). The
> Success Fee (6.4) applies to every completed sale — free to join, not
> free to transact.

The "CoCo Concierge" paragraph stays as written.

## 3. Section 6.5 Storage, Media & Data Retention — the first column

Rename the column "CoCo Starter" → "Salvage Managers" and replace its values:

| Feature | Was | Becomes |
|---|---|---|
| Included storage | 5 GB | 1 TB |
| Maximum file size | 25 MB | 500 MB |
| Images per Lot | 20 | Unlimited |
| Videos per Lot | 1 | Unlimited |
| Documents per Lot | 10 | Unlimited |
| Data retention | 1 Year | 10 Years |
| Draft retention | 30 Days | 1 Year |
| Deleted-data recovery | 7 Days | 180 Days |
| Archive access | — | Yes |
| Purge policy | Automatic | Custodian-defined |
| Backup frequency | Daily | Daily |
| Restore requests | Chargeable | Included |
| Audit trail retention | 90 Days | Unlimited |
| Activity history | 1 Year | Unlimited |

**Why:** Salvage Managers carries every individual seller, all Tender
Auctions, and the platform's own settlements, invoices and disputes. Its
records must outlive Indian GST record-keeping periods (about 6 years —
to be confirmed with the CA) and BR-05's hash-chained audit trail, which
assumes nothing is purged. Salvage lots sell on detailed photos and
inspection documents.

## 4. Fee Payer Election — BR-31, BR-32, BR-33, Section 6.4

Wording only: replace "a CoCo Starter TSX" with "the Salvage Managers
TSX". **No change in substance** — Salvage Managers stays Buyer-Pays only.

*Open option, not adopted:* allowing Seller-Pays on Salvage Managers so
its sellers can match 0%-buyer-premium rails. Its monthly fee invoice
would be billed to ADWITIX itself, so in practice it means waiving the
buyer premium on those sales.

## 5. BR-66 API Commercial Terms

Wording only: "CoCo Starter has no API access" → "Salvage Managers has no
API access (it is run directly through the Custodian Console)".

## 6. Glossary — "Subscription Tier"

> The package level a Tenant operates under — TSX Launch, TSX Growth or
> TSX Enterprise — which determines things like API access and whether
> Seller-Pays is available. The platform's own Salvage Managers TSX sits
> outside this ladder on the CoCo plan.

## 7. Section 6.10 Commercial Principles

"Low entry barrier through CoCo — free to join…" → "Low entry barrier
through Salvage Managers — individual sellers join free; the Success Fee
applies on every completed sale."

## 8. Section 6.3 Subscription Discount Ladder — "three paid tiers"

No change; Salvage Managers was never on the ladder.

---

## What the code does (D-130)

- **One CoCo TSX:** creating, or switching to, a second CoCo TSX is refused (409).
- **Plan and class always match:** CoCo always carries the Company Shop class. Any other plan drops it.
- **Tender access follows the plan:** Tender Auctions are allowed only for the Salvage Managers TSX.
- **Ordering:** Salvage Managers is listed first in every TradeSphereX list.
- **New default plan:** TSX Launch, at both the database and the API.
- **Existing data:** a migration moves any other CoCo TSX (e.g. "General Marketplace") to TSX Launch, but only when a TSX named "Salvage Managers" holds CoCo. The Custodian can choose a different plan afterwards.
- **Pricing pages** show the Salvage Managers column with the values above.
- **Limits not yet enforced:** none of the 6.5 limits are enforced in code for any plan.
