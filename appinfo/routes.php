<?php

declare(strict_types=1);

/**
 * CustomerCheck route table (PSD §10.1 + §16.4 page registry).
 *
 * Ordering matters: literal segments (`/companies/new`) must be declared before
 * the parameterised sibling (`/companies/{id}`) so they are not swallowed by it.
 */
return [
	'routes' => [
		// ── Pages (§16.4) ───────────────────────────────────────────────
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#today', 'url' => '/today', 'verb' => 'GET'],
		['name' => 'page#companies', 'url' => '/companies', 'verb' => 'GET'],
		['name' => 'page#companyNew', 'url' => '/companies/new', 'verb' => 'GET'],
		['name' => 'page#companyEdit', 'url' => '/companies/{id}/edit', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#companyDetail', 'url' => '/companies/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#pipeline', 'url' => '/pipeline', 'verb' => 'GET'],
		['name' => 'page#agreements', 'url' => '/agreements', 'verb' => 'GET'],
		['name' => 'page#agreementNew', 'url' => '/agreements/new', 'verb' => 'GET'],
		['name' => 'page#agreementEdit', 'url' => '/agreements/{id}/edit', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#money', 'url' => '/money', 'verb' => 'GET'],
		['name' => 'page#activities', 'url' => '/activities', 'verb' => 'GET'],
		['name' => 'page#import', 'url' => '/import', 'verb' => 'GET'],
		['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],
		['name' => 'page#supportUs', 'url' => '/support-us', 'verb' => 'GET'],

		// ── Companies API ───────────────────────────────────────────────
		['name' => 'company_api#index', 'url' => '/api/v1/companies', 'verb' => 'GET'],
		['name' => 'company_api#create', 'url' => '/api/v1/companies', 'verb' => 'POST'],
		['name' => 'company_api#suggestSlug', 'url' => '/api/v1/companies/suggest-slug', 'verb' => 'POST'],
		['name' => 'company_api#show', 'url' => '/api/v1/companies/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#update', 'url' => '/api/v1/companies/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#destroy', 'url' => '/api/v1/companies/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#archive', 'url' => '/api/v1/companies/{id}/archive', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#restore', 'url' => '/api/v1/companies/{id}/restore', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#export', 'url' => '/api/v1/companies/{id}/export', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#timeline', 'url' => '/api/v1/companies/{id}/timeline', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// ── Persons API ─────────────────────────────────────────────────
		['name' => 'person_api#index', 'url' => '/api/v1/companies/{id}/persons', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'person_api#create', 'url' => '/api/v1/companies/{id}/persons', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'person_api#update', 'url' => '/api/v1/persons/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'person_api#destroy', 'url' => '/api/v1/persons/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// ── Agreements API ──────────────────────────────────────────────
		['name' => 'agreement_api#index', 'url' => '/api/v1/agreements', 'verb' => 'GET'],
		['name' => 'agreement_api#create', 'url' => '/api/v1/agreements', 'verb' => 'POST'],
		['name' => 'agreement_api#update', 'url' => '/api/v1/agreements/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'agreement_api#cancel', 'url' => '/api/v1/agreements/{id}/cancel', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'agreement_api#supersede', 'url' => '/api/v1/agreements/{id}/supersede', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'agreement_api#deliveryPack', 'url' => '/api/v1/agreements/{id}/delivery-pack', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'agreement_api#partnerMail', 'url' => '/api/v1/agreements/{id}/partner-mail', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Hours API ───────────────────────────────────────────────────
		['name' => 'hours_api#index', 'url' => '/api/v1/agreements/{id}/hours', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'hours_api#create', 'url' => '/api/v1/agreements/{id}/hours', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'hours_api#update', 'url' => '/api/v1/hours/{entryId}', 'verb' => 'PUT', 'requirements' => ['entryId' => '\d+']],

		// ── Deals API ───────────────────────────────────────────────────
		['name' => 'deal_api#index', 'url' => '/api/v1/deals', 'verb' => 'GET'],
		['name' => 'deal_api#create', 'url' => '/api/v1/deals', 'verb' => 'POST'],
		['name' => 'deal_api#update', 'url' => '/api/v1/deals/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'deal_api#stage', 'url' => '/api/v1/deals/{id}/stage', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Activities API ──────────────────────────────────────────────
		['name' => 'activity_api#index', 'url' => '/api/v1/activities', 'verb' => 'GET'],
		['name' => 'activity_api#create', 'url' => '/api/v1/activities', 'verb' => 'POST'],
		['name' => 'activity_api#update', 'url' => '/api/v1/activities/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'activity_api#done', 'url' => '/api/v1/activities/{id}/done', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'activity_api#pushTodo', 'url' => '/api/v1/activities/{id}/push-todo', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'activity_api#dismissFollowUps', 'url' => '/api/v1/deals/{dealId}/dismiss-followups', 'verb' => 'POST', 'requirements' => ['dealId' => '\d+']],

		// ── Emails API (§14.8) ──────────────────────────────────────────
		['name' => 'email_api#index', 'url' => '/api/v1/companies/{id}/emails', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'email_api#create', 'url' => '/api/v1/companies/{id}/emails', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'email_api#composeTemplate', 'url' => '/api/v1/companies/{id}/compose-template', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ── Aggregates ──────────────────────────────────────────────────
		['name' => 'today_api#index', 'url' => '/api/v1/today', 'verb' => 'GET'],
		['name' => 'money_api#index', 'url' => '/api/v1/money', 'verb' => 'GET'],
		['name' => 'summary_api#index', 'url' => '/api/v1/summary', 'verb' => 'GET'],

		// ── Import (§8.6) ───────────────────────────────────────────────
		['name' => 'import_api#scan', 'url' => '/api/v1/import/scan', 'verb' => 'POST'],
		['name' => 'import_api#apply', 'url' => '/api/v1/import/apply', 'verb' => 'POST'],

		// ── Settings ────────────────────────────────────────────────────
		['name' => 'settings_api#show', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'settings_api#update', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
		['name' => 'settings_api#updateMe', 'url' => '/api/v1/settings/me', 'verb' => 'PUT'],
		['name' => 'settings_api#searchUsers', 'url' => '/api/v1/settings/users', 'verb' => 'GET'],
		['name' => 'settings_api#rotateToken', 'url' => '/api/v1/settings/tokens/{kind}/rotate', 'verb' => 'POST', 'requirements' => ['kind' => 'ics|lead']],

		// ── Public token routes (no session; token validated in controller) ──
		['name' => 'feed#renewals', 'url' => '/feed/{token}/renewals.ics', 'verb' => 'GET'],
		['name' => 'lead_webhook#receive', 'url' => '/hooks/lead/{token}', 'verb' => 'POST'],
	],
];
