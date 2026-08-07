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
		['name' => 'health#check', 'url' => '/health', 'verb' => 'GET'],
		['name' => 'page#today', 'url' => '/today', 'verb' => 'GET'],
		['name' => 'page#pulse', 'url' => '/pulse', 'verb' => 'GET'],
		['name' => 'page#brief', 'url' => '/brief', 'verb' => 'GET'],
		['name' => 'page#myAccounts', 'url' => '/my-accounts', 'verb' => 'GET'],
		['name' => 'page#team', 'url' => '/team', 'verb' => 'GET'],
		['name' => 'page#workload', 'url' => '/workload', 'verb' => 'GET'],
		['name' => 'page#strategy', 'url' => '/strategy', 'verb' => 'GET'],
		['name' => 'page#prepareMeeting', 'url' => '/companies/{id}/prepare-meeting', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'page#mailTriage', 'url' => '/mail-triage', 'verb' => 'GET'],
		['name' => 'page#review', 'url' => '/review', 'verb' => 'GET'],
		['name' => 'page#conflicts', 'url' => '/conflicts', 'verb' => 'GET'],
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
		['name' => 'page#appSettings', 'url' => '/app-settings', 'verb' => 'GET'],
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
		['name' => 'company_api#merge', 'url' => '/api/v1/companies/{id}/merge', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#createPcCustomer', 'url' => '/api/v1/companies/{id}/create-pc-customer', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#createTcCustomer', 'url' => '/api/v1/companies/{id}/create-tc-customer', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		// CORE-APP-PLAN §8.3 canonical write-link paths (aliases of create-*-customer).
		['name' => 'company_api#linkProjectcheck', 'url' => '/api/v1/companies/{id}/link/projectcheck', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#linkTicketcheck', 'url' => '/api/v1/companies/{id}/link/ticketcheck', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#unlinkProjectcheck', 'url' => '/api/v1/companies/{id}/unlink/projectcheck', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#unlinkTicketcheck', 'url' => '/api/v1/companies/{id}/unlink/ticketcheck', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#createFieldCustomer', 'url' => '/api/v1/companies/{id}/create-field-customer', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'identity_health_api#summary', 'url' => '/api/v1/admin/identity-health', 'verb' => 'GET'],
		['name' => 'company_api#export', 'url' => '/api/v1/companies/{id}/export', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'company_api#timeline', 'url' => '/api/v1/companies/{id}/timeline', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		// ── Command Center M5 (CUC-PLAN-CC §10.2) ────────────────────────
		['name' => 'command_api#command', 'url' => '/api/v1/companies/{id}/command', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#communication', 'url' => '/api/v1/companies/{id}/communication', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#people', 'url' => '/api/v1/companies/{id}/people', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#scheduleCallback', 'url' => '/api/v1/companies/{id}/callbacks', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#putFilesFolder', 'url' => '/api/v1/companies/{id}/files-folder', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#createFilesFolder', 'url' => '/api/v1/companies/{id}/files-folder/create', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#deleteFilesFolder', 'url' => '/api/v1/companies/{id}/files-folder', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#filesChildren', 'url' => '/api/v1/companies/{id}/files-folder/children', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#searchFolders', 'url' => '/api/v1/files/folders/search', 'verb' => 'GET'],
		['name' => 'command_api#tasks', 'url' => '/api/v1/companies/{id}/tasks', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#createTask', 'url' => '/api/v1/companies/{id}/tasks', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'command_api#platformLinks', 'url' => '/api/v1/companies/{id}/platform-links', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'today_api#command', 'url' => '/api/v1/today/command', 'verb' => 'GET'],

		// ── Persons API ─────────────────────────────────────────────────
		['name' => 'person_api#index', 'url' => '/api/v1/companies/{id}/persons', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'person_api#create', 'url' => '/api/v1/companies/{id}/persons', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'person_api#suggestContacts', 'url' => '/api/v1/contacts/suggest', 'verb' => 'GET'],
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
		['name' => 'deal_api#claim', 'url' => '/api/v1/deals/{id}/claim', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
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

		// ── Persona OS / workspaces (product bible) ───────────────────
		['name' => 'persona_api#workspaces', 'url' => '/api/v1/workspaces', 'verb' => 'GET'],
		['name' => 'persona_api#createWorkspace', 'url' => '/api/v1/workspaces', 'verb' => 'POST'],
		['name' => 'persona_api#workspaceMembers', 'url' => '/api/v1/workspaces/{workspaceId}/members', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#addWorkspaceMember', 'url' => '/api/v1/workspaces/{workspaceId}/members', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#removeWorkspaceMember', 'url' => '/api/v1/workspaces/{workspaceId}/members/{userId}', 'verb' => 'DELETE', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#importWorkspaceMembersFromGroup', 'url' => '/api/v1/workspaces/{workspaceId}/members/import-group', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#importWorkspaceMembersFromCircle', 'url' => '/api/v1/workspaces/{workspaceId}/members/import-circle', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#workspaceSettings', 'url' => '/api/v1/workspaces/{workspaceId}/settings', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#updateWorkspaceSettings', 'url' => '/api/v1/workspaces/{workspaceId}/settings', 'verb' => 'PUT', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#pulse', 'url' => '/api/v1/workspaces/{workspaceId}/pulse', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#brief', 'url' => '/api/v1/workspaces/{workspaceId}/brief', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#confirmCommitment', 'url' => '/api/v1/workspaces/{workspaceId}/brief/confirm', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#verifyCommitment', 'url' => '/api/v1/workspaces/{workspaceId}/brief/{id}/verify', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'persona_api#chase', 'url' => '/api/v1/workspaces/{workspaceId}/chase', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#myAccounts', 'url' => '/api/v1/workspaces/{workspaceId}/my-accounts', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#team', 'url' => '/api/v1/workspaces/{workspaceId}/team', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#setOoo', 'url' => '/api/v1/workspaces/{workspaceId}/ooo', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#bulkReassign', 'url' => '/api/v1/workspaces/{workspaceId}/bulk-reassign', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#mailboxes', 'url' => '/api/v1/workspaces/{workspaceId}/mailboxes', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#saveMailbox', 'url' => '/api/v1/workspaces/{workspaceId}/mailboxes', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#unmatchedMail', 'url' => '/api/v1/workspaces/{workspaceId}/mail/unmatched', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#triageMail', 'url' => '/api/v1/workspaces/{workspaceId}/mail/{id}/triage', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#listItems', 'url' => '/api/v1/workspaces/{workspaceId}/review/items', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'review_api#assign', 'url' => '/api/v1/workspaces/{workspaceId}/review/items/{id}/assign', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#snooze', 'url' => '/api/v1/workspaces/{workspaceId}/review/items/{id}/snooze', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#noRelation', 'url' => '/api/v1/workspaces/{workspaceId}/review/items/{id}/no-relation', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#followUp', 'url' => '/api/v1/workspaces/{workspaceId}/review/items/{id}/follow-up', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#applyCountry', 'url' => '/api/v1/workspaces/{workspaceId}/review/items/{id}/apply-country', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#reopen', 'url' => '/api/v1/workspaces/{workspaceId}/review/items/{id}/reopen', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#enqueue', 'url' => '/api/v1/workspaces/{workspaceId}/review/enqueue', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'review_api#listConflicts', 'url' => '/api/v1/workspaces/{workspaceId}/conflicts', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'review_api#resolveConflict', 'url' => '/api/v1/workspaces/{workspaceId}/conflicts/{id}/resolve', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#listValueMaps', 'url' => '/api/v1/workspaces/{workspaceId}/value-maps', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'review_api#applyValueMap', 'url' => '/api/v1/workspaces/{workspaceId}/value-maps', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'review_api#deleteValueMap', 'url' => '/api/v1/workspaces/{workspaceId}/value-maps/{id}', 'verb' => 'DELETE', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'review_api#updateReviewSources', 'url' => '/api/v1/workspaces/{workspaceId}/review/sources', 'verb' => 'PUT', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#listMailDrafts', 'url' => '/api/v1/workspaces/{workspaceId}/mail/drafts', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#getMailDraft', 'url' => '/api/v1/workspaces/{workspaceId}/mail/drafts/{id}', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'persona_api#sendMailDraft', 'url' => '/api/v1/workspaces/{workspaceId}/mail/drafts/{id}/send', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'persona_api#deleteMailDraft', 'url' => '/api/v1/workspaces/{workspaceId}/mail/drafts/{id}', 'verb' => 'DELETE', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'persona_api#testMailbox', 'url' => '/api/v1/workspaces/{workspaceId}/mailboxes/test', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#sendMail', 'url' => '/api/v1/workspaces/{workspaceId}/mail/send', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#escalate', 'url' => '/api/v1/workspaces/{workspaceId}/escalations', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#strategy', 'url' => '/api/v1/workspaces/{workspaceId}/strategy', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#upsertPlan', 'url' => '/api/v1/companies/{companyId}/account-plan', 'verb' => 'PUT', 'requirements' => ['companyId' => '\d+']],
		['name' => 'persona_api#accountPlan', 'url' => '/api/v1/companies/{companyId}/account-plan', 'verb' => 'GET', 'requirements' => ['companyId' => '\d+']],
		['name' => 'persona_api#addMapStep', 'url' => '/api/v1/companies/{companyId}/account-plan/map-steps', 'verb' => 'POST', 'requirements' => ['companyId' => '\d+']],
		['name' => 'persona_api#circlesRollCall', 'url' => '/api/v1/circles-roll-call', 'verb' => 'GET'],
		['name' => 'persona_api#oneOnOnePrep', 'url' => '/api/v1/workspaces/{workspaceId}/one-on-one/{reportUserId}', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#oneOnOneSave', 'url' => '/api/v1/workspaces/{workspaceId}/one-on-one/{reportUserId}', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'persona_api#moveCompany', 'url' => '/api/v1/companies/{companyId}/move-workspace', 'verb' => 'POST', 'requirements' => ['companyId' => '\d+']],
		['name' => 'persona_api#promises', 'url' => '/api/v1/companies/{companyId}/promises', 'verb' => 'GET', 'requirements' => ['companyId' => '\d+']],
		['name' => 'persona_api#createPromise', 'url' => '/api/v1/companies/{companyId}/promises', 'verb' => 'POST', 'requirements' => ['companyId' => '\d+']],
		['name' => 'persona_api#completePromise', 'url' => '/api/v1/companies/{companyId}/promises/{id}/done', 'verb' => 'POST', 'requirements' => ['companyId' => '\d+', 'id' => '\d+']],
		['name' => 'persona_api#cancelPromise', 'url' => '/api/v1/companies/{companyId}/promises/{id}/cancel', 'verb' => 'POST', 'requirements' => ['companyId' => '\d+', 'id' => '\d+']],
		['name' => 'persona_api#prepareMeeting', 'url' => '/api/v1/companies/{companyId}/prepare-meeting', 'verb' => 'GET', 'requirements' => ['companyId' => '\d+']],

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
		['name' => 'settings_api#rotateToken', 'url' => '/api/v1/settings/tokens/{kind}/rotate', 'verb' => 'POST', 'requirements' => ['kind' => 'ics|lead|order']],
		['name' => 'settings_api#productCodes', 'url' => '/api/v1/settings/product-codes', 'verb' => 'GET'],
		['name' => 'settings_api#saveProductCodes', 'url' => '/api/v1/settings/product-codes', 'verb' => 'PUT'],

		// ── License / companion seats (app-admin, COMPANION-APP §5.2) ───
		['name' => 'license#show', 'url' => '/api/v1/license', 'verb' => 'GET'],
		['name' => 'license#apply', 'url' => '/api/v1/license', 'verb' => 'POST'],
		['name' => 'license#remove', 'url' => '/api/v1/license', 'verb' => 'DELETE'],
		['name' => 'license#seats', 'url' => '/api/v1/license/seats', 'verb' => 'GET'],
		['name' => 'license#assignSeat', 'url' => '/api/v1/license/seats', 'verb' => 'POST'],
		['name' => 'license#removeSeat', 'url' => '/api/v1/license/seats/{uid}', 'verb' => 'DELETE'],

		// ── Companion mobile API v1 (Basic auth, COMPANION-APP §9) ──────
		['name' => 'mobile#bootstrap', 'url' => '/api/mobile/v1/bootstrap', 'verb' => 'GET'],
		['name' => 'mobile#workspaces', 'url' => '/api/mobile/v1/workspaces', 'verb' => 'GET'],
		['name' => 'mobile#today', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/today', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'mobile#companies', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/companies', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'mobile#companyDetail', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/companies/{id}', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'mobile#activities', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/activities', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'mobile#createActivity', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/activities', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'mobile#updateActivity', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/activities/{id}', 'verb' => 'PUT', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'mobile#doneActivity', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/activities/{id}/done', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'mobile#deals', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/deals', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'mobile#dealStage', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/deals/{id}/stage', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+', 'id' => '\d+']],
		['name' => 'mobile#agreements', 'url' => '/api/mobile/v1/workspaces/{workspaceId}/agreements', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'mobile#registerPush', 'url' => '/api/mobile/v1/push', 'verb' => 'POST'],
		['name' => 'mobile#unregisterPush', 'url' => '/api/mobile/v1/push', 'verb' => 'DELETE'],

		// ── Public token routes (no session; token validated in controller) ──
		['name' => 'feed#renewals', 'url' => '/feed/{token}/renewals.ics', 'verb' => 'GET'],
		['name' => 'feed#workspaceRenewals', 'url' => '/feed/w/{workspaceId}/{token}/renewals.ics', 'verb' => 'GET', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'settings_api#rotateWorkspaceIcs', 'url' => '/api/v1/workspaces/{workspaceId}/ics-token/rotate', 'verb' => 'POST', 'requirements' => ['workspaceId' => '\d+']],
		['name' => 'lead_webhook#receive', 'url' => '/hooks/lead/{token}', 'verb' => 'POST'],
		['name' => 'website_order_webhook#receive', 'url' => '/hooks/order/{token}', 'verb' => 'POST'],
	],
];
