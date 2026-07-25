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
	public const CONFIG_LEAD_TOKEN = 'lead_token';
	public const CONFIG_CADENCE_ENABLED = 'cadence_enabled';
	public const CONFIG_VTODO_CALENDAR_URI = 'vtodo_calendar_uri';
	public const CONFIG_MAIL_RELATED_ENABLED = 'mail_related_enabled';

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
		self::CONFIG_LEAD_TOKEN => '',
		self::CONFIG_CADENCE_ENABLED => '1',
		self::CONFIG_VTODO_CALENDAR_URI => '',
		self::CONFIG_MAIL_RELATED_ENABLED => '1',
	];

	/** Keys whose value is secret and must be redacted for non–app-admins (§10.3). */
	public const SECRET_CONFIG_KEYS = [
		self::CONFIG_ICS_TOKEN,
		self::CONFIG_LEAD_TOKEN,
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
		'azc', 'pc', 'mn', 'iv',
		'cp-small', 'cp-std', 'cp-prem',
		'cp-onb', 'cp-ws-half', 'cp-feat', 'custom',
	];

	/** @var array<string, string> product_code => required agreement kind (§4.9.2). */
	public const PRODUCT_CODE_KINDS = [
		'azc' => 'license',
		'pc' => 'license',
		'mn' => 'license',
		'iv' => 'license',
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
	public const ACTIVITY_ORIGINS = ['user', 'sequence_quoted', 'sequence_won', 'system', 'webhook'];

	/** @var list<string> */
	public const INVOICE_STATUSES = ['none', 'sent', 'paid', 'overdue'];

	/** @var list<string> */
	public const EMAIL_DIRECTIONS = ['inbound', 'outbound'];

	/** @var list<string> */
	public const EMAIL_SOURCES = ['manual', 'compose', 'mail_app'];

	/** @var list<string> Timeline event types (§4.6). */
	public const EVENT_TYPES = [
		'company.created', 'company.updated', 'company.archived', 'company.restored',
		'agreement.created', 'agreement.status', 'agreement.cancelled', 'agreement.superseded',
		'reminder.sent', 'reminder.skipped',
		'deal.stage', 'hours.logged', 'import.linked',
		'email.logged', 'email.composed',
		'invoice.paid', 'invoice.paid.ambiguous',
		'lead.webhook', 'digest.sent', 'quota.alert', 'gdpr.erased', 'note',
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

	// ── Stage follow-up sequences (§14.2) ───────────────────────────────
	public const SEQUENCE_QUOTED = 'quoted_v1';
	public const SEQUENCE_WON = 'won_v1';

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

	/** @var list<string> */
	public const NOTIFICATION_SUBJECTS = [
		self::NOTIFY_REMINDER,
		self::NOTIFY_QUOTA80,
		self::NOTIFY_DIGEST,
		self::NOTIFY_LEAD,
		self::NOTIFY_INVOICE_PAID,
		self::NOTIFY_KEY_GENERATED,
	];

	// ── Error codes (§10.2, complete v1 set) ────────────────────────────
	public const ERR_SLUG_INVALID = 'SLUG_INVALID';
	public const ERR_SLUG_TAKEN = 'SLUG_TAKEN';
	public const ERR_COMPANY_NOT_FOUND = 'COMPANY_NOT_FOUND';
	public const ERR_AGREEMENT_ILLEGAL_TRANSITION = 'AGREEMENT_ILLEGAL_TRANSITION';
	public const ERR_AGREEMENT_LIMITS_INVALID = 'AGREEMENT_LIMITS_INVALID';
	public const ERR_DEAL_TERMINAL = 'DEAL_TERMINAL';
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
	public const ERR_RATE_LIMITED = 'RATE_LIMITED';
	public const ERR_NOT_FOUND = 'NOT_FOUND';
	public const ERR_VALIDATION = 'VALIDATION';

	/** @var list<string> Page ids (§16.4). */
	public const PAGE_IDS = [
		'today', 'companies', 'company-detail', 'company-form', 'pipeline',
		'agreements', 'agreement-form', 'money', 'activities', 'import',
		'settings', 'support-us', 'access-denied',
	];

	private function __construct()
	{
	}
}
