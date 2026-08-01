# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## 1.5.48 - 2026-08-01

### Changed / Granny UX (Bachus)

- Brief chrome: “This week: N of 5 promises” and plain cash labels (no WIP / Overdue AR jargon)
- Pipeline: lean 5-column table, Details disclosure, **Edit deal**, name-only New deal (value/SKU under More options)
- Company 360: **Call tomorrow** one-tap (no modal); next steps / High·Normal·Low instead of MAP / P1
- Chase badge: “New lead waiting too long” instead of Lead SLA
- Companion: Today **Done** one-tap; Deals inline stage chips; Settings segmented Appearance/Language

## 1.5.47 - 2026-08-01

### Fixed / Companion & UX

- Companion Settings: theme, language, and biometric unlock toggles (privacy claims now match UI)
- Push deep-links queue until navigation is ready + cold-start last-response handling
- Workspace ICS rotate announces to screen readers; Monday E2E covers per-book ICS chrome

## 1.5.46 - 2026-08-01

### Fixed / Security

- Deal `claimUnowned` CAS includes expected `updated_at` (no stamp-blind claim over concurrent field edits)
- Sequence `dismissRemainingFollowUps` uses `updateDoneCas` (no last-write-wins vs Done/Undo)

### Added

- Per-workspace ICS renewal feeds (`/feed/w/{workspaceId}/{token}/renewals.ics`) with manager rotate; Default feed stays Default-only
- Companion Today can mark activity items done (CAS via `updatedAt`)

## 1.5.45 - 2026-08-01

### Fixed / Companion

- Mobile `dealStage` forwards the JSON body as `DealService::changeStage` `$extra` so `lostReasonCode` reaches LOSS_CODE_REQUIRED (companion mark-lost was permanently broken)

## 1.5.44 - 2026-08-01

### Fixed / Security

- Company `update` / `archive` / `restore` / `gdprErase` take `FOR UPDATE` + re-read (parity with move — no stamp last-write-wins)
- Same-company FK binding for activity deal/agreement/person, deal `agreementId`, person `reportsToPersonId`, promise `personId`, and email-touch `personId` (even when addresses are supplied)
- Deal API `update` honours `If-Match-Updated-At` via `updatedAtPrecondition` (parity with Activity/Person)

## 1.5.43 - 2026-08-01

### Changed / Theme & responsive

- Design-system spacing bridges (`--space-*` → `--crm-space-*` px grid); nav widths use `--crm-nav-width` tokens
- `--crm-surface-muted` + modal/dialog text use theme-aware `--crm-text` (no muted-on-muted)
- Collapsible cards / More menus / person meta use `--crm-*` + `:focus-visible`
- Theme-matrix accent probe follows relocated `#crm-page-actions` primary CTAs

## 1.5.42 - 2026-08-01

### Changed / UX

- Company create is name-first: status, owner, language, notes, and links live under Advanced
- Company 360: one Log email under Talked; People lean to Name/Email/Actions; Account plan collapsed; Contracts in primary jump
- Agreement row actions: one primary CTA + More menu (Cancel / quote / delivery / hours)
- Brief verify: default evidence “Activity done”, primary Done, Missed/Carry/Abandon under More
- Companies list shows owner display names; filter Apply demoted to Refresh
- Owner pickers use display names instead of raw user ids

## 1.5.41 - 2026-08-01

### Fixed / Security

- Partner quota overview / `findPartnerAgreements` require a workspace (fail closed; no cross-book scan)
- `CompanyMapper::findAllActive` requires a workspace
- Money IC display scopes `invoice_ref` matches to the PC company / active book (no foreign `agreementId`)
- Person delete takes company `FOR UPDATE` + re-read (parity with update TOCTOU class)
- Hours `updateEntry` locks the row inside a TX before the 7-day edit window check
- Account plan upsert serialises on the company row and retries unique races as update

## 1.5.40 - 2026-08-01

### Fixed / Security

- Agreement field `update()` takes `FOR UPDATE` + re-read before write (no resurrect of cancelled/superseded rows vs concurrent cancel)
- Invoice-paid sync by `invoice_ref` requires a PC or CRM company pin (bare/global refs fail closed — no cross-book paid marks)
- Activity `pushToTasks` claims `todo_uri` with CAS (`claimTodoUri`) so concurrent pushes cannot double-create VTODOs
- GDPR erase clears escalation `deal_id` (parity with other PII/FK scrub paths)

