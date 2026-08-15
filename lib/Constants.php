<?php

declare(strict_types=1);

namespace OCA\CustomerCheck;

/**
 * Closed enumerations, AppConfig keys and error codes for CustomerCheck.
 *
 * Everything here is normative from the PSD. Adding a key that is not in
 * PSD §4.9.3 is a forbidden invention (§16.1).
 */
final class Constants
{
	// ── AppConfig keys (§4.9.3) ─────────────────────────────────────────
	public const CONFIG_APP_ADMIN_USER_IDS = 'app_admin_user_ids';
	public const CONFIG_ACCESS_RESTRICTION_ENABLED = 'access_restriction_enabled';
	public const CONFIG_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const CONFIG_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';
	public const CONFIG_STALE_DEAL_DAYS = 'stale_deal_days';
	public const CONFIG_DIGEST_ENABLED = 'digest_enabled';
	public const CONFIG_DIGEST_WEEKDAY = 'digest_weekday';
	public const CONFIG_DIGEST_HOUR = 'digest_hour';
	public const CONFIG_REMINDER_HOUR = 'reminder_hour';
	public const CONFIG_ICS_TOKEN = 'ics_token';
	/** JSON map workspaceId => token for per-book ICS feeds (§15.2 optional multi-book). */
	public const CONFIG_ICS_WORKSPACE_TOKENS = 'ics_workspace_tokens';
	public const CONFIG_LEAD_TOKEN = 'lead_token';
	/** Website order intake token (§14.4 order path). */
	public const CONFIG_ORDER_TOKEN = 'order_token';
	public const CONFIG_CADENCE_ENABLED = 'cadence_enabled';
	public const CONFIG_VTODO_CALENDAR_URI = 'vtodo_calendar_uri';
	public const CONFIG_MAIL_RELATED_ENABLED = 'mail_related_enabled';
	/** Shared-identity W1 — CUC→PC display-name push on rename (MH-16). Default on. */
	public const CONFIG_IDENTITY_RENAME_PUSH = 'identity_rename_push';

	/** @var array<string, string> AppConfig key => default value */
	public const CONFIG_DEFAULTS = [
		self::CONFIG_APP_ADMIN_USER_IDS => '[]',
		self::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
		self::CONFIG_ACCESS_ALLOWED_USER_IDS => '[]',
		self::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '[]',
		self::CONFIG_STALE_DEAL_DAYS => '14',
		self::CONFIG_DIGEST_ENABLED => '1',
		self::CONFIG_DIGEST_WEEKDAY => '1',
		self::CONFIG_DIGEST_HOUR => '7',
		self::CONFIG_REMINDER_HOUR => '5',
		self::CONFIG_ICS_TOKEN => '',
		self::CONFIG_ICS_WORKSPACE_TOKENS => '{}',
		self::CONFIG_LEAD_TOKEN => '',
		self::CONFIG_ORDER_TOKEN => '',
		self::CONFIG_CADENCE_ENABLED => '1',
		self::CONFIG_VTODO_CALENDAR_URI => '',
		self::CONFIG_MAIL_RELATED_ENABLED => '1',
		self::CONFIG_IDENTITY_RENAME_PUSH => '1',
	];

	/** Keys whose value is secret and must be redacted for non–app-admins (§10.3). */
	public const SECRET_CONFIG_KEYS = [
		self::CONFIG_ICS_TOKEN,
		self::CONFIG_ICS_WORKSPACE_TOKENS,
		self::CONFIG_LEAD_TOKEN,
		self::CONFIG_ORDER_TOKEN,
	];

	/** User config key for the pipeline view preference (§4.9.3). */
	public const USER_CONFIG_PIPELINE_VIEW = 'crm_pipeline_view';

	// ── Enumerations ────────────────────────────────────────────────────
	/** @var list<string> */
	public const COMPANY_STATUSES = ['lead', 'active', 'paused', 'churned'];

	/** @var list<string> */
	public const LANGUAGES = ['de', 'en'];

	/** @var list<string> */
	public const AGREEMENT_KINDS = ['license', 'partner', 'onetime'];

