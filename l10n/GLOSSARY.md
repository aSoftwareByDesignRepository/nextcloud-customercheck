# CustomerCheck — cross-locale glossary (binding)

Source of voice: `l10n/NATIVE-STYLE.md` (informal address — overrides generic “Sie/vous” guidance for this app).  
Cross-app terms: see `../../../../planning/l10n-glossary.md` (repo planning/).

## Language set (website footer + parity script)

| Code | Language | Address |
|------|----------|---------|
| en | English (source) | you |
| de | German | du / dein |
| fr | French | tu / ton |
| es | Spanish | tú / tu |
| da | Danish | du / din |
| nl | Dutch | je / jouw |
| it | Italian | tu / tuo |
| pl | Polish | ty / twój |
| sv | Swedish | du / din |
| nb | Norwegian Bokmål | du / din |
| pt_BR | Brazilian Portuguese | você (neutral UI) |

Also synced variants: `de_DE`←de, `en_GB`←en, `es_EC`/`es_MX`←es.

## Domain terms (use everywhere)

| English | de | fr | es | da | nl | it | pl | sv | nb | pt_BR |
|---------|----|----|----|----|----|----|----|----|----|-------|
| My email | Meine E-Mail | Mon e-mail | Mi correo | Min e-mail | Mijn e-mail | La mia e-mail | Mój e-mail | Min e-post | Min e-post | Meu e-mail |
| Personal email sync | Persönliche E-Mail-Sync | Sync e-mail perso | Sincronización de correo personal | Personlig e-mail-synk | Persoonlijke e-mailsync | Sync e-mail personale | Synchronizacja prywatnej poczty | Personlig e-postsynk | Personlig e-postsynk | Sync de e-mail pessoal |
| Team & mailbox | Team & Postfach | Équipe & boîte mail | Equipo y buzón | Team & postkasse | Team & mailbox | Team e casella | Zespół i skrzynka | Team & brevlåda | Team & postkasse | Equipe e caixa de correio |
| shared mailbox / team inbox | gemeinsames Postfach / Team-Posteingang | boîte partagée / boîte d’équipe | buzón compartido / bandeja del equipo | delt postkasse / team-indbakke | gedeelde mailbox / teaminbox | casella condivisa / inbox del team | wspólna skrzynka / skrzynka zespołu | delad brevlåda / teaminbox | delt postkasse / teaminboks | caixa compartilhada / caixa da equipe |
| Sales Inbox / Inbox | Posteingang | Boîte de réception | Bandeja de entrada | Indbakke | Inbox | Posta in arrivo | Skrzynka | Inkorg | Innboks | Caixa de entrada |
| Assign (nav / triage) | Zuordnen | À traiter | Asignar | Tildel | Toewijzen | Assegna | Przypisz | Tilldela | Tildel | Atribuir |
| Review (legacy synonym → Assign) | Zuordnen | À traiter | Asignar | Tildel | Toewijzen | Assegna | Przypisz | Tilldela | Tildel | Atribuir |
| Disconnect | Trennen | Déconnecter | Desconectar | Afbryd | Ontkoppelen | Disconnetti | Odłącz | Koppla från | Koble fra | Desconectar |
| Reconnect | Erneut verbinden | Reconnecter | Reconectar | Genopret | Opnieuw verbinden | Ricollega | Ponownie połącz | Återanslut | Koble til på nytt | Reconectar |
| Sync / Sync now | Sync / Jetzt synchronisieren | Sync / Synchroniser maintenant | Sincronizar / Sincronizar ahora | Synk / Synkroniser nu | Sync / Nu synchroniseren | Sync / Sincronizza ora | Sync / Synchronizuj teraz | Synka / Synka nu | Synk / Synkroniser nå | Sync / Sincronizar agora |
| Folders | Ordner | Dossiers | Carpetas | Mapper | Mappen | Cartelle | Foldery | Mappar | Mapper | Pastas |
| Share with workspace | Mit dem Workspace teilen | Partager avec l’espace de travail | Compartir con el espacio de trabajo | Del med arbejdsområdet | Delen met de werkruimte | Condividi con lo spazio di lavoro | Udostępnij przestrzeni roboczej | Dela med arbetsytan | Del med arbeidsområdet | Compartilhar com o espaço de trabalho |
| Signature | Signatur | Signature | Firma | Signatur | Handtekening | Firma | Podpis | Signatur | Signatur | Assinatura |
| DSGVO / GDPR | DSGVO | RGPD | RGPD | GDPR | AVG | GDPR | RODO | GDPR | GDPR | LGPD/GDPR |
| Churned | Abgewandert | Parti | Perdido | Mistet | Afgehaakt | Perso | Utracony | Förlorad | Mistet | Perdido |
| Manager override | Manager-Freigabe | Validation manager | Excepción del responsable | Lederoverstyring | Manager-vrijstelling | Approvazione manager | Nadpisanie przez managera | Chefsgodkännande | Lederoverstyring | Exceção do gestor |
| workspace | Arbeitsbereich | espace de travail | espacio de trabajo | arbejdsområde | werkruimte | spazio di lavoro | przestrzeń robocza | arbetsyta | arbeidsområde | espaço de trabalho |
| Open mail | Mail öffnen | Ouvrir le mail | Abrir correo | Åbn mail | Mail openen | Apri mail | Otwórz pocztę | Öppna e-post | Åpne e-post | Abrir e-mail |

## Technical constraints

- Format: Nextcloud `l10n/{lang}.json` + matching `.js` via `rebuild-l10n-js.php`
- Placeholders: `%s`, `%d`, `%n`, `%1$s`… — never drop or rename
- Product tokens kept: CustomerCheck, Nextcloud, Talk, IMAP, IMAP/SMTP, OAuth, Google, Microsoft, SHARE (typed confirm), Files
- Parity locales: en, de, da, es, fr, it, nb, nl, pl, sv, pt_BR