## 1.5.39 - 2026-08-01

### Fixed / Security

- Promise complete/cancel require `companyId > 0` (fail closed — no optional same-WS IDOR path)
- Activity and deal field `update()` take `FOR UPDATE` row locks inside a TX (parity with Done/stage race class)

## 1.5.38 - 2026-08-01

### Fixed / Security

- Company move takes `lockRow` + re-reads optimistic stamp inside the TX (parity with merge; concurrent moves cannot both restamp)
- Brief verify refuses closed commitments and CAS-updates on `status` (no audit overwrite / last-write-wins)
- GDPR erase scrubs commitment `verify_note` + `evidence`
- Mail triage assign only matches `triage_status = open` (no silent rematch)
- Promise complete/cancel bind URL `companyId` (same-WS IDOR hardening)

## 1.5.37 - 2026-08-01

### Fixed / Security

- Company move re-stamps promise/plan/escalation/commitment `workspace_id` in the same TX (closes Today cross-book leak)
- Company merge re-homes persona ledgers onto the keeper (and stamps keeper workspace)
- Company merge re-homes shared mail matches + drafts onto the keeper (360 Communication history no longer vanishes with the archived source)
- Delivery pack / partner mail / compose template require `write` (viewers can no longer insert email touches)
- Agreements list row actions + edit links gated; emails-section Log email gated
- Promise `dueSoon` joins company and skips archived rows

## 1.5.36 - 2026-08-01

### Fixed / Security

- Pipeline stage selects, Edit chase, and kanban drag gated for viewers; money Payment reminder and activities Done/Undo likewise
- Company 360: buying-role select + Contacts unlink write-only; account plan and promises render read-only via shared `shell-can.js`
- Activity Done/Undo uses `updated_at` CAS (`updateDoneCas`) — same race class as deal stage
- GDPR export includes weekly `commitments` (titles erase already scrubbed)
- `findOpenByTitle` requires workspace (fail closed); removed dead unscoped `sumOpenValueByStage`

## 1.5.35 - 2026-08-01

### Fixed / Security

- GDPR erase deletes `crm_plan_ms` milestones before plans; agreement anonymise blanks free-text `label`
- Company detail / pipeline hide write·delete·move CTAs for viewers (Edit, Merge, Erase, Log email, Done, New deal, …)
- Website-order title idempotency scoped to the default intake workspace (no cross-book reclaim)
- Deal `changeStage` uses `updated_at` CAS so concurrent stage moves cannot both win
- Pipeline create-deal disables submit against double-post; Brief/Team/Strategy honour `data-crm-can-*` shell flags
- Chase and Brief renewal money filter at the SQL layer by workspace; shell exposes brief/coach/bulk capabilities

## 1.5.34 - 2026-08-01

### Fixed / Security

- Agreement New/Edit SSR lists only the active workspace’s companies; Edit requires company-workspace write (no foreign-book picker leak)
- Company New and Agreement forms require write capability (viewers cannot open mutation pages by URL)
- Brief confirm and Escalation create validate company/deal belong to the workspace; WIP and open-cap use exclusive locks
- Persona company writes use `assertUserCanAccessCompany` (null workspace_id → 404, not last-used privilege)
- GDPR erase anonymises name/slug, clears PC/TC links, scrubs commitment titles and escalation reasons
- Viewer mutation CTAs (Add company / New agreement / Add person / Import empty-states) hidden without write
- Brief UI says “Add a commitment” (CEO commitments ≠ customer promises)

## 1.5.33 - 2026-08-01

### Fixed / Security

- Classic CRM mutations (people / deals / agreements / activities / hours) assert **write on the company’s workspace**, not last-used — closes multi-book privilege escalation (viewer in A + manager in B)
- Deal reassign/`bulk` capability uses the company-book role
- GDPR erase clears Files folder id/path pointers (nodes stay in Files SoR)
- `PersonMapper::findByEmail` and ImportMatcher require a workspace (fail closed)
- `CustomerCheckApi` pins `workspaceId` from the page URL on every request