	/** @var list<string> */
	public const AGREEMENT_STATUSES = [
		'draft', 'active', 'expiring', 'expired', 'superseded', 'cancelled', 'completed',
	];

	/** @var list<string> Statuses excluded from the reminder scan (§6.1). */
	public const AGREEMENT_TERMINAL_STATUSES = ['superseded', 'cancelled', 'completed'];

	/** @var list<string> §4.9.2 closed product-code set. */
	public const PRODUCT_CODES = [
		'azc', 'pc', 'mn', 'iv', 'dty', 'cuc', 'ic',
		'cp-small', 'cp-std', 'cp-prem',
		'cp-onb', 'cp-ws-half', 'cp-feat', 'custom',
	];

	/** @var array<string, string> product_code => required agreement kind (§4.9.2). */
	public const PRODUCT_CODE_KINDS = [
		'azc' => 'license',
		'pc' => 'license',
		'mn' => 'license',
		'iv' => 'license',
		'dty' => 'license',
		'cuc' => 'license',
		'ic' => 'license',
		'cp-small' => 'partner',
		'cp-std' => 'partner',
		'cp-prem' => 'partner',
	];

	/** @var array<string, int> Default partner hour quotas (§4.9.2). */
	public const PARTNER_DEFAULT_QUOTAS = [
		'cp-small' => 4,
		'cp-std' => 10,
		'cp-prem' => 25,
	];

	/** @var list<string> Ordered pipeline stages (D6). */
	public const DEAL_STAGES = ['lead', 'contacted', 'quoted', 'negotiation', 'won', 'lost'];

	/** @var list<string> Terminal stages — no further transitions (§4.7-C). */
	public const DEAL_TERMINAL_STAGES = ['won', 'lost'];

	/** @var list<string> */
	public const ACTIVITY_TYPES = ['call', 'email', 'meeting', 'todo'];

	/** @var list<string> */
	public const ACTIVITY_ORIGINS = [
		'user', 'sequence_contacted', 'sequence_quoted', 'sequence_negotiation',
		'sequence_won', 'system', 'webhook',
	];

	/** @var list<string> */
	public const INVOICE_STATUSES = ['none', 'sent', 'paid', 'overdue'];

	/** @var list<string> */
	public const EMAIL_DIRECTIONS = ['inbound', 'outbound'];

	/** @var list<string> */
	public const EMAIL_SOURCES = ['manual', 'compose', 'mail_app'];

	/** @var list<string> Timeline event types (§4.6). */
	public const EVENT_COMPANY_MERGED = 'company.merged';

	public const EVENT_TYPES = [
		'company.created', 'company.updated', 'company.archived', 'company.restored',
		self::EVENT_COMPANY_MERGED,
		'company.pc_linked', 'company.tc_linked',
		'company.pc_unlinked', 'company.tc_unlinked',
		'company.pc_rename_push_failed',
		'agreement.created', 'agreement.status', 'agreement.cancelled', 'agreement.superseded',
		'reminder.sent', 'reminder.skipped',
		'deal.stage', 'hours.logged', 'import.linked',
		'email.logged', 'email.composed', 'mail_sent',
		'invoice.paid', 'invoice.paid.ambiguous',
		'lead.webhook', 'order.webhook', 'digest.sent', 'quota.alert', 'gdpr.erased', 'note',
	];

	// ── Renewal engine (§6.2) ───────────────────────────────────────────
	/** @var list<int> Cadence offsets in days before `valid_until` (negative = after). */
	public const REMINDER_OFFSETS = [60, 30, 7, 0, -7];

	/** Sentinel ledger offset for the one-time Check Partner 80 % quota alert (§6.3). */
	public const REMLOG_OFFSET_QUOTA80 = 80080;

	/** Check Partner billing unit in minutes (CHECK-PARTNER §2.1). */
	public const HOURS_UNIT_MINUTES = 15;

	/** Days after which an hours entry becomes immutable (§4.6). */
	public const HOURS_LOCK_DAYS = 7;

	/** Quota alert threshold as a percentage (§6.3). */
	public const QUOTA_ALERT_PERCENT = 80.0;

	/** Grace window in days after expiry (§3). */
	public const GRACE_DAYS = 7;

