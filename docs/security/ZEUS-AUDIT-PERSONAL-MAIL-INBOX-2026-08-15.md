# Zeus Architecture Review — CustomerCheck Personal Mail & Sales Inbox Mutate

> **Review Mode: FULL AUDIT**  
> **Object:** Personal mail + Sales Inbox / Assign mutate (CustomerCheck **1.6.136**)  
> **Runtime:** Docker `nextcloud`  
> **Date:** 2026-08-15  

---

## Verdict (executive)

Must-Fix MF-01/MF-02 and Absolute No-Gos from 1.6.135 remain closed. Should-Fix **SF-01–SF-04** are now **🟢 VERIFIED** in 1.6.136 (sync slice, no unlocked outbound index, rate-limit key purge, indexed `mail_msg_id`). Busy UX copy is plain language (WCAG).

---

## Table of contents

1. [System Context](#1-system-context--component-map)
2. [ADRs](#2-architecture-decision-records)
3. [Failure Modes](#3-failure-mode-register)
4. [Threat Model](#4-security--threat-model)
5. [Patterns](#5-patterns-vs-anti-patterns)
6. [NFRs](#6-nfr-audit)
7. [Ops](#7-deployment--ops)
8. [Verdict Register](#8-verdict-register)
9. [Data Contracts](#9-data-contracts)
10. [Testability](#10-testability)
- [Assumptions](#assumptions-register)
- [Evidence](#remediation-evidence)

---

## 1. System Context & Component Map

Unchanged from 1.6.135 map: MailboxSyncService owns `crm_mbox_*`; ReviewService owns Assign; MailSendService indexes outbound under the same lock; RateLimitService AppConfig counters; MailboxSyncJob → `syncAll`.

---

## 2. Architecture Decision Records

| ADR | Decision | Zeus |
|---|---|---|
| ADR-401 | Shared `crm_mbox_*` for live IMAP | ENDORSED |
| ADR-402 | CRM-only for `outbound:` UIDs | ENDORSED |
| ADR-403 | IMAP-then-CRM + Inbox compensate | ENDORSED |
| ADR-405 | Sync time-slice (8s) under lock, then release | ENDORSED — no mid-stream unlock |
| ADR-406 | Skip outbound CRM index if lock busy after retries | ENDORSED |
| ADR-407 | Purge expired `rl_*` AppConfig keys on cron | ENDORSED |
| ADR-408 | `mail_msg_id` column + index on `crm_review_item` | ENDORSED |

---

## 3. Failure Mode Register

| ID | Mode | Mitigation | Status |
|---|---|---|---|
| FM-01 | Inbox IMAP/CRM split | finishInboxRemoteMutate | 🟢 |
| FM-02 | Assign resurrect remote_deleted | isRemoteDeleted skip | 🟢 |
| FM-03 | Multi-lock deadlock | Single crm_mbox_* | 🟢 |
| FM-04 | Unlocked outbound index | Deferred skip (SF-02) | 🟢 |
| FM-05 | Sync starves mutate UX | 8s slice (SF-01) | 🟢 |
| FM-06 | Assign close O(n) | mail_msg_id index (SF-04) | 🟢 |
| FM-07 | rl_* AppConfig growth | purgeExpiredKeys (SF-03) | 🟢 |

---

## 4. Security & Threat Model

Argus gates unchanged. No new trust boundaries. Indexed column is non-secret FK-like int.

---

## 5. Patterns vs Anti-Patterns

| ✅ | ❌ |
|---|---|
| Time-slice sync then release lock | Unlock mid-php-imap stream |
| Skip CRM write without lock | Unlocked insert after SMTP |
| Delete expired rate keys | Eternal zeroed AppConfig blobs |
| Equality on mail_msg_id | Full open-mail PHP scan |

---

## 6. NFR Audit

| Category | Target / current |
|---|---|
| Sync lock hold | ≤ ~8s slice + IMAP RTT per pass |
| Mutate availability | Can acquire lock between sync slices |
| Assign close | Indexed equality when migrated |
| Observability | outbound_index_deferred; rate purge silent |

---

## 7. Deployment & Ops

- Migration **Version1019** adds `mail_msg_id` + backfill — run `occ upgrade` / app update.
- Rollback: code-only reverse loses column (harmless nullable); prefer forward.
- Busy UX: “This mailbox is updating. Wait a few seconds, then try again.”

---

## 8. Verdict Register

### Must-Fix (prior)
| ID | Status |
|---|---|
| MF-01 Inbox compensate | 🟢 VERIFIED (1.6.135) |
| MF-02 returnSharedMail remote_deleted | 🟢 VERIFIED (1.6.135) |

### Should-Fix
| ID | Status | Evidence |
|---|---|---|
| SF-01 Sync slice | 🟢 VERIFIED | MAILBOX_SYNC_SLICE_SECONDS + loop breaks |
| SF-02 No unlocked index | 🟢 VERIFIED | outbound_index_deferred return |
| SF-03 rl_* purge | 🟢 VERIFIED | purgeExpiredKeys + RateLimitServiceTest |
| SF-04 mail_msg_id | 🟢 VERIFIED | Version1019 + close by column |

### Absolute No-Gos
| ID | Status |
|---|---|
| NG-01 Shared IMAP lock key | 🟢 |
| NG-02 Compensate after IMAP | 🟢 |
| NG-03 No Assign resurrect remote_deleted | 🟢 |
| NG-04 No unlocked CRM write after SMTP | 🟢 (SF-02) |

---

## 9. Data Contracts

`crm_review_item.mail_msg_id` nullable BIGINT; index `crm_ri_mmid (workspace_id, mail_msg_id)`; written on Assign promote; backfilled from `source_ref.mailMsgId`.

---

## 10. Testability

Contracts + RateLimitService unit + review-mail / outbound-assign mutations + db-standards gate for Version1019.

---

## Assumptions Register

| ID | Assumption | Invalidates If |
|---|---|---|
| AS-01 | ILockingProvider cluster-safe | File locks multi-node |
| AS-05 | 8s slice enough for UX between cron ticks | Single folder IMAP >8s always |

---

## Remediation Evidence

```text
# PHPUnit (Docker www-data)
php vendor/bin/phpunit … RateLimitServiceTest PersonalMailHardening MailPreviewSecurity MailboxSyncService ReviewMailSecurity

# Mutations
run-review-mail-security-mutations.php → all killed
run-outbound-assign-mutations.php → all killed

# Migration
occ upgrade / app:update customercheck → Version1019
```

**Final Zeus posture:** All MF/SF/NG in this register **🟢 VERIFIED** as of 1.6.136.
