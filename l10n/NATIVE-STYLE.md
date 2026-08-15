# CustomerCheck l10n — mother-tongue style

English (`en.json`) is the source of truth for meaning. Every other locale must read as if a careful native speaker wrote the UI for a small-business CRM — warm, plain, short. Not brochure German/French, not support-desk formal, not raw machine translation.

## Voice (all locales)

- **Informal address** to the signed-in user (see table). Never mix formal and informal in one locale.
- **One job per sentence.** Prefer verbs users do: add, open, save, pick, mark done.
- **No leftover English** in user-facing prose (Workspace, Digest, Follow-up, opt-in, Allowlist, Mailbox, Leak, Brief as a noun for the weekly summary, Manager-Override, Shared Mailbox). Translate the idea.
- **Keep product / technical tokens:** CustomerCheck, ProjectCheck, TicketCheck, InvoiceCheck, Nextcloud, Talk, CRM, Deal/Deals (product term), Check Partner, CUC2, IMAP, SMTP, PDF, CSV, JSON, API, URL, IBAN, GDPR/DSGVO where that locale expects it, Circle (Nextcloud Circles).
- **Placeholders must match msgid exactly:** `%s`, `%d`, `%n`, `%1$s`, `%2$s`, `%%`, and any `{name}` forms. Never reorder printf numbered placeholders incorrectly; never drop one.
- **Punctuation:** use the locale’s normal quotation marks and dashes; do not paste English straight quotes when the locale prefers „…“ / «…» / “…”.
- **UI labels** (“Add reminder”, “Team & mailbox”, “Edit mailbox: %s”) must be translated — never left in English.
- **Avoid calques** of English idioms. “Clear what needs you now” is not a word-for-word job — say the natural equivalent of “do what is due / waiting on you”.
- **Plural rules** stay in the catalog `pluralForm` header; translate singular/plural msgids as separate keys when both exist.

## Address register

| Locale | Address the user as |
|--------|---------------------|
| de     | du / dein / dir     |
| da     | du / din / dig      |
| nb     | du / din / deg      |
| sv     | du / din / dig      |
| nl     | je / jij / jouw     |
| es     | tú / tu / te        |
| fr     | tu / ton / toi      |
| it     | tu / tuo / ti       |
| pl     | ty / twój / cię     |

## Recurring glossary (idea → prefer)

| English idea | Prefer (examples) |
|--------------|-------------------|
| Team & mailbox | Team + native word for mailbox/inbox (DE Postfach, FR boîte mail, NL mailbox/postvak, …) |
| shared mailbox / team inbox | “shared/team mail inbox” in natural words — not “Shared Mailbox” |
| weekly digest | weekly summary / weekly overview |
| workspace (retired multi-book jargon) | team / organisation / workspace→“arbeidsområde/espacio de trabajo/…” only when the string truly means the org book |
| follow-up | native “nachfassen / suivi / follow-up-opvolging / …” as a normal noun/verb |
| reminder | native reminder/Erinnerung/påmindelse/… |
| Today | native “today” page name when used as nav |
| Money | native “money/Geld/Penge/Dinero/…” when used as the Money page |
| Brief (weekly) | short weekly summary — not the English loan “Brief” unless that locale commonly uses it |
| Rotate if a link leaks | “if the link gets out, create a new one / renew the link” |

## Quality bar

A native speaker should not immediately spot “translationese”. If a string sounds like Google Translate, rewrite it. Prefer the tone of a calm colleague explaining the screen — the Bachus / granny test: a non-technical person understands on first read.

## After editing a base locale

```bash
php scripts/sync-l10n-variants.php
php scripts/rebuild-l10n-js.php
php scripts/check-l10n-parity.php
php scripts/check-l10n-placeholders.php
php scripts/check-l10n-de-quality.php   # de only today; extend when adding per-locale gates
```