	/** Renewal window in days before expiry (§3). */
	public const RENEWAL_WINDOW_DAYS = 60;

	/** Max items returned by GET /today (§10.3). */
	public const TODAY_MAX_ITEMS = 100;

	// ── Stage follow-up sequences (§14.2 / product §11.4) ───────────────
	public const SEQUENCE_CONTACTED = 'contacted_v1';
	public const SEQUENCE_QUOTED = 'quoted_v1';
	public const SEQUENCE_NEGOTIATION = 'negotiation_v1';
	public const SEQUENCE_WON = 'won_v1';

	/** @var list<string> Workspace roles (product §3.2). */
	public const WS_ROLES = ['viewer', 'member', 'manager'];

	/** @var list<string> */
	public const KAM_TIERS = ['standard', 'key', 'partner'];

	/** @var list<string> */
	public const BUYING_ROLES = [
		'economic_buyer', 'champion', 'day_to_day', 'technical', 'blocker', 'other',
	];

	/** @var list<string> */
	public const PERSON_STATUSES = ['active', 'left', 'unknown'];

	/** @var list<string> Loss reason codes (product §11.6). */
	public const LOSS_REASON_CODES = [
		'price', 'competitor', 'timing', 'no_decision', 'requirements', 'technical', 'internal', 'other',
	];

	/** @var list<string> Forecast buckets — planning labels, never cash (§11.7). */
	public const FORECAST_BUCKETS = ['commit', 'upside', 'pipeline'];

	/** @var list<string> CEO commitment kinds (§10.3). */
	public const COMMIT_KINDS = [
		'ar_chase', 'renewal_act', 'plan_push', 'link_debt', 'unbilled_review', 'custom',
	];

	/** @var list<string> */
	public const COMMIT_STATUSES = [
		'suggested', 'committed', 'done', 'missed', 'carried', 'abandoned',
	];

	/** @var list<string> */
	public const PROMISE_STATUSES = ['open', 'done', 'cancelled'];

	/** Default Brief WIP limit (product §10.2). */
	public const WIP_LIMIT_DEFAULT = 5;
	public const WIP_LIMIT_MIN = 3;
	public const WIP_LIMIT_MAX = 15;

	/** Max OOO cover duration in days (§13.4). */
	public const OOO_MAX_DAYS = 60;

	/** Max bulk reassign rows (§13.5). */
	public const BULK_REASSIGN_MAX = 200;

	/** Max Assign inbox items removable in one discard-bulk request. */
	public const REVIEW_DISCARD_BULK_MAX = 100;

	/** Max open escalations per workspace (§13.3). */
	public const ESCALATION_MAX_OPEN = 50;