## 1.5.32 - 2026-08-01

### Fixed / Security

- Import API requires workspace `write` and scopes match/create to the active book (no Default-WS smuggling)
- Unified Search (companies / people / deals) limited to workspaces the user can access
- Lead and website-order intake match emails only inside the default intake workspace
- Renewal / quota / activity-due / mailbox / invoice-paid / license-key / lead notifications fan out to workspace members only (fail closed when workspace is missing — never instance-wide company PII)
- ICS renewals feed scoped to the default organisation workspace
- GDPR erase clears `accountOwnerUserId`; Import nav hidden for viewers
- Person create asserts economic-buyer uniqueness under the company `FOR UPDATE` lock
- Search provider DI arity fixed for `WorkspaceAccessService`

## 1.5.31 - 2026-08-01

### Fixed / Security

- Money SSR scopes overview to the active workspace (no cross-book € blend); MoneyService fails closed when workspaceId is missing
- GDPR erase scrubs shared-mail `from` / `to` / subject / snippet / preview (not only detach company_id)
- GDPR export includes shared mail, failed drafts, escalations, and KAM/account-owner company fields
- Shared SMTP send requires a valid Idempotency-Key (no unlocked empty-key double-send path)
- Company 360 communication feed uses plain-language kind labels (Shared mail / Logged email / …)
- A11y smoke covers My Accounts, mail triage, and Settings

## 1.5.30 - 2026-08-01

### Added / Security

- Shared-mailbox **Failed sends** inbox (§14.4): `mail.read` lists/opens failed drafts; `mail.send` resend/discard
- Resend CAS-reuses the same draft row (no duplicate recovery drafts on SMTP fail)
- Claim/idempotency rows stay out of the inbox (`idempotency_key IS NULL` only)
- GDPR erase scrubs draft `to` / subject / body and clears `company_id`
- `addMember` updates existing membership even when the workspace is at member cap (fixes flaky MobileGate when default WS is full)

## 1.5.29 - 2026-08-01

### Fixed / Security

- Actor-scoped `FilesAccessService` for quote PDF and mail attachments (no raw filecache trust)
- Company generic PUT can no longer set `filesFolderId` / `filesFolderPath` (Documents service only)
- Deal `quoteFileId` requires readable PDF via Files ACL; Pipeline Edit chase has PDF picker
- Shared mailbox send accepts `attachmentFileIds` (PDF/PNG/JPEG/TXT) with size limits; oversize/denied → HTTP 422
- SMTP multipart/mixed + IMailer `createAttachment`; prepared-mail UI can Add file

## 1.5.28 - 2026-08-01

### Fixed / Security

- Shared SMTP fail-closed when host is set but password decrypt/missing (no silent IMailer From-spoof)
- Idempotency key claimed before SMTP; failed sends drop the claim so retries stay safe
- Multi-mailbox workspaces prompt which shared From to use (remembers last pick)
- Mail triage assigns via company name search — no typed company IDs
- Mail retention purge uses fair round-robin across workspaces + dedicated 5-minute TimedJob

## 1.5.27 - 2026-08-01

### Fixed / Security

- Shared SMTP rejects `companyId` outside the send workspace before rate-limit / SMTP (cross-book audit attach closed)
- Company workspace move uses optimistic lock + slug confirm, rematches shared mail, detaches old-book drafts; generic PUT cannot change `workspaceId`
- Manager UI: Move to workspace on company 360 (Files ACL warning)
- Directory user-pickers support Arrow/Home/End/Enter keyboard navigation (`aria-activedescendant`)
- Pipeline shows owner display names (not raw UIDs); Edit chase seeds the same label
- Prepared-mail dialog shows unavoidable shared-mailbox disclosure before Send
- A11y smoke covers Pulse, Team, and Brief

## 1.5.26 - 2026-08-01

### Fixed / Security

- Shared SMTP: idempotency replay runs before rate-limit burn; client reuses one key per send attempt
- OOO cover insert serializes under advisory lock (TOCTOU closed); ended covers no longer block reschedule
- Bulk reassign reports CAS-affected rows only — concurrent ownership change rolls back with CONFLICT_STALE
- Mail retention purge drains under a wall-clock budget and reports remaining backlog
- Pipeline kanban stage control is visible, ≥44px, and keyboard-movable (ArrowLeft/Right)

