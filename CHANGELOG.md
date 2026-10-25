# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.6.191 - 2026-10-25

### Fixed

- settings area-switch chrome + token action SR context
- collapse doubled tokens, strip zero-width spaces in catalogs
- country-picker clear button no longer orphans to its own row
- post-seal class-gate repairs — deep links, l10n lexicon, dead CSS
- deep links target mail.page.index, not dead mail.home.index
- media-boundary migration + sprintfT fills + lane batch
- migrate media boundaries to 769px/768.98px family convention
- drop Constants refs from BootstrapDefaultWorkspace
- restore mobile-side 768px coverage across remaining sheets
- restore mobile-side media boundary coverage at 768/640 integers
- sweep label-in-name consistency across all catalogs (WCAG 2.5.3)
- label-in-name consistency for renamed checklist label (WCAG 2.5.3)
- Fix stale Nextcloud version range in store descriptions (32–34 → 32–35)
- Fix nav focus-ring strength and duplicate report links on app-owned toasts
- Fix App Store screenshot URLs: point at main branch
- Fix PL/DA l10n plural placeholders that broke localized CustomerCheck pages.

### Added

- country picker — ISO 3166-1 (+XK) directory SSOT, web+companion API, combobox UI
- complete EU country set + workspace-scoped link facade + a11y/theme hardening
- CrmCompanyLinkReadFacade for carecheck CRM link

### Changed