	/** Max shared mailboxes / folders (§14.1). */
	public const MAILBOX_MAX_PER_WS = 3;
	/** Max folders persisted / sync-enabled per mailbox (spec NFR). */
	public const MAILBOX_MAX_FOLDERS = 20;
	/**
	 * Max folders returned from IMAP LIST for discovery / picker.
	 * Higher than MAILBOX_MAX_FOLDERS so new subfolders stay visible when the
	 * mailbox has many system folders — user still saves ≤ MAILBOX_MAX_FOLDERS.
	 */
	public const MAILBOX_LIST_FOLDERS_MAX = 200;
	/** Soft cap personal accounts per user per workspace (schema allows more). */
	public const PERSONAL_MAIL_MAX_PER_USER = 1;
	public const MAILBOX_SYNC_CAP = 500;
	/** Alias used by live-probe / OAuth sync — must equal MAILBOX_SYNC_CAP. */
	public const MAIL_SYNC_MAX_MESSAGES = self::MAILBOX_SYNC_CAP;
	/** Background IMAP ingest interval (MailboxSyncJob TimedJob). */
	public const MAILBOX_SYNC_INTERVAL_SECONDS = 900;
	/** Open unmatched mail promoted into Assign per list/sync page. */
	public const REVIEW_SHARED_MAIL_SYNC_PAGE = 200;
	/** Soft ceiling of open mail rows promoted in one Assign wake (DoS guard). */
	public const REVIEW_SHARED_MAIL_SYNC_MAX = 2000;
	public const MAIL_PREVIEW_MAX_BYTES = 51200;
	/** Max stored plain body per message after sanitize (spec §NFR body size — 200 KiB). */
	public const MAIL_BODY_PLAIN_MAX_BYTES = 204800;
	/** Max stored HTML body per message after sanitize (spec §NFR — 512 KiB). */
	public const MAIL_BODY_HTML_MAX_BYTES = 524288;
	/** @deprecated Prefer MAIL_BODY_HTML_MAX_BYTES / MAIL_BODY_PLAIN_MAX_BYTES */
	public const MAIL_BODY_MAX_BYTES = self::MAIL_BODY_HTML_MAX_BYTES;
	/** Max bytes of plain text peeked when detecting forwarded original From. */
	public const MAIL_FORWARD_BODY_PEEK_BYTES = 8192;
	/** Cap body probes per mailbox sync run (DoS / IMAP load). */
	public const MAIL_FORWARD_BODY_PROBE_CAP = 40;
	/**
	 * Cap full-body (plain) fetches per mailbox sync (MH-05 / Q7).
	 * Bounded so Sync now stays HTTP-fast and IMAP-friendly.
	 */
	public const MAIL_SYNC_BODY_FETCH_CAP = 40;
	/** Cap IMAP repair probes during manual Sync now (keep HTTP fast). */
	public const MAIL_SYNC_POST_REPAIR_CAP = 40;
	/** DB-only forward-hint backfill from stored preview per post-sync pass. */
	public const MAIL_SYNC_HINT_BACKFILL_CAP = 500;
	/** On-demand IMAP body preview rate limits (DoS / mailbox lockout protection). */
	public const MAIL_PREVIEW_USER_LIMIT = 30;
	public const MAIL_PREVIEW_USER_WINDOW = 3600;
	public const MAIL_PREVIEW_WS_LIMIT = 120;
	public const MAIL_PREVIEW_WS_WINDOW = 3600;
	/** Inbox/Assign delete & mark-as-spam (IMAP dial + CRM purge storms). */
	public const MAIL_MUTATE_USER_LIMIT = 60;
	public const MAIL_MUTATE_USER_WINDOW = 3600;
	public const MAIL_MUTATE_WS_LIMIT = 240;
	public const MAIL_MUTATE_WS_WINDOW = 3600;
	/** Shared-mailbox index retention floor + purge batch (§5.10 / §18.9). */
	public const MAIL_RETENTION_DAYS_MIN = 30;
	public const MAIL_PURGE_BATCH = 500;
	/** Safety ceiling per workspace; wall-clock budget usually stops earlier. */
	public const MAIL_PURGE_MAX_ROUNDS = 100;
	/** Soft wall-clock budget (seconds) for one purge pass across all workspaces. */
	public const MAIL_PURGE_BUDGET_SECONDS = 12;

	/** Mail send rate limits (§14.3 / §17.4). */
	public const MAIL_SEND_USER_LIMIT = 30;
	public const MAIL_SEND_USER_WINDOW = 3600;
	public const MAIL_SEND_WS_LIMIT = 200;
	public const MAIL_SEND_WS_WINDOW = 3600;

	/** Personal mail sync-now / OAuth connect (spec §9). */
	public const PERSONAL_MAIL_SYNC_USER_LIMIT = 10;
	public const PERSONAL_MAIL_SYNC_USER_WINDOW = 3600;
	public const PERSONAL_MAIL_OAUTH_USER_LIMIT = 20;
	public const PERSONAL_MAIL_OAUTH_USER_WINDOW = 3600;
	/** IMAP/app-password personal connect attempts per user (hour). */
	public const PERSONAL_MAIL_IMAP_CONNECT_USER_LIMIT = 20;
	public const PERSONAL_MAIL_IMAP_CONNECT_USER_WINDOW = 3600;

	/**
	 * Cron IMAP dial backoff after transient sync failure (SF-02).
	 * Cooldown = min(MAX, BASE * 2^min(streak-1, EXP_CAP)).
	 */
	public const MAIL_SYNC_DIAL_BACKOFF_BASE_SECONDS = 60;
	public const MAIL_SYNC_DIAL_BACKOFF_MAX_SECONDS = 3600;
	public const MAIL_SYNC_DIAL_BACKOFF_EXP_CAP = 6;