## 1.5.25 - 2026-08-01

### Fixed / Security

- Managers/app-admins can reassign owned deals again (1.5.24 steal guard had blocked them)
- Mail triage rejects companies outside the message workspace (cross-book attach closed)
- Account owner changes use the same owner/manager reassign policy as deals
- Shared-mailbox SMTP send shared across company 360, agreements, and money compose surfaces

## 1.5.24 - 2026-08-01

### Fixed / Security

- Deal claim is CAS (`POST /deals/{id}/claim`) — no steal of owned deals; race-safe unowned claim
- Generic deal owner update rejects non-owner reassignment (managers/owners only via owned path)
- Workspace `stuckChaseDays` drives quote follow-up chase threshold + settings UI
- Mail retention purge serializes under advisory lock
- Delivery pack can send via shared mailbox SMTP (disclosure + idempotency), not mailto-only

## 1.5.23 - 2026-08-01

### Fixed / Security

- Shared-mailbox CRM index respects `mail_retention_days` (purge after sync; floor 30 days)
- Workspace settings expose lead SLA hours + mail retention
- Website/webhook leads set `leadSlaDueAt` from workspace SLA and stay unowned until Claim
- Deal create uses workspace lead SLA when hours are not passed explicitly
- Today My Chase shows Claim CTA for unowned chase deals (optimistic lock)

## 1.5.22 - 2026-08-01

### Fixed / Security

- Command Board waiting-tickets resolve TicketCheck `customerId` → CRM company (facade always sends `companyId: null`)
- Pulse waiting-ticket metric filters to this workspace’s linked TC customers (no cross-book heat)
- Pulse renewals/pipeline queries are workspace-scoped at the mapper
- My Accounts coverage is one-pass (no N× `forCompany`)
- GDPR erase clears company links on weekly commitments and escalations

## 1.5.21 - 2026-07-31

### Fixed / Security

- Today API, Summary API, and Dashboard widget resolve the active workspace — never blend books
- Command Board legacy sections (renewals/activities/stale/invoices/tickets) are workspace-scoped
- Account plan health uses real overdue callbacks + renewals≤7d (not hardcoded off)
- Bulk reassign ACL-filters companies/promises/commitments before counting
- Today badges use human kind labels; plan health and Team stuck use display names
- My Accounts shows coverage risk flag labels

## 1.5.20 - 2026-07-31

### Fixed / Security

- Team **Coverage gaps** column uses real buying-role CoverageLogic (one-pass), not overdue-touch proxy
- Coverage risk flags wired: promise overdue, partner hours hot, renewal hot (§12.7)
- Customer promises: required due date + evidence; cancel path; human status labels
- Sequence dismiss closes all `sequence_*` follow-ups (contacted/negotiation included)
- Shared-mail disclosure uses real workspace name; send Idempotency-Key locked before claim
- Company archive/restore API passes optimistic-lock precondition (PHP 8 arity)
- GDPR export includes buying roles, person status, deal owner/forecast/loss codes
- Team lead copy is text-first (On track / Needs help), not colour-only

## 1.5.19 - 2026-07-31

### Changed / UX

- Nav clarity (Do now / Customers / Deals & money / Tools), Job: page leads, account owner on companies, pipeline column sums

### Security / Mobile

- Companion workspace ACL path-scoped APIs + switcher; fail-closed foreign book → 404
- Companion race hardening: generation-token loads clear rows on workspace switch; mid-session revoke re-lists membership on 404
- Viewer role is fail-closed in the companion UI (no log-activity / stage affordances); server still enforces `WS_ROLE_DENIED`
- Deal stages aligned with server (`contacted`, not invent-your-own `qualified`)

### Docs

- Website/store honesty: shared mailbox vs personal crawl; CHANGELOG added
- Play release notes / listings no longer claim ProjectCheck clock-in

## 1.5.18 - 2026-07-30

### Notes

- Product bible capabilities marked complete for the native command-center spine (workspaces, Pulse/Brief/Chase/Coverage/Team, shared mail sync+send, Company 360, renewal engine, Check sibling joins). See README product-bible status and `CUSTOMERCHECK-PRODUCT.md`.
