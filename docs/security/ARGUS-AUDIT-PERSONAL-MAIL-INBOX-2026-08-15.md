# Argus Security Review — CustomerCheck Personal Mail, Sales Inbox Mutate, Signature Logo, Connection Update

> **Security Mode: FULL AUDIT**  
> **Object:** Sales Inbox / Assign delete & mark-as-spam (CRM-only `outbound:` UIDs), personal ownership gates, `testConnection` blank-password reuse, My email connection update, signature logo (`img`).  
> **App version audited/patched:** 1.6.134 (MF-01/MF-02 + SF-01/SF-02/SF-03)  
> **Runtime:** Docker service `nextcloud` (`docker compose exec -u www-data nextcloud`)  
> **Date:** 2026-08-15  

---

## Table of contents

1. [Stakeholder Analysis](#1-multi-perspective-security-stakeholder-analysis)
2. [Asset & Data Classification](#2-asset--data-classification)
3. [Threat Modeling](#3-threat-modeling)
4. [Vulnerability Checklist](#4-vulnerability--weakness-enumeration)
5. [Patterns vs Anti-Patterns](#5-patterns-vs-anti-patterns)
6. [Verdict Register](#6-security-requirement-tiering--verdict-register)
7. [Security NFRs](#7-security-non-functional-requirements)
8. [Secure SDLC & Testability](#8-secure-sdlc--testability)
9. [Data Protection & Privacy](#9-data-protection--privacy)
10. [Incident Response](#10-incident-response--recoverability)
- [Assumptions Register](#assumptions-register)
- [Risk Register](#risk-register)
- [Blocking Questions](#blocking-questions)
- [Hardened Design Changelog](#hardened-design-changelog-audit-remediation)
- [Remediation Evidence](#remediation-evidence)

---

## 1. Multi-Perspective Security Stakeholder Analysis

| Perspective | Findings |
|---|---|
| **Attacker (opportunistic)** | Peer CRM-purges personal `outbound:`; blank-password Test; flood delete/spam. |
| **Attacker (targeted)** | Manager session → team secrets/SSRF; http logo tracking (blocked); CRM graph pivot. |
| **Data subject** | Personal credentials + indexed mail; breach notification depends on operator IR (AS-03). |
| **Security/Compliance/Legal** | GDPR veto if non-owners mutate personal mail or decrypt secrets via Test. |
| **Engineering/Architecture** | CRM-only still needs ownership; mutate rate limits; audit events. |
| **Ops/IR** | `personal_mail.mutate_denied`, `personal_mail.test_blank_password_refused`, `mail.mutate_rate_limited`. |
| **Business** | Trust + fine exposure if peers erase/probe personal mail. |
| **Downstream** | IMAP dial; recipients fetch https logos only. |
| **Future auditor** | This doc + PHPUnit + vitest + mutations. |

**Open Security Conflict:** None.

---

## 2. Asset & Data Classification

| Data Type | Sensitivity | Where Stored | Access | At Rest | In Transit | Retention |
|---|---|---|---|---|---|---|
| Personal IMAP/OAuth secrets | Critical | `secret_enc` | Owner; blank Test refused | ICrypto | TLS | Until disconnect |
| Team mailbox secrets | Critical | `secret_enc` | Mailbox managers + services | ICrypto | TLS | Life of mailbox |
| Indexed personal mail | High | `crm_mail_msg` | MailAclLogic | DB AS-01 | TLS | Purge policy |
| Signature logo URLs | Medium–High | Signature HTML | Owner; recipients https fetch | DB AS-01 | TLS | Life of signature |
| Security logs | Medium | NC logs | Ops | Platform | TLS | AS-02 |

---

## 3. Threat Modeling

| ID | Threat | Tier | Mitigation | Verdict |
|---|---|---|---|---|
| TH-01 | CRM-purge personal outbound without owner | Auth user | MF-01 | 🟢 |
| TH-02 | Blank Test decrypts personal secret | Mailbox cap | MF-02 + SF-03 log | 🟢 |
| TH-03 | Assign dials personal IMAP | Assign | assertRemoteMutateAllowed | 🟢 |
| TH-04 | SSRF mail host | Manager | isBlockedMailHost | 🟢 |
| TH-05 | XSS signature | Auth | HtmlSanitize | 🟢 |
| TH-06 | http logo tracking | Owner+3p | SF-01 https-only | 🟢 |
| TH-07 | Unthrottled delete/spam | Auth | SF-02 MAIL_MUTATE_* | 🟢 |
| TH-08 | Cross-WS IDOR | Auth | workspace_id | 🟢 |
| TH-09 | Silent purge storms | Auth | SF-03 warnings | 🟢 |

---

## 4. Vulnerability & Weakness Enumeration

| Area | Result |
|---|---|
| 4.1 Authn/session | Pass |
| 4.2 Authz | Pass (MF-01) |
| 4.3 Injection / SSRF / XSS | Pass |
| 4.4 CSRF/clickjacking | Pass (AS-05) |
| 4.5 Secrets | Pass (MF-02) |
| 4.6 Crypto | Pass |
| 4.7 Input / logos | Pass (SF-01) |
| 4.8 Supply chain | Pass (local mutations) |
| 4.9 Logging | Pass (SF-03) |
| 4.10 DoS | Pass (SF-02) |

---

## 5. Patterns vs Anti-Patterns

| ✅ | ❌ |
|---|---|
| Ownership before CRM-only | Early CRM-only return |
| https-only signature img | Allow http |
| Mutate rate limits + audit logs | Unlimited / silent denials |
| Refuse blank personal Test | Reuse any mailbox secret |

---

## 6. Verdict Register

### Must-Fix

| ID | Status | Evidence |
|---|---|---|
| MF-01 | 🟢 VERIFIED | Personal outbound unit tests + hardening contract + outbound-assign mutations |
| MF-02 | 🟢 VERIFIED | `testConnectionBlankPasswordRefusesPersonalMailbox` + refuse log |

### Should-Fix (closed)

| ID | Status | Evidence |
|---|---|---|
| SF-01 | 🟢 VERIFIED | https-only sanitize + signature-logo-core + vitest + UX copy |
| SF-02 | 🟢 VERIFIED | `assertMailMutateRateAllowed` + rate-limit unit test |
| SF-03 | 🟢 VERIFIED | Structured warnings + `testPersonalOutboundDeniedLogsSecurityEvent` |

### Absolute No-Gos

| ID | Status |
|---|---|
| NG-01 Personal mutate without owner | 🟢 VERIFIED |
| NG-02 Blank Test decrypt personal | 🟢 VERIFIED |
| NG-03 Secrets in VCS/client GET | 🟢 VERIFIED |
| NG-04 Unauthenticated mutate | 🟢 VERIFIED |

---

## 7. Security NFRs

NC session + ICrypto; RBAC + MailAclLogic + owner `hash_equals`; TLS; MAIL_MUTATE 60/user/h and 240/ws/h; SF-03 audit events; operator breach process AS-03.

---

## 8. Secure SDLC & Testability

PHPUnit (MailboxSync, HtmlSanitize, PersonalMailHardening, MailPreviewSecurity), vitest (signature-logo, personal-mail UX), mutations outbound-assign + argus-bachus-mail.

---

## 9. Data Protection & Privacy

personalMailEnabled + owner connect; https logos; disconnect/GDPR erase; subprocessors = IMAP/OAuth (AS-07).

---

## 10. Incident Response

Detect via SF-03 logs + IMAP auth quarantine; contain via disconnect / disable feature / session revoke; rotate via password re-save / OAuth.

---

## Assumptions Register

| ID | Assumption | Invalidates If | Owner |
|---|---|---|---|
| AS-01 | DB volume encryption | Unencrypted | Ops |
| AS-02 | Log retention ≥ 30d | Aggressive rotation | Ops |
| AS-03 | Breach notification process | No IR owner | Legal/Ops |
| AS-04 | SCA in release CI | Skipped | Eng |
| AS-05 | NC CSRF/framing | Custom bypass | Platform |
| AS-06 | Instance secret rotation | Never | Ops |
| AS-07 | IMAP/OAuth DPAs | Missing | Legal |

---

## Risk Register

| ID | Residual |
|---|---|
| R-01 Peer CRM-purge | Low |
| R-02 Peer Test decrypt | Low |
| R-03 Logo tracking | Low |
| R-04 IMAP DoS | Low |
| R-05 Host compromise | Critical (platform) |

---

## Blocking Questions

None.

---

## Hardened Design Changelog

| ID | Change |
|---|---|
| MF-01 | `assertRemoteMutateAllowed` before CRM-only |
| MF-02 | Refuse blank password for personal Test |
| SF-01 | https-only logos (PHP + JS + UX) |
| SF-02 | `MAIL_MUTATE_*` rate limits on delete/spam |
| SF-03 | Audit warnings for denials / rate limit / blank Test |
| Assign | Pass `$actor` as `rateLimitActor` |

---

## Remediation Evidence

Executed for 1.6.134 (see ship terminal): PHPUnit mail suites green; vitest personal-mail/signature green; outbound-assign mutations all killed; argus-bachus-mail MSI 100%. All MF/SF/NG in scope **🟢 VERIFIED**.