	/** Failed SMTP drafts (idempotency_key IS NULL) — SF-04. */
	public const MAIL_FAILED_DRAFT_TTL_DAYS = 90;
	/** Unacked send claims older than this are abandoned — SF-04. */
	public const MAIL_HOLLOW_CLAIM_TTL_HOURS = 24;
	public const MAIL_DRAFT_PURGE_BATCH = 200;

	/** Shared SMTP Files attachments (§14.3 / §18.4). */
	public const MAIL_ATTACH_MAX_COUNT = 5;
	public const MAIL_ATTACH_MAX_BYTES = 10485760; // 10 MiB per file
	public const MAIL_ATTACH_MAX_TOTAL_BYTES = 15728640; // 15 MiB combined
	/** @var list<string> */
	public const MAIL_ATTACH_ALLOWED_MIMES = [
		'application/pdf',
		'image/png',
		'image/jpeg',
		'text/plain',
	];
	/** @var list<string> */
	public const QUOTE_FILE_ALLOWED_MIMES = [
		'application/pdf',
	];
	public const QUOTE_FILE_MAX_BYTES = 10485760;

	/** Lead SLA default hours (§11.8). */
	public const LEAD_SLA_HOURS_DEFAULT = 24;

	/** Max skip_until horizon in days (§11.2). */
	public const NEXT_ACTION_SKIP_MAX_DAYS = 7;

	/**
	 * Multi-workspace (multi customer-book) is retired — one organisation per instance.
	 * Schema/ACL still use workspace_id; create/move/switcher UX stay locked off.
	 */
	public const MULTI_WORKSPACE_ENABLED = false;

	/** Max workspaces / members (§6). Single-org mode caps at one book. */
	public const WS_MAX_PER_INSTANCE = 1;
	public const WS_MAX_MEMBERS = 200;

	/** Freemail domains — never auto domain-match (§14.2 / §18.3). */
	public const FREEMAIL_DOMAINS = [
		'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.de', 'hotmail.com',
		'outlook.com', 'live.com', 'icloud.com', 'me.com', 'gmx.de', 'gmx.net',
		'web.de', 'mail.de', 'aol.com', 'proton.me', 'protonmail.com',
	];

	/** User config: last-used workspace id. */
	public const USER_CONFIG_LAST_WORKSPACE = 'crm_last_workspace_id';

	// ── Lead webhook (§14.4) ────────────────────────────────────────────
	public const HOOK_RATE_LIMIT = 30;
	public const HOOK_RATE_WINDOW_SECONDS = 600;
	public const HOOK_DEDUP_WINDOW_SECONDS = 86400;

	/** Session mutating API rate limit (§15): 120 / user / min. */
	public const SESSION_RATE_LIMIT = 120;
	public const SESSION_RATE_WINDOW_SECONDS = 60;

	// ── Notification subjects (§15) ─────────────────────────────────────
	public const NOTIFY_REMINDER = 'crm_reminder';
	public const NOTIFY_QUOTA80 = 'crm_quota80';
	public const NOTIFY_DIGEST = 'crm_digest';
	public const NOTIFY_LEAD = 'crm_lead';
	public const NOTIFY_INVOICE_PAID = 'crm_invoice_paid';
	public const NOTIFY_KEY_GENERATED = 'crm_key_generated';
	/** Companion P2 — open activity due today / overdue (exactly-once ledger). */
	public const NOTIFY_ACTIVITY_DUE = 'crm_activity_due';
	/** Deal checklist item assigned to a colleague. */
	public const NOTIFY_DEAL_CHECKLIST = 'crm_deal_checklist';
	/** Parallel edit conflict opened — notify both actors (GNQ §6). */
	public const NOTIFY_EDIT_CONFLICT = 'crm_edit_conflict';

	/** @var list<string> */
	public const NOTIFICATION_SUBJECTS = [
		self::NOTIFY_REMINDER,
		self::NOTIFY_QUOTA80,
		self::NOTIFY_DIGEST,
		self::NOTIFY_LEAD,
		self::NOTIFY_INVOICE_PAID,
		self::NOTIFY_KEY_GENERATED,
		self::NOTIFY_ACTIVITY_DUE,
		self::NOTIFY_DEAL_CHECKLIST,
		self::NOTIFY_EDIT_CONFLICT,
	];