- translation sync — regenerated catalogs (de/fr/sv/nb/nl/pl/pt/pt_BR/it/es/da/en)
- baseline-green guard — codify mutation_runner_baseline_red_false_kill
- l10n(en_GB): proper British English forms (licence, recognise, authorised…)
- l10n: catalog corrections + format normalization
- l10n(pt): translate Checklist notification strings (Lista de verificação)
- normalize tarball modes + host-side signature audit (GH #47)
- add ATLAS screenshot-matrix manifest + capture spec
- nl locale sync + shared e2e instance-lock harness
- drop consumed one-off l10n write script (repo hygiene)
- Atlas v3.5.15 fixer delta: import post-commit counters, uniform-404/500 middleware, CAS double-rollback fix, dialog/filter e2e coverage
- add UpgradeBackupStateGuard test support; sync contract tests + l10n catalogs
- refresh l10n + comment cleanup for 1.6.190 store release
- reposition as CRM in store copy + fleet sync
- l10n: escape literal % as %% in .json values (PHP vsprintf safety); fix untranslated/case-only values
- Prepare repository for App Store release
- Atlas farm v3.5.15: DS contrast suite + cascade-cleanup harness + spec hygiene
- Atlas v3.5.14 verification delta: API hardening (form-false bool coercion), sweep fixes (touch floors, overflow), l10n, contract coverage
- Post-Atlas deliverables: refreshed store screenshots and listings, l10n sync, and pipeline fixes.
- Extract focus preservation and field errors to shared modules; API contract tests.
- sync workspace updates
- Ship l10n, mobile-nav, and Atlas contract updates.
- Refresh l10n catalogs after the multi-locale Play/store pass.
- Seal Atlas 3.5.11 product fixes for CustomerCheck (sticky-footer sysnav and chrome).
- Seal Atlas 3.5.9 farm UX, offline, and dialog-proof hardening.
- Close Atlas residual should_fix with durable AVD proofs and related hardening.

## 1.6.190 - 2026-08-25

### Fixed (Dashboard — Today desklet theming)
- Register `desklet-nextcloud.css` on widget `load()` so list-row icons invert under Nextcloud dark themes (Vue SVG load never flipped → invisible file-icons)
- Theme-safe `::before` app mark, 44px touch targets, focus rings, readable multiline subtitles — WCAG 2.1 AA

## 1.6.189 - 2026-08-24

### Added (Sales Inbox — inline company create)
- **Unassigned** tab: create a new company and assign the current message without leaving the inbox
- Collapsible “Company not listed? Create one here” block with name field + **Create and assign**
- Empty company search pre-fills the create name and opens the block
- `inbox-create-company-core` helpers; company picker `onEmptyResults` hook; contract + mutation tests

### Improved (Team — Manager tools UX)
- Three manager jobs are **accordion panels** — only step 1 open by default (no more wall of forms)
- Each step is a clear card with step badge, title, one-line hint, and chevron
- Removed repeated “search by name / never type ID” clutter; bulk **What to move** tucked in collapsed options
- Confirm checkbox in a highlighted box; large touch-friendly Save buttons

## 1.6.188 - 2026-08-24

### Cleaned (Settings — aggressive pre-production hygiene)
- Removed legacy mega-page `#anchor` redirect scripts (not in production; chip nav is canonical)
- Renamed settings section **`circles` → `promises`** (`/settings/promises`, `PromisesRollCallService`, `/api/v1/promises-roll-call`)
- Jobs form now exposes **identity rename push** + **default VTODO calendar URI** (were API-only headless keys)
- Integrations matrix: boolean probes only, human labels, MaintenanceCheck included

### Added (Team coaching — bulk reassign open to-dos) — 1.6.187
- Managers can bulk-move **open to-dos** (activity assignees) and deal checklist items with deals/accounts/promises
- Activities have no `workspace_id` — scoped via **company join** (same ACL pattern as deals); done items stay put
- CAS on assignee + open status; conflict if ownership changes mid-batch; new assignee gets `activity_assigned` (not the actor)
- Team capacity form: checkboxes for Deal checklist + Open to-dos; clearer lead copy

### Cleaned (Settings hygiene)
- Removed unreachable non-admin branches on Access / Personal email OAuth (app-settings already gates admin)
- Removed leftover create-workspace DOM scrub from `settings.js` (single-org; templates already gone)
- Integrations: only boolean capability probes, human labels, MaintenanceCheck + write facades; fixed misleading “read-only” copy
- Settings page help / quick-start match the chip nav (no mega-page leftovers)

### Harden / Gauntlet
- Unit + contract tests for company-join ACL, from≠to, membership, CAS rollback, notify skip-self
- Mutation suite `test:mutation:coaching-activity-bulk`
- `SettingsHygieneContractTest` locks the dead-code removals

## 1.6.184 - 2026-08-19

### Theme, layout, and WCAG 2.1 AA
- Map `--crm-radius-lg` / `--crm-radius-pill` and `--crm-scrim` to Nextcloud theme variables (Light, Dark, High Contrast, custom accent)
- High-contrast borders and focus rings cover native dialogs and personal-settings panels
- OAuth companion “sign-in complete” page follows `color-scheme` / Canvas colors and the user’s `lang`
- Stacked tables no longer force a 600px min-width; keyboard focus on inputs is not stripped
- Gauntlet: theme/a11y contracts, CSS token vitest, mutations, axe + theme-matrix + responsive-matrix

### Harden
- List searches fail closed without a workspace (`ListWorkspaceGuard`); unscoped mapper `search()` is `1=0`
- Company name lookup requires `workspaceId`; pipeline table/board switches in place (no full reload)

### UX
- Shared new-deal form, list toolbars, and native date picker on filter/create flows

### Hygiene
- Remove internal audit/runbook files from the public app tree (canonical copies live under `documentation/`)

## 1.6.148 - 2026-08-16

### Harden (Sales Inbox pagination — Aristoteles gauntlet)
- Unknown `tab` falls closed to Personal (never unscoped workspace dump)
- Multi-pass fetch when ACL skips thin a page — no silent early end of “Load older”
- Stable `sentAt` cursor normalization (DateTime / ISO); reject malformed cursors
- Client: `inbox-page-core` merge/meta helpers; clear stale list on load error; focus + live region on Load older
- UX: divider + hint under **Load older messages**; search-empty copy when more pages exist
- A11y: listbox `option` rows no longer set invalid `aria-expanded` (state in accessible name)
- Gauntlet: unit + integration + vitest + mutation (`run-inbox-paging-mutations.php`) + E2E route mock

## 1.6.147 - 2026-08-16

### Added (Sales Inbox pagination)
- Personal and Team tabs load 50 messages per page with cursor-based **Load older messages**
- API: `GET …/mail/inbox` accepts `cursor` and returns `nextCursor` / `hasMore`
- Status line notes when more mail is available; screen reader announces each page loaded

## 1.6.146 - 2026-08-15

### Harden (Signature logo — Aristoteles gauntlet)
- Single-flight Logo insert (busy gate + `aria-busy` + live “Adding logo…/Logo added”) — no double-share race
- Reuse only passwordless, downloadable public shares from OCS list payloads
- Share-token path allows `_`/`-`; OCS parse helpers unit-tested in `signature-logo-core`
- Integration: container `HtmlSanitizeService` same-origin http share allow / evil host block
- E2E: Team + personal signature Logo toolbar touch target + axe on Team signature wrap
- Mutation MSI extended (busy gate, reusable-share helper)

## 1.6.145 - 2026-08-15

### Fix (Signature logo on HTTP Nextcloud)
- Root cause: local Docker is `http://localhost:8081`, but logos required `https://` — Files pick created a public link that the editor then rejected
- Allow **same-origin** Nextcloud public-share download URLs over http (this instance only); still block third-party http / data / javascript
- Shared policy: `SignatureLogoUrlPolicy` (PHP) ↔ `signature-logo-core.js`; sanitize + editor stay in lockstep
- Vitest + PHPUnit + mutation gauntlet (`run-signature-logo-mutations.php`)

## 1.6.144 - 2026-08-15

### Fix (Team signature logo)
- Team signature WYSIWYG mounts on page load and after Reset (not only after Edit)
- Same Logo → Files picker as personal signature (1.6.143 share-API fix)

## 1.6.143 - 2026-08-15

### Fix (Signature logo — real Files pick)
- **Logo** opens the Nextcloud Files picker immediately (no URL paste, no extra ack dialog)
- Public share create uses form-urlencoded OCS (JSON body never reached the Share API — Files insert was broken)
- Reuses an existing public link when present; restores caret after the picker so the image lands in the signature
- Help copy: pick from Files; recipients need a public https link under the hood

## 1.6.142 - 2026-08-15

### Inbox (Update mail — granny UX)
- **Why it vanished:** `Update mail` was gated to Personal only (`activeTab === 'mine'`), so Team/Unassigned hid it
- Show on **Personal and Team** when that tab has syncable mailboxes; still hidden on **Unassigned** (decide queue, not a folder)
- Team sync uses `/mailboxes/{id}/sync`; Personal keeps `/personal-mailboxes/{id}/sync`; filter scopes sync; `syncInFlight` + captured tab prevent races
- Account filter options/aria use PHP `data-crm-*` labels so first paint stays native (no English leftovers from JS `t()` lag)
- Contracts + personal-mail mutation needle; e2e asserts Unassigned keeps sync hidden

## 1.6.141 - 2026-08-15


### l10n (native mail / CRM — Aristoteles)
- Synced live `$l->t` / `t()` literals into catalogs (2858 keys; parity across en/de/da/es/fr/it/nb/nl/pl/sv/pt_BR)
- Hand-translated mail-domain and CRM identity gaps (informal du/tu/je per `NATIVE-STYLE.md`) — no MT APIs
- Fixed DE calques/grammar (`Churned`→Abgewandert, broken `Filtern du` / `lesen können`, Workspace/opt-in/Shared-Mailbox jargon)
- Restored informal FR (tu) and NL (je) after vous/u MT leftovers; ES usted→tú on owner/plan copy
- Added `l10n/GLOSSARY.md`; wired `check-l10n-native-quality.php` into `npm run check:l10n`
- Contracts: `L10nNativeQualityContractTest` + hardened `L10nGermanCopyQualityTest`; mutation MSI **100%** (8/8) on `run-l10n-de-quality-mutations.php`
- Regenerated `.js` catalogs and regional variants; parity + placeholder + DE + native + variant `--check` green

### Test / contract hardenings (Assign rename drift)
- Aligned Zeus/MailForward/GDPR/Timeline/health/roster contracts with Assign APIs (`selectedAssignIds`, `customerEmailForAssignSuggest`, `nextAssignOn`, …)
- Full PHPUnit **unit** suite green: **1838** tests / **10440** assertions
- `appinfo/version` kept in lockstep with `info.xml` (asset cache-buster)

## 1.6.140 - 2026-08-15

### Theme (Aristoteles — zero hex in feature CSS)
- Sweep `app.css` + common stylesheets: replace `var(--color-*, #hex)` with `--crm-*` / NC vars (no feature-CSS hex fallbacks)
- Support-us brand plate/ink/muted map to theme-adaptive `--crm-support-brand-*` on `body` (fixed white/#29235c broken dark/HC)
- Token compliance: feature CSS must contain zero hex; only `tokens.css` may keep NC notif fallbacks
- Mutation needle kills hardcoding muted `#666` into Sales Inbox/My email CSS

## 1.6.139 - 2026-08-15

### Theme / Responsive (Aristoteles — design-system × Nextcloud tokens)
- Sales Inbox + My email CSS: map surfaces/ink/borders/radii/touch to `--crm-*` tokens (backed by NC `--color-*`); zero hex in that Bachus block
- Touch targets use `--crm-touch` / `--crm-touch-lg` (44 / 48); type/spacing use `--crm-fs-*` / `--crm-space-*`
- Theme-matrix + responsive-matrix include Sales Inbox and My email; personal-mail e2e axe under light/dark/high-contrast themes

## 1.6.138 - 2026-08-15

### Security (Aristoteles)
- Restore `reuseDraftId` ACL: `claimFailedDraft` again calls `MailAclLogic::actorMayAccessFailedDraft` (was `|| false` — peer personal draft hijack)
- Encrypt OAuth Google/Microsoft client secrets at rest via `ICrypto` in `storeClientSecret` (was plaintext appconfig)

### Responsive (Bachus / Aristoteles — My email / Sales Inbox)
- Mobile ≤767px: stack My email and Sales Inbox action rows; full-width ≥44px touch targets; OAuth one-per-row; DSGVO wraps; compose Cc ≥44×44
- Nested folder indent reduced on phones; folder picker scrolls horizontally on tablet/phone (≤1023px)
- Desktop ≥1024px base layout unchanged (additive max-width media only; NC nav toggle unchanged)
- Contracts + Playwright overflow/toolbar-direction checks at 320–1280; personal-mail mutation needles for responsive CSS

## 1.6.137 - 2026-08-15

### UX (Bachus — personal mail / Sales Inbox simplify)
- Signature editors use a lean toolbar (logo kept; no reply chrome)
- My email: DSGVO ack sits under OAuth; IMAP-only opens mail servers; Disconnect under Remove inbox
- Folder Sync/Share auto-saves (Save folders hidden); shorter folder help
- Sales Inbox busy mailbox: warning + Try again (not a hard danger failure)
- Touch targets for rich-mail tools and folder toggles use 44px (not rem)

### Fix (Aristoteles — race hardenings)
- Busy delete/spam retry: pin mail id; do not unlock `assignBusy` while handing off the retry
- Folder auto-save: dirty coalesce during SHARE dialogs; mark `data-was-shared` after ack so coalesce does not re-prompt
- `isNativeOAuthState`: return true when OAuth state `client=native` (was always false — companion AuthSession footgun)

## 1.6.133 - 2026-08-15

### Fix (Sales Inbox / Assign delete & spam)
- CRM-sent copies (`outbound:…` UIDs) delete or mark-as-spam as CRM-only — no more “missing folder or id”
- Live IMAP UIDs still dial the mail host; personal mail stays owner-gated
- Clearer Inbox error titles (Could not delete / Could not mark as spam)

### Added (Signature logo)
- Personal and Team signature editors: add logo via public https URL or Files (public link)
- Signature HTML allows http(s) `<img>` only (AS-18); compose body still has no image insert

### Added (My email — update connection)
- Connected inboxes: **Update password or servers** without Disconnect wipe
- Prefill hosts; lock email; blank password keeps the current secret; Cancel closes the form

## 1.6.132 - 2026-08-15

### Fix (My email folders)
- Subfolders: stronger indent, primary tree mark + tinted rail; name and Sync/Share on one row
- Drop redundant full-path text beside the leaf name (full path stays on hover / screen reader)

## 1.6.131 - 2026-08-15

### Fix (My email connect)
- DSGVO acknowledgement sits directly above Connect (same pattern as Team mailbox save)

## 1.6.130 - 2026-08-15

### Fix (My email connect form)
- Flatten IMAP connect: one panel, no nested fieldset boxes; mail servers as a simple divider disclosure
- Tighter, even spacing between email / username / password and server fields

## 1.6.129 - 2026-08-15

### Fix (Team & mailbox settings scroll)
- Sticky jump links: Shared mailbox, Members, Personal email, Weekly limits
- Add-member form and mailbox connection form collapse by default; open on Edit / when empty
- Team signature tucked under a details panel; member list scrolls in place

## 1.6.128 - 2026-08-15

### Fix (Sales Inbox rows)
- Full-width row: subject/from use the line; date sits on the right; no duplicate snippet
- Click the open row again to collapse the preview (same as company Talk)

## 1.6.127 - 2026-08-15

### Security / UX (Talk & Review Answer)
- In-app Answer: kind-aware send disclosure (personal vs team), companyId/mailbox/privacy on reply pack, close dialog after send
- Private personal → team send warns before sharing the quote; scoped dialog controls; clearer To/Subject layout (WCAG touch targets)

## 1.6.126 - 2026-08-15

### Added (Company Talk / Review Answer)
- **Answer** on company Talk and Review opens the in-app composer: send from connected personal or team mail when you have rights, with Open mail as fallback

## 1.6.125 - 2026-08-15

### Fix (Sales Inbox layout)
- Full-width message list; clicking a row opens the preview directly underneath (company Talk pattern)

## 1.6.124 - 2026-08-15

### Fix (Tools nav)
- **My email** sits under Tools next to Sales Inbox (not a ghost link on the inbox toolbar)
- **Team & mailbox** in Tools is managers-only

## 1.6.123 - 2026-08-15

### Added (My email settings page)
- Dedicated **/settings/my-email** for every member to connect their personal inbox, folders, and signature
- **/settings/mailbox** (Team mailbox) is managers-only again: members, personal-sync policy, shared team inbox, limits

## 1.6.122 - 2026-08-15

### Fix (Sales Inbox labels)
- Tab **Personal** (not Mine) vs action **Email settings** — no more Meine / Meine E-Mail clash

## 1.6.121 - 2026-08-15

### Fix (Sales Inbox compose spacing)
- Recipients band: Send to + Add Cc on one row; tighter field rhythm; no stacked form-group margins
- Attachments header inline with Add file; Cc panel opens as a compact inset

## 1.6.120 - 2026-08-15

### Fix (rich mail width)
- Compose / signature editors and quote panel use full available width (no shrink-wrapped columns)

## 1.6.119 - 2026-08-15

### Fix (Sales Inbox / signature composer UX)
- Compact rich-mail toolbar (accessible short labels); signature editors use a lean toolbar (B/I/U/list/link)
- Reply quote visible + editable; strip MIME debris from previews; “Send to” instead of ambiguous “To”/„Bis“
- Cache-bust for rich-mail-composer.js (version bump)

## 1.6.118 - 2026-08-15

### Share-cancel keeps sync folder (Aristoteles)

- **P1:** Cancelling the share disclosure no longer omits the folder from the folders PUT — payload keeps `shareWithWorkspace: false` so `replacePersonalFolders` cannot delete the sync row
- Companion: drop unused `shareConfirm` opt (payload builder already stamps SHARE on shared Inbox/Sent)
- Contracts + mutation for share-cancel keep

## 1.6.117 - 2026-08-15

### Disconnect + companion folder ack (Aristoteles)

- **P1:** Web quarantine reconnect keeps **Disconnect** visible (hide Sync/folders/signature only) so erase / email-switch works under `PERSONAL_MAIL_MAX_PER_USER=1` + email-rebind guard
- **P1:** Companion folder PUT stamps `shareAck` / `SHARE` on **every** shared row (sibling under-ack no longer fail-closes Sync toggles after Inbox share)
- Contracts + Jest for folder payload builder

### Localization (Hermes — personal mail / Sales Inbox)

- Server: SHARE confirm fallback + folder-row `Sync` native in all secondary locales; DE SHARE uses “Team” not “Workspace”
- Mobile: `myEmail.emptyBody` + `salesInbox.reconnectBody` OAuth-first plain copy (da/de/es/fr/it/nb/nl/pl/pt/sv)
- Report: `planning/app-ideas/customercheck/HERMES-PERSONAL-MAIL-LOCALIZATION-1.6.117.md`

## 1.6.116 - 2026-08-15

### Bachus P1 closeout (Aristoteles)

- **P1:** Auto-open IMAP/app-password disclosure when no OAuth providers are configured (Connect CTA no longer buried)
- **P1:** Typed confirm in `confirmDialog` is case-insensitive (`share` ≡ `SHARE`) — matches server/mobile
- **P1:** OAuth callback `?personalMailError=` / `?personalMail=connected` surfaced in My email (then scrubbed from the URL); access_denied → cancelled copy
- Contracts + mutations for the three fixes

## 1.6.115 - 2026-08-15

### Bachus — personal-mail UX radical simplify

- **My email:** DSGVO ack sits above OAuth; IMAP/app-password collapsed behind one disclosure; username optional (defaults to email); hosts no longer HTML-`required` inside closed `<details>`
- **Share Inbox/Sent:** one dialog (checkbox + typed SHARE) — no stacked `prompt()`
- **Sales Inbox Reconnect:** deep-links `#crm-ws-personal-mail-mine` with focus; clearer “synced mail stays” copy
- **Connected chrome:** signature in `<details>`; Sync is the primary large CTA; folder/signature chrome hidden while reconnecting
- **Companion:** share disclosure moves into the share modal (no permanent switch clutter); OAuth-first empty/reconnect copy
- Contracts + Playwright/Vitest/axe gauntlet

## 1.6.114 - 2026-08-15

### Email-rebind guard + atomic auth_fail (Aristoteles)

- **P1:** IMAP reconnect heal refuses `id` + different email (server `assertOwnedPersonalEmailUnchanged`; heal UPDATE no longer rewrites `email_address`); web passes `id` only when form email matches the quarantined box
- **P2:** `recordAuthFailure` atomically increments `auth_fail_count` (`auth_fail_count + 1`) and notifies only when the counter first hits **3** (concurrent dials cannot under-count quarantine)
- Contracts + mutations for rebind guard / atomic increment; l10n for disconnect-first copy

## 1.6.113 - 2026-08-15

### IMAP heal + mobile DSGVO (Aristoteles — US-002 parity)

- **P1:** IMAP `connectImap` resolves same-email owned mailbox and heals in place (clears OAuth tokens + quarantine); web passes `id` on reconnect
- **P1:** Companion shows DSGVO ack next to OAuth reconnect buttons (no longer buried in collapsed IMAP form)
- OAuth heal clears leftover `secret_enc`; web prefills email/username on reconnect
- Contracts + mutation for IMAP same-email heal; gauntlet **44/44**

## 1.6.112 - 2026-08-15

### OAuth quarantine heal (Aristoteles — US-002 ok⇄error)

- **P1:** OAuth `completeAuthorize` heals an owned personal mailbox with the **same email** in place (tokens + `status=ok` + clear auth_fail / dial cooldown) — no Disconnect wipe required
- Web My email: quarantine shows reconnect OAuth strip + clear “synced mail stays” copy
- Companion: OAuth buttons when empty or quarantined; AuthSession cancel uses `login.cancelled` (not “complete in browser”)
- Contracts + mutation for heal path; gauntlet **43/43** mutations

## 1.6.111 - 2026-08-15

### Residual hardening (Aristoteles — auth vs network / send UX)

- **php-imap sync:** only `classifyError === auth` calls `recordAuthFailure`; network/cert/unknown → dial backoff (`status=network|error`), not reconnect quarantine
- **OAuth refresh:** transport failure (`status=network`) and missing app config do **not** bump auth_fail; only provider auth rejection does
- **Send path:** personal/OAuth credential failures surface My email reconnect copy (not Team & mailbox)
- Live-probe auth returns `error` when quarantined; native OAuth callback unit test; mutations **42/42**

## 1.6.110 - 2026-08-15

### Spec closeout residual (Aristoteles — US-002 / US-008 races)

- **US-002 AC-3:** Auth quarantine notify centralized on `MailboxService::recordAuthFailure` so OAuth refresh + live-probe ×3 notify the owner (not only php-imap dial)
- **US-008 AC-3 race:** Companion OAuth `client=native` → web callback stays passive (`DataDisplayResponse`); state/code redeemed only via `POST …/oauth/complete`
- OAuth connect fallback returns full `PersonalMailboxAccount` shape; Sales Inbox compose focuses **To** first; preview keeps `role=article`
- Unit + integration + Vitest contracts; mutation needles for native state / owner notify

## 1.6.109 - 2026-08-15

### Spec closeout (Aristoteles — PERSONAL-EMAIL-SYNC + audit trail)

- **US-002 AC-3:** Personal mailbox auth quarantine notifies **owner only** (team still workspace fan-out)
- **US-008 AC-3:** Companion OAuth via `AuthSession` + `POST …/oauth/complete` (Basic auth; workspace-bound state)
- **§6 Observability:** `personal_mail.sync_fail_rate` when exception rate >10% of attempted dials in a syncAll run
- **Bugfix:** syncAll dial cooldown used `$this->time` (undefined) → `$this->timeFactory`
- Contracts + mutations for owner notify / OAuth workspace bind; FAC-09 companion HTML tags asserted

## 1.6.108 - 2026-08-15

### Localization (Hermes — personal mail / Sales Inbox)

- Mobile companion: native `salesInbox.*` + `nav.salesInbox` for da/de/es/fr/it/nb/nl/pl/pt/sv (informal register)
- Server l10n: personal-mail leftovers (`Reconnect`, Review personal-mail errors, sync hint, …) for all suite locales + `pt_BR`
- Soft Legal Q1 path: plain privacy-notice ack copy in `myEmail` (manager confirms notice; no invented DPA gate)
- DE register cleanup: Sie→du mix fixed in Sales Inbox empty states
- Validated: `check-l10n-parity`, placeholders, mobile `localeParity` Jest

## 1.6.107 - 2026-08-15

### Architecture / Ops (Aristoteles gauntlet — Zeus SF-01…SF-05)

- **SF-01:** UNIQUE `(mailbox_id, message_id)` after empty→NULL normalize + duplicate clear (`Version1017`/`1018`)
- **SF-02:** Cron IMAP dial exponential cooldown (`sync_fail_streak` / `sync_cooldown_until`); manual Sync now still runs
- **SF-03:** Real failures emit `personal_mail.sync_fail`; policy-off is `personal_mail.sync_skip` (ops can alert correctly)
- **SF-04:** Failed-draft 90d TTL + hollow unacked claim 24h purge via `MailRetentionPurgeJob`
- **SF-05:** UNIQUE `(workspace_id, owner_user_id, kind)` after retiring duplicate personal rows
- **UX/a11y:** Sales Inbox message list is `listbox`/`option` with ArrowUp/Down/Home/End + `aria-activedescendant`
- Contracts: `AristotelesPersonalMailHardeningContractTest`; dial + draft purge units; mutations +3

## 1.6.106 - 2026-08-15

### Architecture (Zeus FULL AUDIT — personal mail)

- **P0:** Define `MAIL_SYNC_MAX_MESSAGES` (= `MAILBOX_SYNC_CAP`) — OAuth/live-probe sync no longer fatals on undefined constant
- **P0:** Idempotency replay requires `smtp_acked` or outbound CRM row — unacked claims under send lock are abandoned (no fake `sent: true`)
- **P0/P1:** Outbound CRM index takes `crm_mbox_*` lock; skip insert when `outbound:{idem}` already exists
- **P1:** OAuth personal mailbox cap re-checked inside email connect lock
- **P2:** Timeline/touch failures after SMTP no longer fail the HTTP send; CAS release on draft claim; sync service doc corrected
- Migration `Version1016` adds `crm_mail_dft.smtp_acked` + backfill from existing outbound rows
- Contracts: `ZeusPersonalMailArchitectureContractTest`; mutations extended

## 1.6.105 - 2026-08-15

### Security / Hardened (Aristoteles — Argus residual closeout)

- **SF-01 / AS-12:** Personal unmatched list metadata (subject/from/snippet/preview/mailbox labels) redacted without write/triage — Sales Inbox `listInbox` + `unmatchedMail`
- **SF-02:** IMAP/app-password personal connect rate-limited (`pm_imap_connect`, 20/h) before dial
- **P1:** `reuseDraftId` / `claimFailedDraft` enforces failed-draft actor ACL (no peer hijack)
- **P2:** Personal send fail-closed without `workspace_id`; orphan draft kind fail-closed; OAuth `sessionUserId` required
- **UX/a11y:** Tab + list roving tabindex; quiet auto-sync still announces failures to live region; Needs triage badge (web + companion)
- Contracts + mutations extended (29 focused kills target)

## 1.6.104 - 2026-08-15

### Security (Argus FULL AUDIT — personal mail Absolute No-Gos)

- **NG:** `MobileMailController` CSRF gate verifies Basic credentials (`checkPassword` + UID match); presence-only `Authorization` no longer bypasses CSRF
- **NG:** Failed drafts list/get/delete/resend scoped by actor — personal SMTP failures are owner-only (not any `mail.read`)
- **NG:** `unmatchedMail` redacts personal `preview` for callers without write/triage (AS-12)
- **MF:** OAuth `completeAuthorize` binds session UID to state `userId` (`hash_equals`)
- **MF:** Personal send / send-account list respect `personalMailEnabled`
- **MF:** Team mailbox `save` lowercases email addresses (parity with personal connect)
- Contracts: `ArgusPersonalMailSecurityContractTest`; mutations extended

## 1.6.103 - 2026-08-15

### Security / Hardened (Personal mail P0 — Aristoteles)

- **P0:** Review discard/spam never dials personal IMAP; CRM-close only for `personal_mail_shared_unmatched`
- **P0:** Team mailbox DELETE refuses `kind=personal` (owner disconnect still works with `allowPersonal`)
- **P0:** `replyMail` / preview use `MailAclLogic` + real write/triage flag; AS-12 personal unmatched bodies need triage
- **P0:** `triageAssign` refuses `visibility=private`; `syncWorkspace` / peer team sync are team-only
- **P1:** Sales Inbox preview generation guard; quiet auto-sync only sticks on success; reply prefers message `mailboxId`; mobile personal sync rate-limited
- Contracts + focused mutations for the new gates

## 1.6.102 - 2026-08-15

### Changed (Bachus — Sales Inbox personal-mail UX simplify)

- **Sales Inbox:** One-line disclosure, giant Mine/Team/Unmatched tabs, quiet status line, account filter only when 2+ accounts
- **Empty Mine:** Primary **Connect my email** CTA; first visit auto-updates personal mail quietly
- **Compose:** Cc behind **Add Cc** disclosure; Reply/Send use large primary targets
- **Companion:** Matching empty CTA → My email, collapsed Cc, larger tabs, one quiet auto-sync on Mine focus
- Contracts: `BachusPersonalMailUxContractTest`, Vitest + Playwright axe journeys updated

## 1.6.101 - 2026-08-15

### Fixed / Hardened (Personal Email Sync — Sent dedupe + OAuth HTML + Inbox IA)

- **P0:** SMTP sends real `Message-ID`; CRM outbound stores it; IMAP Sent re-ingest merges by Message-ID (adopts IMAP UID) — no duplicate Talk/Inbox rows
- **P0 / MH-05:** Live-probe / OAuth sync persists `bodyHtml` via `peekBodiesForUids` (parts 2 / 1.2), not plain-only
- **US-004 AC-3:** Sales Inbox shows **Shared folders: …** banner (web + companion)
- **§7 IA:** Account filter switcher + auth-error Reconnect CTA on Sales Inbox (web + companion)
- Mutations: Message-ID header, live-probe `bodyHtml`, outbound share visibility

## 1.6.100 - 2026-08-15

### Fixed / Hardened (Personal Email Sync — MH-05 / MH-07 production closeout)

- **MH-05 / Q7:** Sync now persists capped plain (+ HTML when present) into `body_text` / `body_html` (php-imap + live-probe), with `MAIL_SYNC_BODY_FETCH_CAP`; on-demand open backfills empty bodies
- **MH-07:** Personal CRM outbound visibility follows Sent folder `share_with_workspace` (shared ⇒ teammates with `company.read`)
- **IMAP peek:** `peekTextPrefixesForUids` / `fetchUidTextPreview` allow up to `MAIL_BODY_PLAIN_MAX_BYTES` (no longer hard-capped at 8 KiB / preview size)
- **Companion:** Sales Inbox Shared / Only you badges; reply passes `mailboxId`
- Focused mutations extended for body sync + outbound share visibility

## 1.6.99 - 2026-08-15

### Fixed / Hardened (Personal Email Sync — auditor residual closeout)

- **NN-07 companion:** Inbox/Sent share requires typing SHARE (modal), not auto-inject
- **AS-19 companion:** reply keeps quote in `quotedBody` (signature before quote)
- **Q8 companion:** per-folder sync enable/disable + fuller share disclosure copy
- **Send NFR:** plain 200 KiB / HTML 512 KiB with `MAIL_BODY_TOO_LARGE`; optional `cc[]` on SMTP + CRM
- **Match:** personal ingest ignores forward-from hints (Team Inbox only)
- **Schema:** `attachment_meta_json` + `signature_included` on outbound CRM index rows
- **WCAG:** signature toggle announces; companion attach/errors use assertive live region
- Normative error code aliases: `MAIL_SEND_FORBIDDEN`, `MAIL_ATTACH_LIMIT`, `MAIL_ATTACH_FORBIDDEN`

## 1.6.98 - 2026-08-15

### Fixed / Hardened (Personal Email Sync — auditor closeout)

- **FAC-05/06:** Shared personal ingest sets `company_id` / Review unmatched correctly; private stays private
- **GDPR wipe:** Disconnect Review purge includes `personal_mail_shared_unmatched` (not only `shared_mail`)
- **FAC-15/17:** Disconnect deletes mailbox row (signatures + encrypted secrets) after children
- **NN-05 / FAC-19:** OAuth complete calls email-conflict assert + unique-violation → 409; connect lock per workspace email
- **MH-01 / OAuth:** Begin authorize requires member `dsgvoAck` (web + companion)
- **NN-07 / FAC-20 companion:** `shareConfirm=SHARE` + `shareExistingAck` confirm/retry
- **Body caps:** plain 200 KiB / HTML 512 KiB; companion safe plain fallback from sanitized HTML
- Mutation + FAC closeout suite expanded

## 1.6.97 - 2026-08-15

### Added / Hardened (Personal Email Sync — FAC closeout)

- **FAC-16:** Company Talk feed renders server-sanitized `bodyHtml` (same path as Sales Inbox)
- **FAC-13:** Attachment reject keeps a failed draft (`draftId` in error fields) before SMTP
- **FAC-07/08/10/11:** Behavioral send suite — From = mailbox email, Team picker ACL, includeSignature on/off, From-swap one signature
- **listSendAccounts:** exposes `signaturePlain`/`signatureHtml` for From-swap UX (no concatenation)
- **Companion:** signature PUT + OAuth begin routes; My email signature + Google/Microsoft connect
- Detox personal-mail smoke + expanded FAC contracts / mutations

## 1.6.96 - 2026-08-15

### Added / Hardened (Personal Email Sync — production closeout)

- **Q12:** Web My email + Team signature editors use rich HTML twin (`signatureHtml` + plain)
- **OAuth secrets:** Google/Microsoft client secrets encrypted at rest via `ICrypto` (legacy plaintext readable until re-save)
- **FAC-16 / NN-10:** Preview re-sanitizes `bodyHtml` on read; Sales Inbox renders sanitized HTML (plain fallback)
- **MH-13 companion:** in-app My email IMAP connect / share / sync / disconnect; NC Files attachable browser; compose toolbar h2/h3/ol/quote
- Mobile routes: personal-mailbox mutations + `/mail/attachable-files`
- FAC/NN contracts for HTML read path, signature wiring, mobile routes; OAuth encrypt + path-traversal unit tests

## 1.6.95 - 2026-08-15

### Added / Hardened (Personal Email Sync — v1 closeout)

- **NN-07 / FAC-20:** Share disclosure requires an explicit checkbox ack (existing + new share)
- **OAuth:** refresh/expiry failure bumps `auth_fail_count` and fails closed (no stale bearer)
- **Race:** folder share/save takes the same `crm_mbox_{id}` lock as sync
- **MH-16 / MH-18 companion:** markdown-lite rich toolbar + device→Files stage attach API (`/mail/attachments`)
- **E2E:** Playwright Sales Inbox + My email + share-checkbox contract
- **l10n:** DE strings for Sales Inbox / share disclosure / OAuth / compose

## 1.6.94 - 2026-08-15

### Added / Hardened (Personal Email Sync — M6 closeout)

- **Drafts (US-006 AC-2):** failed-send drafts retain HTML body, signature toggle, Files attachment ids, and quoted text (`Version1014`)
- **MH-18 Sales Inbox:** Files attach UI on reply compose (folder picker + `attachmentFileIds`)
- **MH-02 admin UX:** App settings section for Google/Microsoft OAuth client credentials + redirect URI
- **NN-06:** personal sync skips when workspace personal mail is disabled (API + `doSync` gate + rate limit)
- **FAC-01 / FAC-19:** `PERSONAL_MAIL_DISABLED` → HTTP 403; `MAIL_ACCOUNT_CONFLICT` → HTTP 409
- **FAC-04:** preview ACL no longer invents `company.read` from `company_id` alone
- **Observability:** `personal_mail.connected|sync_ok|sync_fail|share_folder|disconnected|sent`
- **Companion AS-20:** deep-links to web My email / Sales Inbox for signature + rich/Files compose
- Rich composer: subheading + clear formatting; company shared-send rich path retained
- FAC/NN contract tests + expanded personal-mail mutation gauntlet

## 1.6.93 - 2026-08-15

### Fixed / Hardened (Personal Email Sync — M5)

- **XOAUTH2:** IMAP sync/preview and SMTP send resolve OAuth tokens via `MailboxAuthService` (refresh + SASL)
- **AS-12:** Review / unmatched promote only shared-visibility mail; personal shared unmatched uses `personal_mail_shared_unmatched`
- **AS-16:** `shareExistingAck` before flipping existing private messages; folder upsert keeps folder ids
- **AS-19:** Send assembly is content → signature → quoted original
- **MH-16 / Q12:** Rich HTML composer on Sales Inbox reply; Include signature; Team signature API + settings
- **NN-10 / AS-18:** Normative HTML allowlist; https `img` only in signature sanitize
- Send success returns `contentType` + `signatureIncluded`

## 1.6.92 - 2026-08-15

### Added (Personal Email Sync — M1–M4 product cut)

- Workspace gate UI + API: enable personal sync with DSGVO ack; My email IMAP connect / folders / share disclosure / disconnect / signature
- Sales Inbox (Mine / Team / Unmatched) with ACL-safe preview
- Send From picker: personal owned + Team with `mail.send`; HTML sanitize + multipart/alternative; per-mailbox signature insert
- OAuth Google/Microsoft connect path (admin-configured client credentials)
- Companion mobile inbox / message / send-accounts / send APIs
- Signature columns on `crm_mailbox`; HtmlSanitizeService (NN-10); MailAcl + personal-send mutation gauntlets

### Security

- Private personal mail: owner-only on Talk/preview/inbox
- Personal send-as: owner-only; Team still requires mail.send
- Stored HTML bodies sanitized on ingest and compose

## 1.6.91 - 2026-08-15

### Added (Personal Email Sync — M1.1 schema)

- Mailbox accounts gain `kind` (`personal`|`team`), `owner_user_id`, OAuth token columns (`auth_kind`, `token_enc`, `refresh_enc`)
- Folders gain `share_with_workspace` + share acknowledgement stamps (default private for new personal folders; existing Team folders backfilled shared)
- Messages gain `visibility`, `owner_user_id`, `body_text` / `body_html`, `cc_addrs`, `forward_from_hints`
- Workspace settings: `personal_mail_enabled` + DSGVO ack columns
- `MailAclLogic` — private = owner only; shared+linked = company.read; shared unmatched = mail rights

## 1.6.90 - 2026-08-14

### Fix (auto-match after reopen — forward hints from preview)

- **Hint backfill:** open mail with stored preview but empty `forward_from_hints` now extracts customer email before rematch (no IMAP required when body text exists)
- **Repair pipeline:** IMAP repair includes hint-empty rows even when To/preview are populated; body probe runs when hints still missing after preview parse
- **Rematch:** persists forward hints extracted during reconcile so the next pass does not re-parse
- **Sync cap:** post-sync repair raised to 40 probes; DB hint backfill up to 500 rows per pass

## 1.6.89 - 2026-08-14

### Fix (Review suggestions on Thunderbird forwards)

- **Review Vorschlag** scores embedded customer (`gattinger@banss.at`), not team forwarder (`alex@…`) — no more wrong “Julian Groshaupt Development” hits when alex is listed on multiple companies
- **Thunderbird forward block** without email on `From:`/`To:` lines: recover customer address from signature before nested `*Von:*` quotes

## 1.6.88 - 2026-08-14

### Fix (team forwards misfiled on internal company)

- **Match order:** forward hints beat envelope From; team-domain senders no longer auto-file as `person_email` on internal companies where the forwarder is listed as a contact
- **Sync now:** reopens already-matched team forwards (`Fwd:` / `WG:` + team From filed as `person_email`) so repair + rematch can file on the embedded customer (e.g. Banss)

## 1.6.87 - 2026-08-14

### Fix (Outlook / Thunderbird / Nextcloud Mail — forward & redirect)

- **Redirect:** RFC `Resent-From` is the redirector (team), not the customer — no longer blocks body probing or mis-files
- **Thunderbird 91+ redirect:** `Reply-To` header parsed and used as original-sender hint when envelope From is team
- **Outlook / Thunderbird forward:** additional body markers (`-------- Forwarded Message --------`, German variants)
- **IMAP fetch:** `Reply-To` included in header peek alongside `Resent-From` / `X-Original-From`
- **needsBodyProbe:** skips body peek only when actionable (non-team) header hints exist

## 1.6.86 - 2026-08-14

### Fix (shared mail sync — production reliability)

- **Sync now** reports only truly new index rows (not every IMAP duplicate); header backfills count separately
- Matched mail on **archived companies** is reopened and returned to Review so active customer records can receive it
- Post-sync Review pass is bounded (fast HTTP): reopen archived matches → repair headers → file known customers → promote to Review
- Sync summary stays visible after reload (“Filed N…”, “Added N to Review…”) instead of reverting to the idle mailbox line
- Manual triage rejects archived companies; matched rows can be reopened for rematch

## 1.6.85 - 2026-08-14

### Fix (shared mail — production rematch)

- Body probes now persist a plain-text `preview` at ingest so Review rematch can re-extract forward hints from older indexed mail
- Open mail missing To/CC, preview, or forward hints is backfilled from IMAP before known-customer reconcile (capped per run)
- **Sync now** promotes open shared mail into Review and auto-files known contacts immediately (no Review page visit required)
- Outlook forward blocks without a `Gesendet:` / `Sent:` line between `Von:` and `An:` are parsed correctly
- Person index for ingest/rematch excludes archived companies

## 1.6.84 - 2026-08-14

### Fix (shared mail — forwarded customer mail)

- Outlook-style forwards (`Von:` / `Gesendet:` / `An:`) now file onto known contacts even without a “Weitergeleitete Nachricht” marker
- When the quoted block shows team on `Von:` and the customer on `An:`, rematch uses the customer address
- Forward sender hints are stored on indexed mail and re-used on Review rematch (also re-extracted from preview when missing)

### Fix (email normalization)

- Mail ingest stores trimmed, lowercased From/To/CC addresses; forward hints normalized the same way
- Person emails trimmed + lowercased on insert/update; lookup compares `TRIM(LOWER(email))` so legacy padded rows still match
- Import-created contacts lowercased to match Person API behaviour

## 1.6.83 - 2026-08-14

### Fix (shared mail sync)

- Re-sync no longer ignores already-indexed messages: open rows with empty To/CC get header backfill and recipient rematch (CC’d / team-sent copies can land on customers again)
- Review promotion drains all open unmatched mail by id (not only the newest 200 by sent date), so older or late-indexed messages still appear for assignment
- Review reconcile uses each message’s `matched_how` (e.g. recipient_email) when auto-closing assigned rows

### Fix (GDPR erase)

- Restore agreement edit-conflict cancellation on company erase (mutation debris removed)

## 1.6.82 - 2026-08-13

### Changed

- Packaging release: version bump for ready4upload / production archive (local install already at 1.6.81).

## 1.6.81 - 2026-08-13

### Fix (Review inbox)

- Open Review rows for shared mail that is already deleted/discarded no longer linger as “ghost” messages after you delete others
- List window uses a stable queue (not recently-touched order), so deletes show the next real messages instead of a random slice
- Clearer count (“Showing X of Y…”) and refresh announcement after delete

## 1.6.80 - 2026-08-13

### UX (Talk / company 360)

- Search the Gespräch / Talk message list by from, subject, date, or snippet (live filter + Search button)
- Shows match count while filtering; clear empty state when nothing matches
- Search query survives mailbox sync / board reload on the same company page

## 1.6.79 - 2026-08-13

### Fix (dates / display)

- Locale date labels no longer render as 1 January 1970: Nextcloud `IL10N::l()` receives a Unix timestamp instead of `DateTimeImmutable`
- JS “today” / due defaults use local calendar `YYYY-MM-DD` (no UTC day shift via `toISOString`)

### Fix (import / duplicates)

- Import never auto-links onto archived companies; blocked/taken slugs get a fresh unique suggestion instead of `SLUG_TAKEN`
- Companies list flags case-insensitive name twins and points to **Merge into…**
- Import ambiguous matches show slug + match reason so same-name twins are distinguishable

### Fix (mail preview)

- Multipart IMAP bodies prefer decoded `text/plain` (no more MIME soup / quoted-printable headers in the reader)
- Message body is explicitly selectable; **Copy message** button with clipboard fallback

### UX (shared mailboxes)

- Up to three team inboxes: Sync now / background sync already hit every healthy box — status and settings copy now say so clearly
- Partial sync success reports when some mailboxes need attention

## 1.6.78 - 2026-08-13

### Fix (Review / shared mail)

- Review suggestion catalog paginates the full company book (no more silent 500-company cap) so known contacts get suggestions
- Open shared mail that `MailMatchLogic` can uniquely place is rematched on Review sync / person create-update and leaves Review automatically
- From headers like `Name <email@domain>` normalize for exact person match
- Orphan open Review rows whose mail is already `matched` are closed on reconcile

### Fix (Mail preview)

- Invalid UTF-8 in IMAP bodies no longer turns `JSONResponse` into an HTML HTTP 500 — sanitize preview text and use `JSON_INVALID_UTF8_SUBSTITUTE` on API JSON
- Clearer client message + inline reader error (`role="alert"`) when a preview still fails

### UX

- My Accounts: **Add customer** header button and empty-state CTA
- Talk / Review: second click on the selected mail row closes the reader (accordion)
- Review empty state explains that only unplaceable mail stays in the inbox

## 1.6.77 - 2026-08-13

### Packaging

- Production upload packaging for website-order import expansion, multi-agreement apply coalesce, and dedicated company postal address fields (1.6.75–1.6.76)

## 1.6.76 - 2026-08-13

### UX / Import

- Dedicated company postal address fields (street, postcode, city, country) under Advanced — shown on company 360
- Website-order free-text `address` parses into those fields (no longer stuffed into notes when parse succeeds)
- Order webhook stores / fills empty address fields on create or email match

## 1.6.75 - 2026-08-13

### Fix (Import)

- Website-order JSON (top-level `companyName` + `agreements` with `seats`/`devices`) expands into one import row per license instead of collapsing into a broken `custom` onetime row
- Multi-agreement paste (GDPR / website-order) reuses the company created earlier in the same apply — no more `SLUG_TAKEN` / “1 row error(s)” on the second product
- Import summary lists concrete row error messages (not only the count)

## 1.6.74 - 2026-08-13

### Security / reliability

- GDPR company erase no longer builds SQL `IN()` lists from named-parameter tokens (`ceid0` / `pid0`) — that caused `SQLSTATE 42S22` and HTTP 500 on `DELETE /companies/{id}` when open edit conflicts or account plans existed
- Coaching bulk reassign filters use the same `PARAM_INT_ARRAY` / `PARAM_STR_ARRAY` pattern

### UX (l10n)

- German company status **Churned** is **Abgewandert** (was wrong MT “Aufgewühlt”)

## 1.6.73 - 2026-08-13

### UX (Strategy / Plans board)

- Strategy page matches Team/Companies: card shell, glance stats, quick filters, responsive table
- Goal-first inline edit with labelled fields and progressive “Priority, owner & review”
- Open plan deep-links to company 360 `#crm-account-plan`; cancel restores the form snapshot
- Race-safe reload token and busy Save (no double-submit paint races)

### Security / reliability (account plans)

- Upsert rejects unknown plan status values (active / paused / done only)
- Board hydrate exposes `priorityLabel`, `statusLabel`, and `openMoves`; attention-first sort

## 1.6.66 - 2026-08-12

### Security (Aristoteles audit — mail + checklist)

- Block private/local IMAP hosts on **Test connection**, live-probe sync, and SMTP send (SSRF)
- Sync loads mailbox by `id` **and** `workspace_id` (cross-workspace IDOR closed)
- AAAA + IPv6 literal checks in `isBlockedMailHost`; harden IMAP folder allowlist (`"\`{}` / controls)
- Folder paths validated on save and skipped when unsafe during sync
- Indexed IMAP delete shares the `crm_mbox_*` lock with sync/preview (ZEUS NG-01 — no dual sessions)
- Review discard: after successful IMAP delete, always `markRemoteDeleted` even if CRM close conflicts
- Manager reopen refuses `remote_deleted` messages (no zombie triage for mail gone on the host)
- Person remove: delete first (optimistic lock), then optional return of matched shared mail to Review
- Deal checklist `linkFile` requires the file under the **company folder** (not any readable NC file)
- Lost-deal cancel withdraws assign notifications and stamps `updated_at`
- Idempotent Done (already-done → soft success) for companion retries
- `MailPreviewText` strips inline HTML tags (not only full documents)
- GDPR company erase withdraws deal-checklist assign notifications **after** successful commit
- Checklist `completeItem` / empty `applyTemplate` never `rollBack` after a successful commit (post-commit serialize outside try)

### UX / a11y (Company 360 focus)

- Tab labels: **Bills & files** / **Plan & links**; `role="tablist"` without nested landmark
- Noscript + `@media (scripting: none)` reveal all panels if JS never runs
- Quieter hash race via microtask token (no dropped deep-links)
- Replace-template copy matches hard-delete of open tasks
- Review / Talk mail boards use table rows (`.crm-review-row`) — dead card/feed CSS removed
- Linked-apps glance KPIs as auto-fit stat tiles (calm accordion / attention expanded)
- Review list widens date-query fetch and sorts by sentAt; Talk feed matches Review board patterns

## 1.6.57 - 2026-08-12

### Architecture (Company 360 focus — Zeus audit remediation)

- Structural SECTION_TAB_MAP equality gate between classic bootstrap and Vitest core (kills silent key drift)
- `Number.isFinite` parity in scroll math (no coercing global `isFinite`)
- Fail-open paths latch `data-crm-focus-ready` + `data-crm-focus-degraded` to prevent re-init stacking
- Mutation set extended for map drift / isFinite / degraded latch

## 1.6.56 - 2026-08-12

### Reliability (Company 360 focus — Aristoteles)

- Focus tabs are a classic IIFE (no dynamic ESM `import`) so Nextcloud `Util::addScript` never leaves panels stuck hidden
- Document-level hash capture so glance KPI links outside the tab root still switch panels
- DOM-based tab resolution for `#crm-identity-links` (Money cold-merge vs More manage)
- Fail-open reveal + jump generation guard against init/race failures; numeric companyId sessionStorage keying
- Mutation gauntlet: `tests/Mutation/run-company360-focus-mutations.php`

## 1.6.55 - 2026-08-12

### UX (Company 360 focus — Bachus / Pipedrive-style)

- Replaced long scroll + 15-chip jump nav with four focus tabs: **Today**, **Deals**, **Money**, **More**
- First paint shows only Today (talk, people, to-do) — deals, money, and setup/history stay one tap away
- Removed redundant “How to use this page” callout; hash deep links still open the right tab and section
- Compact “At a glance” lead copy

## 1.6.52 - 2026-08-12

### UX (Link apps — Bachus)

- Cold company: one **Link apps** card (identity CTAs + status roster) — no twin “Linked apps” empty + “Link apps (setup)”
- Per-app rows: app name + badge only (no duplicate “Not linked to …” prose); MaintenanceCheck under More apps

## 1.6.51 - 2026-08-12

### UX (Account plan — Bachus)

- Plain-language lead: where this customer should go; goal first
- Priority / owner / review under one details block
- Plan moves use a large textarea + checklist with Done (no tiny single-line “New next step”)
- Clear hint: plan moves ≠ day-to-day To-dos

## 1.6.50 - 2026-08-12

### UX (Linked apps — Bachus)

- Empty projects / tickets / invoices no longer paint three nested empty cards inside “Linked apps”
- One setup empty state + a three-row status roster (Not installed / Needs fix / Linked · none open / Not linked); jump-nav IDs preserved
- When any sibling list has data, full peer cards render without the collapsed wrapper

## 1.6.45 - 2026-08-11


### UX (Import — Bachus)

- Import is one obvious path: drop file → auto-preview → one green Import button → Done
- Paste lives under “Paste instead”; source collapses after preview (“Change list” to go back)
- Include column only when a name needs a pick; ready-only lists hide it
- Clear status copy (no “Semmelbrösel”, no “Bereite importieren”); success says what was added
- Narrow viewport: import table region is keyboard-focusable; axe WCAG serious/critical clean

### Translations (mother-tongue pass)

- Rewrote **da / de / es / fr / it / nb / nl / pl / sv** (and regional variants) so UI copy reads like a native speaker wrote it — informal address, plain language, no Argos leftovers
- Style + gate: `l10n/NATIVE-STYLE.md`, `scripts/check-l10n-native-quality.php`; Argos `build-family-l10n.py` refuses to overwrite unless `--force-argos`

### Reliability (notifications & Today)

- Activity-due and renewal reminders claim the ledger only when a workspace can be notified; empty membership releases the claim for retry
- Activity-due chunk query skips already-ledged (activity, due) pairs so older claims cannot starve the queue
- Weekly digest claims only when recipients exist; releases claims if delivery cannot run
- Today renewals use a soonest-first window (−14…+60 days) instead of id-ordered candidates

### Mailbox / UX

- Saved mailbox folders round-trip on edit (API returns paths; form restores them); omitting `folders` on update no longer wipes sync folders
- Mailbox list formats last-sync with locale date/time; Edit has an accessible label
- Date-only display anchors at noon so user timezones cannot shift the calendar day

## 1.6.44 - 2026-08-09

### UX (Bachus — Company 360 radical simplicity)

- Phone tasks collapsed by default; Reminders use Title+Due+Done (type under More on add)
- 1-click Renew (confirm with end date + amount; no 3-field form); Cancel asks for a reason once
- Command-center hydrate failure shows Try again on Talk / Phone tasks / Money (not only Glance)
- Prepare meeting + Timeline collapsed; document Create/Unlink under More
- Jump label “Phone tasks”; primary CTA “Add reminder”

## 1.6.43 - 2026-08-09

### UX (Bachus — single-org plain language)

- Page leads drop “workspace / customer book” jargon
- Nav no longer shows “Your role”; Company 360 drops the role badge
- Team & mailbox: hide `#id · role` chrome; members show name + plain role (Look only / Can edit / Can manage team)
- Shared mailbox: essentials first; IMAP/SMTP under Advanced; plain shared-inbox confirmation
- Review sources: “Scan as teammate”; softer privacy checkbox copy
- Mail triage / Review disclosures speak about the team, not a workspace

## 1.6.42 - 2026-08-09

### UX (Company 360 — granny simplicity)

- **Deal notes** on create/edit (team-only, ≤4000 chars) with preview under the deal title
- **Edit deal** works on Company 360 (was a dead button — handler lived only on Pipeline)
- Stop reminders moved under **More** so each deal row has one primary action
- “How to use this page” is a compact callout instead of a full card
- Linked-app KPI strip collapses when everything is calm (expand on demand)
- German Company 360 copy normalised to consistent **du** (no formal Sie mix on primary flows)

### Security / reliability

- Deal notes included in conflict snapshots / optimistic-lock patches
- Notes cleared on empty string; GDPR export includes deal notes; mobile serialisation includes notes
- Migration `Version1010Date20260809210000` adds `crm_deal.notes`

## 1.6.41 - 2026-08-09

### Removed / simplified (single organisation)

- Multi-workspace UX is retired: no switcher, no “Create workspace”, no “Move to workspace”
- Creating additional workspaces and moving companies between books is blocked server-side (`MULTI_WORKSPACE_ENABLED=false`, max 1 book)
- Settings renamed to **Team & mailbox**; ACL still uses the default organisation workspace under the hood

### Security / reliability (single organisation)

- Create is serialized with an exclusive lock + DB transaction (no empty-table TOCTOU double-book)
- `BootstrapDefaultWorkspace` promotes an existing row instead of inserting a second Default
- Persona API rejects create/move at the controller (defense in depth)
- Ambient resolve + mobile list pin to the canonical organisation; `needsPicker` stays false
- Mutation suite `tests/Mutation/run-single-org-mutations.php` guards the fail-closed locks

## 1.6.40 - 2026-08-08

### Theme / responsive / a11y (Aristoteles)

- Primary/accent aliases map to `--color-primary-element` (accent + WCAG solid), not brand `--color-primary`
- Semantic light tints mix into `--color-main-background`; danger button tokens use `--crm-danger-fill`
- `prefers-contrast: more` + Nextcloud HC body theme boosts (invalid `high` media removed)
- Mobile: drop `100vw` max-width footgun; kanban/metric/import grids use `minmax(min(…, 100%), 1fr)`; form rows stack &lt;768
- Skip links / avatars / alert borders use element / `*-text` tokens

## 1.6.39 - 2026-08-08

### UX (Bachus)

- Money filtered empty state: full empty chrome + **Show all money** (no muted dead end)
- Company 360 docs / Talk feed / email log empty states expose in-body primary CTAs
- Email section: **Logged messages** (plain language) + Log email from empty state
- Account plan / promises load failures show **Try again**; plan health and step status use human labels

## 1.6.38 - 2026-08-08

### UX (Bachus)

- Company 360 **Mark done** is one tap (default note) — no Evidence jargon dialog
- Persona pages show **Try again** when load fails (Overview, This week, Review, …)
- Mail triage / Review company pickers show **name only** (no slug chrome)
- Brief chip: Collect overdue money; Deals view aria-label (not Pipeline view)

## 1.6.37 - 2026-08-08

### UX (Bachus)

- Company glance shows a visible error + **Try again** when overview load fails (no eternal Loading…)
- Coverage says **Needs links** (not Gap); glance shows company name without slug; Deals/Overview drop Pipeline jargon
- This week **Done** is one primary tap — evidence choices live under More (defaults to Activity done)
- Today quickstart copy drops Chase jargon

## 1.6.36 - 2026-08-08

### Reliability / UX

- Workspace settings boot status/error hosts on mailbox, Review sources, and Circles (no silent failures after the settings split)
- Review sources form locks until hydrate finishes; save uses a visible ready-guard instead of a silent no-op

## 1.6.35 - 2026-08-08

### UX / Design system

- Review inbox **Files** source uses the Nextcloud folder picker (no raw folder file-id typing — principle 11)
- Confirm-dialog acknowledgement checkbox uses `label.crm-choice` (44×44 dialog chrome)
- Workspace settings boot also runs on Review sources / Circles pages so save/load is not a silent no-op after the settings split

## 1.6.34 - 2026-08-08

### UX / Design system

- Team **Manager tools** is a single collapsible card (`crm-card--collapsible`), not `crm-advanced` nested inside a card (principle 14 — no box-in-box)
- Auto-open when someone needs help targets `details.crm-team-capacity-shell` directly

## 1.6.33 - 2026-08-08

### UX / Design system / a11y

- Fixed orphan CSS in `icons.css` after empty-state icon rule (brace integrity)
- Dialog hosts for `.crm-simple-list`, `.crm-dl`, `.crm-card__title`; global `.crm-empty-state__text`
- Contract: every feature CSS file must brace-balance and must not leave orphan properties after `}`

## 1.6.32 - 2026-08-08

### UX / Design system hygiene

- Removed dead legacy `.btn` / `.button` chrome CSS — production uses `.crm-btn` only (`:not(.btn)` link exclusions kept so cascade contracts stay fail-closed)
- Danger CTAs pinned to `--crm-danger-fill` via `.crm-btn--danger` (no pale `.btn-danger` alias path)

## 1.6.31 - 2026-08-07

### UX / Design system / a11y

- Dialog hosts for `.crm-muted`, `.crm-list` (+ items), `.crm-pre`, and `code`/`pre` so mail/deal modals keep WCAG contrast and readable layout
- Workspace mailbox advanced blocks use `crm-advanced__summary` (44×44); page-level bare `.crm-advanced > summary` also gets touch chrome

## 1.6.30 - 2026-08-07

### UX / Design system / a11y

- Dialog hosts for `.crm-choice` / `.crm-check`, `.crm-advanced` (+ 44×44 summaries), and `.crm-form-inline-error[hidden]` so modal forms keep granny layout and never leak hidden danger callouts
- `openModal` no longer builds legacy `.modal-backdrop` — native `<dialog>` only
- Pipeline / company-detail / persona advanced summaries use `crm-advanced__summary`; dialog checkboxes use `label.crm-choice`
- Support Us + jump-nav semantic fills mix into `--color-main-background`

## 1.6.29 - 2026-08-07

### UX / Design system / a11y

- Native `<dialog>` is a first-class chrome host: `.crm-btn*`, callouts/alerts/notices, switches, user-pickers, and form stacks apply inside `dialog.crm-native-dialog` (dialogs append to `document.body`, so `#app-content` scoping alone left modal CTAs unstyled)
- Import row semantic fills mix into `--color-main-background` (not `transparent`)
- Team empty-state CTA opens `/settings/mailbox` (members live there), not the settings mega-root

## 1.6.28 - 2026-08-07

### UX / Design system / a11y

- **All programmatic modals** (`createModal` / `openModal`) use native `<dialog class="crm-native-dialog">` with `showModal()`, focus restore, and idempotent close (no double-unlock race)
- Closed native dialogs stay hidden despite `.modal { display:flex }` (critical CSS display override)
- Persona empty-state CTA links to `/settings/review-sources`; country placeholder uses l10n
- Switch fields / keep-focused-visible treat native dialogs like modal surfaces

## 1.6.27 - 2026-08-07

### UX / Design system / security

- **Confirm dialogs** use native `<dialog class="crm-native-dialog">` + `crm-btn` (no pale `.btn` / backdrop-only stack for confirms)
- **Review sources** scan actors use directory combobox pickers (no raw username typing)
- Legacy `.btn-danger` / `.button.error` fills use `--crm-danger-fill` (WCAG solid error)
- Table wraps inside headed cards drop double borders (principle 14)
- Modal close / touch targets enforced at 44×44; spacing hotspots moved onto `--crm-space-*`

## 1.6.26 - 2026-08-07

### UX / Design system (settings multipage)

- **Workspace settings** split to `/settings/{section}` (mailbox, preferences, review-sources, circles, integrations) with chip nav + legacy `#anchor` forwarding
- **App settings** split to `/app-settings/{section}` (access, jobs, tokens, license, product-codes) with the same pattern
- Catalog-driven route allowlists; fail-closed template dispatchers (no request path concatenation)
- Manager-only workspace sections redirect instead of rendering privileged partials to viewers
- Purge remaining `style=` on company 360 identity, pipeline chips, company form, mailbox, settings JS dialogs — token utility classes instead

## 1.6.16 - 2026-08-03

### UX (Bachus — Review / Conflicts / Import / Settings / Team)

- **Conflicts:** keep-saved prefilled reason; min reason 3 chars; plainer lead
- **Import:** hide unavailable sources; one-click **Import clear rows**; Open companies after apply
- **Settings:** mailbox/people before preferences and Review sources; First reply / Calendar feed labels
- **Team:** Coach (not 1:1 prep); Missing coverage; Manager tools auto-open when someone needs help; no Chase in prep
- **Copy:** digest/promises plain language; short address confirms; no webhook/SLA jargon in admin tips

## 1.6.15 - 2026-08-03

### Changed

- **L10n hygiene:** sync rebuilds en/de from live PHP/JS literals only (309 orphan keys pruned, including old `Job:` leads); DE map pruned to match; leftover Circles / company-360 wording aligned

## 1.6.14 - 2026-08-03

### UX (Bachus — leftover granny pass)

- **Nav:** Pulse → Overview, Brief → This week, Pipeline → Deals; matching page titles
- **Page help:** plain one-line leads (no Job: prefix)
- **Agreements:** Type / Product (no Kind / product-code clutter in the picker)
- **Prepare / My customers:** Needs attention · Up to date; Open customer; forgiving empty prepare
- **Settings:** Short address, Today follow-up copy, Website lead/order links, Promises column (not WIP), Weekly promise check
- E2E + contracts: nav-clarity, a11y smoke extended, ExtremeSimplicity leftovers

## 1.6.13 - 2026-08-03

### UX (Bachus — whole app granny pass)

- **Today:** plain section titles (Follow up on deals, Customers to contact, My promises); CTAs Assign mail / Help teammate / I’ll take it; locale due dates
- **Money / Activities / Agreements:** empty states with primary CTAs; Activities drops Origin column; localized dues
- **Import:** human paste placeholder, translated meta (“rows · ready · need a choice”), Accept all clear rows
- **Team:** lean columns (no Open chase / Commit pipeline); Manager tools collapsed by default
- **Brief / Pulse / Strategy / Prepare:** week-of label, demoted cash detail, Promises left, High/Normal/Low priorities, Renewals in the next 90 days
- **Pipeline:** human stage live region; “Quote N days old”
- Quick starts: Open Today / My customers / Plans (no Command Board jargon)
- E2E: `bachus-whole-app.spec.js` + axe WCAG 2.1 AA

## 1.6.12 - 2026-08-03

### UX (Bachus — company 360 effortless)

- **After create:** land on company 360 with a clear **Next steps** coach (add person → add deal → log talk)
- **Empty states:** large in-body CTAs for people, deals, activities, and agreements
- **Add person / deal:** title/name first; value, products, phone, job title, and buying role under **More options**
- **Validation:** plain “Almost there / Please fill in…” instead of jargon; native `type=date` pickers kept
- **Dates:** locale-aware display in glance, feed, tasks, invoices, activities, promises
- **Copy / DE:** Communication (not Talked), Short address (not Slug), Stop reminders, Email & log hours; fixed broken DE strings
- Jump bar promotes **Deals**; quick start / page help rewritten for first-run clarity
- E2E: `bachus-company360.spec.js` + axe WCAG 2.1 AA on core journeys

## 1.6.11 - 2026-08-02

### Changed

- Packaging release: version bump for ready4upload / production archive (local install already at 1.6.10).

## 1.6.10 - 2026-08-02

### Changed

- **German locale:** refreshed `de` catalog after the Universal Review / Conflicts Bachus UX pass.

## 1.6.9 - 2026-08-02

### UX (Bachus simplify)

- **Review:** one disclosure, source kind chips, progressive Assign / No relation (snooze & follow-up under More)
- **Conflicts:** plain-language choices, manual field hidden until chosen, empty CTA → Today, human `entityLabel`
- **Settings:** Review sources as first-class card (deep-link `#crm-review-sources`)
- **Agreement create:** name/kind first; product/dates/amount under More
- **Company 360:** email log collapsed until there is history; contracts title id aligned
- Quick starts + page help for Review and Conflicts

## 1.6.8 - 2026-08-02

### Security

- **Import anomalies** default **off** until manager DSGVO ack; enqueue requires `dsgvoAckAt`
- Edit conflicts **notify both actors** (NC notification → Conflicts deep-link) and withdraw on resolve

### Added

- Review **Include archived companies in suggestions** (opt-in, fail-closed default)
- Settings toggle for import anomaly Review source
- Website EN/DE blurbs for Universal Review & Conflicts
- Companion **1.0.11**

### Fixed

- Product/GNQ: person cadence SoT is company `next_touch_on` (no phantom person `next_touch`)

## 1.6.7 - 2026-08-02

### Security

- Account plan upsert requires **optimistic `updatedAt`** + CAS (closes Strategy LWW race)

### Fixed

- Review **disclosure** always shown (unfiltered / shared mail / platform kinds) — GNQ §5.6
- Import `company_name` anomalies feed **org suggestions** and clear Assign / No-relation UX (no dead-end Apply&learn)
- Review suggestions include **companyName** (not bare `#id`) and prefill the search box

### Added

- Companion **1.0.10** (Play docs server floor ≥ 1.6.7)
- E2E: Review keyboard assign / unfiltered disclosure

## 1.6.6 - 2026-08-02

### Security

- Entity updates use **owned transactions** (`DbTransaction`) so conflict resolve outer txns are never rolled back by nested writers
- Review person stub creates under **company row lock** (idempotent email re-check)

### Fixed

- Apply once / Apply & learn now **updates company country** when the import anomaly has `companyId` (`POST …/apply-country`)
- Companion push switches **workspaceId** before opening company/Today
- Review assign optional **deal picker** (overhead only when no deal)
- Pipeline template no longer calls removed `OC::$server->getURLGenerator()` (NC 34) — board view loads again

### Added

- Pipeline period chips: All · This month · This quarter · Custom
- Companion **1.0.9**

## 1.6.5 - 2026-08-02

### Security

- Conflict resolve is **atomic**: mark + entity apply share one DB transaction (rollback on apply failure); `conflict.resolved` timeline after commit
- Deal **stage** participates in the three-way edit-conflict queue (CAS miss → `EDIT_CONFLICT` with `conflictId`)

### Added

- Review assign creates an idempotent **person stub** (contact / calendar / shared mail)
- Import **country** column → value-map resolve or `import_anomaly`; Review **Apply once** (no learn) beside Apply & learn
- Calendar ingress scores **ATTENDEE** emails for company suggestions
- Companion **1.0.8**: push payload `workspaceId`; Conflicts deep-link `?conflictId=`; deal-stage 409 opens Conflicts in browser

## 1.6.4 - 2026-08-02

### Security

- Company / Person / Agreement updates **require** `updatedAt` (parity with Deal); conflict apply uses explicit `skipOptimistic`
- Companion **1.0.7**: Review / Conflicts / company browser links include `?workspaceId=`

### Added

- Entity revision **90-day purge** job (`EntityRevisionPurgeJob`)
- Contacts Review ingress when source enabled + DSGVO + workspace-member `scanActor`
- Pipeline **custom date range** filter; mail-triage → Review **HTTP 301**
- Review overhead UX copy + timeline `review.assigned` metadata (GNQ §5.4)

## 1.6.3 - 2026-08-02

### Added

- Value-map **DELETE** API + Settings “Data quality — country maps” list/remove UI
- Review snooze presets **1d / 7d / 30d**, accessible inline follow-up note (no native `prompt`), empty CTA → `#crm-review-sources`
- Conflicts UI: entity labels (company/deal/person/agreement) + **manual** field choice
- Import anomaly **Apply & learn** → value-map then close as no-relation

### Security

- GDPR erase cancels **agreement** open conflicts and blanks conflict `base`/`current`/`incoming`/`fields` JSON plus Review `source_ref` / suggestion scrub for erased company
- Conflict resolve compensator: CAS on `status` + `resolved_by` + `row_version`; throws if restore fails (no silent orphan)

### Fixed

- `.crm-muted` now sets theme-safe muted ink (pipeline forecast disclaimer no longer fails WCAG AA contrast)

## 1.6.2 - 2026-08-01

### Added

- Agreement edit-conflict three-way + apply-on-resolve (parity with Company/Deal/Person)
- Calendar/Tasks Review ingress when manager configures URIs + DSGVO + workspace-member scan actor
- Review empty-state CTA to source settings; j/k/a/s/n keyboard; Contacts Send-to-Review when enabled
- Kanban company link, relative due labels, owner initials avatar, quote/files link polish
- Companion **1.0.6**: Today web-queue opens Review / Conflicts in the browser

### Fixed

- Conflict resolve compensator re-opens the queue row when entity apply fails (no orphan “resolved”)
- File/calendar/task `scanActor` must be a workspace member (settings + ingress)
- Settings form loads/saves Files folder id, calendar/task URIs, and scan actors
- License key events: map `invoicecheck` / `ic` / `ic2` → CRM product `ic` (was wrongly collapsing to InventoryCheck `iv`); map `inventorycheck` explicitly to `iv`; add DutyCheck / CustomerCheck aliases

## 1.6.1 - 2026-08-01

### Added

- Deal and Person edit-conflict three-way + apply-on-resolve (parity with Company)
- Manager Review **reopen** API + UI; Review **enqueue** for opt-in sources
- Review ingress job (shared-mail sync + opt-in Files folder walk with DSGVO + scan actor)
- Import ambiguous rows enqueue `import_anomaly` Review items; value-map country resolve on company save
- GDPR erase cancels open conflicts and scrubs Review PII for the company

### Fixed

- Conflict resolve no longer failed optimistic lock when applying field choices (null precondition)

## 1.6.0 - 2026-08-01

### Added — GNQ Bordmittel delta (CUC-GNQ-DELTA)

- **Universal Review Inbox** (`/review`) with shared-mail sync, suggestions, assign/snooze/no-relation/follow-up, overhead flag, fingerprint suppress (180d)
- **Edit conflict queue** (`/conflicts`) with three-way field compare, mandatory reason, apply-on-resolve (no LWW)
- **Workspace value-map learn** (`crm_value_map`) for country normalisation
- Entity revision snapshots (`crm_entity_rev`) for conflict base recovery
- Review source settings (`review_sources_json`) — non-mail sources default OFF + DSGVO ack
- Pipeline period filter (month/quarter/year) + kanban due line
- Today Command Board counts for open review / conflicts; companion Today meta `reviewOpenCount` / `conflictsOpenCount`
- Mail triage URL redirects to `/review?kind=shared_mail`

### Security

- Review mutations require workspace `write` (viewers read-only)
- Conflict resolve limited to managers or participating actors
- Cross-workspace company assign → 404; conflict insert survives aborted update transaction

## 1.5.50 - 2026-08-01

### Security

- Workspace **member add/import** now serialises on the workspace row (`SELECT … FOR UPDATE`) so concurrent inserts cannot race past `WS_MAX_MEMBERS`
- Restored Activity **pushToTasks** `claimTodoUri` CAS so concurrent VTODO pushes cannot double-create calendar tasks

### Tests

- Import `limitReached` + Circles/group unit coverage; member-import ACL contract; companion auto-lock edge cases; settings E2E for one-shot import UI

## 1.5.49 - 2026-08-01

### Added

- Workspace **one-shot Nextcloud group member import** (product §6 optional — not live sync)
- Workspace **one-shot Circles member import** when Circles is enabled (soft-dep; actor must belong to the Circle)
- Companion **auto-lock** after background (with timeout choices) when Require unlock is on

## 1.5.48 - 2026-08-01

### Changed / Granny UX (Bachus)

- Brief chrome: “This week: N of 5 promises” and plain cash labels (no WIP / Overdue AR jargon)
- Pipeline: lean 5-column table, Details disclosure, **Edit deal**, name-only New deal (value/SKU under More options)
- Company 360: **Call tomorrow** one-tap (no modal); next steps / High·Normal·Low instead of MAP / P1
- Chase badge: “New lead waiting too long” instead of Lead SLA
- Companion: Today **Done** one-tap; Deals inline stage chips; Settings segmented Appearance/Language

### Added / InvoiceCheck facade v3 aging (WP-ECO-CRM)

- Money + company invoice rows + Pulse overdue attention show `agingBucket` / days past due when present (text+chip); MoneyService stays null-safe if keys missing

### Fixed / Companion 1.0.4 (Aristoteles)

- Parse CUC `{ error: CODE, message }` so ACL refresh + conflict UX use real codes/messages
- Epoch-gate license demotion / recheck saves so sign-out cannot resurrect SecureStore
- Push deep-links wait until tab routes exist (WorkspaceGate no longer drops cold-start taps)
- Today read-only callout; Log activity type radiogroup; Settings full-width choices; Deals pull-to-refresh

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
