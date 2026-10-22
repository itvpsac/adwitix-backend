# Draft Privacy Policy wording: Custodian oversight (D-134)

**Status:** DRAFT for the project owner and the company's legal adviser.
This is not legal advice. It is wording written to match what the Platform
actually does, so that a lawyer can check it against the Digital Personal
Data Protection Act, 2023 (DPDP Act), the DPDP Rules, the Prevention of
Money-Laundering Act, 2002 (PMLA) and the IT Act before it is published.

It amends `frontend/src/pages/legal/PrivacyPolicy.jsx` (Effective Date
15-September-2026). Under BR-51(d) the change is **material**, so users
must re-consent before further use.

---

## A. New section — insert after section 4 ("Sharing")

### 5. Platform oversight by the Custodian

5.1. **Who the Custodian is.** The Custodian is the Platform's single
regulatory administrator, operated by the Data Fiduciary. The Custodian
never buys, sells, bids or acts as a TSX Master (BR-15).

5.2. **What the Custodian can see.** To keep the marketplace safe and
lawful, the Custodian keeps a single record of each user across every
TradeSphereX (BR-37). The Custodian can see:
- (a) your identity and KYC details. Your Aadhaar number stays masked; the Custodian never sees it in full;
- (b) your roles on each TradeSphereX;
- (c) your completed trading activity: bids, offers, EMD deposits, purchases, Lots, Trading Sessions and sales;
- (d) your full Star Rating history, including every change, its reason, who approved it and any appeal;
- (e) disputes, chargebacks, anti-money-laundering reviews and payout reviews;
- (f) the security and audit log of actions on your account, including IP addresses and login times.

5.3. **What the Custodian does not see.**
- (a) **During live bidding.** The Custodian cannot see who is bidding in a Trading Session that is still running. Bid amounts and timings are visible; identities are not. Identities become visible only after the session closes, as for every other permitted viewer (BR-16).
- (b) **Your browsing.** Your searches, saved searches, preferences, favourites and the Lots you look at are not shown to the Custodian.
- (c) **Your credentials.** Your mPIN, one-time passwords and authentication secrets are never shown to anyone.

5.4. **Why.** The Custodian uses this information to:
- prevent fraud, bid manipulation and shill bidding;
- resolve disputes;
- approve severe Star Rating downgrades (BR-36);
- meet anti-money-laundering and record-keeping duties (BR-54, PMLA);
- keep the immutable audit trail the Platform is required to hold (BR-05).

5.5. **Account freeze.** If the Custodian has reason to believe your
account is being misused, it may freeze the account.
- **While frozen:** you can still sign in, see your history and complete open settlements, but you cannot bid, offer, pledge a deposit, list or sell.
- **Your Lots:** a freeze does not by itself stop your live Lots.
- **Record and appeal:** every freeze and unfreeze is recorded with a reason. You may ask for the reason and request a review through the Grievance Redressal process.

5.6. **Stopping a Lot or Trading Session.** The Custodian may stop any Lot
or live Trading Session, giving a reason that is shown to the seller.
- **Deposits** held for it are released.
- **Record:** the stop is recorded in the audit trail.

5.7. **Star Ratings are not edited by hand.** Ratings change only through
the recorded rating events described in our Star Ratings policy, with the
approvals that policy requires.

## B. Change in section 4.1 (visibility table), row "Custodian"

Replace:
> Full transaction records for audit purposes, accessible only after a
> Trading Session concludes — no visibility into live bidding.

with:
> Full account, KYC (Aadhaar masked), transaction, Star Rating and audit
> records for oversight (see section 5). Bidder identities in a live Trading
> Session are visible only after it closes. Browsing activity is not shown.

## C. Change in section 7.1 (updates)

Replace:
> Material changes will be reflected in the "Effective Date" above.

with:
> Material changes will be notified to you, and you will be asked to accept
> the updated Policy before continuing to use the Platform (BR-51). Each
> acceptance is recorded with the version you accepted.

---

## Points for the legal adviser

1. **Consent wording (1.3).** The DPDP Act expects consent that is free, specific, informed and unambiguous, given against an itemised notice. Should "By registering … you consent" be replaced by the per-purpose consents the Platform already records (BR-51)?
2. **Legitimate uses (DPDP s.7).** Can fraud prevention and PMLA record-keeping rely on legitimate use rather than consent? If so, a user who withdraws consent cannot stop that processing, and the Policy should say so.
3. **Retention (5.2 of the current Policy).**
   - PMLA requires transaction records for 5 years after the business relationship ends, which matches BR-05's 5-year audit retention.
   - GST records must be kept for about 6 years.
   - The Policy should state the periods rather than "as required".
4. **Grievance Officer.** The DPDP Rules require published contact details and response timelines. The footer names the Data Fiduciary; confirm a named officer and timelines.
5. **Freeze as an adverse decision.** Should the Policy, or the Terms, promise a time limit for reviewing a freeze?
6. **Significant Data Fiduciary.** Given KYC volume, check whether the company may be notified as one, which would bring a DPO in India, audits and DPIAs.
7. **Cross-border transfer.** Hosting and backup locations should be checked against any country restrictions notified under DPDP s.16.