	// ── Error codes (§10.2, complete v1 set) ────────────────────────────
	public const ERR_SLUG_INVALID = 'SLUG_INVALID';
	public const ERR_SLUG_TAKEN = 'SLUG_TAKEN';
	public const ERR_COMPANY_NOT_FOUND = 'COMPANY_NOT_FOUND';
	public const ERR_AGREEMENT_ILLEGAL_TRANSITION = 'AGREEMENT_ILLEGAL_TRANSITION';
	public const ERR_AGREEMENT_LIMITS_INVALID = 'AGREEMENT_LIMITS_INVALID';
	public const ERR_DEAL_TERMINAL = 'DEAL_TERMINAL';
	public const ERR_DEAL_OWNED = 'DEAL_OWNED';
	public const ERR_LOST_REASON_REQUIRED = 'LOST_REASON_REQUIRED';
	public const ERR_CANCEL_REASON_REQUIRED = 'CANCEL_REASON_REQUIRED';
	public const ERR_HOURS_WRONG_KIND = 'HOURS_WRONG_KIND';
	public const ERR_HOURS_NOT_QUARTER = 'HOURS_NOT_QUARTER';
	public const ERR_HOURS_LOCKED = 'HOURS_LOCKED';
	public const ERR_CONFLICT_STALE = 'CONFLICT_STALE';
	public const ERR_IMPORT_AMBIGUOUS = 'IMPORT_AMBIGUOUS';
	public const ERR_FEED_TOKEN_REVOKED = 'FEED_TOKEN_REVOKED';
	public const ERR_HOOK_TOKEN_INVALID = 'HOOK_TOKEN_INVALID';
	public const ERR_HOOK_RATE_LIMITED = 'HOOK_RATE_LIMITED';
	public const ERR_HOOK_VALIDATION = 'HOOK_VALIDATION';
	public const ERR_EMAIL_INVALID = 'EMAIL_INVALID';
	public const ERR_INVOICE_PAID_AMBIGUOUS = 'INVOICE_PAID_AMBIGUOUS';
	public const ERR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERR_NOT_AUTHENTICATED = 'NOT_AUTHENTICATED';
	public const ERR_RATE_LIMITED = 'RATE_LIMITED';
	public const ERR_NOT_FOUND = 'NOT_FOUND';
	public const ERR_VALIDATION = 'VALIDATION';
	public const ERR_LINK_CONFLICT = 'LINK_CONFLICT';
	/** PC/TC created but CRM link persist failed — keep sibling row (BQ-4a). */
	public const ERR_LINK_INCOMPLETE = 'link_incomplete';
	public const ERR_IDEMPOTENT_REPLAY = 'IDEMPOTENT_REPLAY';
	public const ERR_SIBLING_UNAVAILABLE = 'SIBLING_UNAVAILABLE';
	public const ERR_MERGE_SAME = 'MERGE_SAME';
	public const ERR_MERGE_TARGET_ARCHIVED = 'MERGE_TARGET_ARCHIVED';
	public const ERR_MERGE_SOURCE_ARCHIVED = 'MERGE_SOURCE_ARCHIVED';
	public const ERR_MERGE_LINK_CONFLICT = 'MERGE_LINK_CONFLICT';

	/** Command Center (CUC-PLAN-CC Appendix B). */
	public const ERR_CALLBACK_DUE_REQUIRED = 'CALLBACK_DUE_REQUIRED';
	public const ERR_FILES_PERMISSION_DENIED = 'FILES_PERMISSION_DENIED';
	public const ERR_FILES_NOT_FOUND = 'FILES_NOT_FOUND';
	public const ERR_FILES_NOT_FOLDER = 'FILES_NOT_FOLDER';
	public const ERR_FILES_NOT_FILE = 'FILES_NOT_FILE';
	public const ERR_TASKS_PUSH_FAILED = 'TASKS_PUSH_FAILED';
	public const ERR_TASKS_UNAVAILABLE = 'TASKS_UNAVAILABLE';
	public const ERR_CONTACTS_UNAVAILABLE = 'CONTACTS_UNAVAILABLE';
	public const ERR_CONTACTS_UID_INVALID = 'CONTACTS_UID_INVALID';
	public const ERR_FACADE_UNAVAILABLE = 'FACADE_UNAVAILABLE';

	/** Human conversation lookback for “in conversation” chip (CC §6.4). */
	public const CONVERSATION_LOOKBACK_DAYS = 14;

	/** @var list<string> Page ids (product §4.1 / appendix). */
	public const PAGE_IDS = [
		'today', 'pulse', 'brief', 'my-accounts', 'team', 'companies',
		'company-detail', 'company-form', 'prepare-meeting', 'strategy',
		'pipeline', 'agreements', 'agreement-form', 'money', 'activities',
		'mail-triage', 'sales-inbox', 'review', 'conflicts', 'import', 'settings', 'support-us', 'access-denied',
		'workload',
	];

	public const ERR_WS_NOT_FOUND = 'WS_NOT_FOUND';
	public const ERR_WS_ARCHIVED = 'WS_ARCHIVED';
	public const ERR_WS_ROLE = 'WS_ROLE_DENIED';
	public const ERR_WS_MEMBER_LIMIT = 'WS_MEMBER_LIMIT';
	public const ERR_WS_LIMIT = 'WS_LIMIT';
	public const ERR_WIP_FULL = 'WIP_FULL';
	public const ERR_BUYER_DUP = 'BUYER_DUP';
	public const ERR_OOO_OVERLAP = 'OOO_OVERLAP';
	public const ERR_OOO_TOO_LONG = 'OOO_TOO_LONG';
	public const ERR_BULK_TOO_LARGE = 'BULK_TOO_LARGE';
	public const ERR_SKIP_TOO_FAR = 'SKIP_TOO_FAR';
	public const ERR_LOSS_CODE_REQUIRED = 'LOSS_CODE_REQUIRED';
	public const ERR_MAILBOX_LIMIT = 'MAILBOX_LIMIT';
	/** Spec §9 / FAC-08 — send-as denied (also historical MAIL_SEND_DENIED). */
	public const ERR_MAIL_SEND_FORBIDDEN = 'MAIL_SEND_FORBIDDEN';
	/** @deprecated Prefer ERR_MAIL_SEND_FORBIDDEN */
	public const ERR_MAIL_SEND_DENIED = self::ERR_MAIL_SEND_FORBIDDEN;
	public const ERR_MAIL_BODY_TOO_LARGE = 'MAIL_BODY_TOO_LARGE';
	public const ERR_MAIL_ATTACH_LIMIT = 'MAIL_ATTACH_LIMIT';
	public const ERR_MAIL_ATTACH_FORBIDDEN = 'MAIL_ATTACH_FORBIDDEN';
	/** @deprecated Prefer ERR_MAIL_ATTACH_LIMIT */
	public const ERR_ATTACHMENT_TOO_LARGE = self::ERR_MAIL_ATTACH_LIMIT;
	/** @deprecated Prefer ERR_MAIL_ATTACH_FORBIDDEN */
	public const ERR_ATTACHMENT_DENIED = self::ERR_MAIL_ATTACH_FORBIDDEN;
	public const ERR_ESCALATION_LIMIT = 'ESCALATION_LIMIT';
	public const ERR_FORECAST_AS_CASH = 'FORECAST_AS_CASH';
	public const ERR_DEAL_NOT_IN_COMPANY = 'DEAL_NOT_IN_COMPANY';
	public const ERR_ALREADY_TERMINAL = 'ALREADY_TERMINAL';
	public const ERR_SNOOZE_TOO_LONG = 'SNOOZE_TOO_LONG';
	public const ERR_REASON_REQUIRED = 'REASON_REQUIRED';
	public const ERR_REVIEW_SOURCE_DISABLED = 'REVIEW_SOURCE_DISABLED';
	public const ERR_FINGERPRINT_SUPPRESSED = 'FINGERPRINT_SUPPRESSED';
	public const ERR_EDIT_CONFLICT = 'EDIT_CONFLICT';

	private function __construct()
	{
	}
}
