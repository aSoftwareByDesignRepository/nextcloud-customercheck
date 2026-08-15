<?php

declare(strict_types=1);

/**
 * Focused mutation gauntlet for CUC-PERSONAL-MAIL-1 ACL / sanitize / send-as.
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$phpunit = $root . '/vendor/bin/phpunit';
$cases = [
	[
		'file' => $root . '/lib/Service/MailAclLogic.php',
		'find' => "if (\$visibility === self::VISIBILITY_PRIVATE) {\n\t\t\treturn \$owner !== '' && hash_equals(\$owner, \$actorUserId);\n\t\t}",
		'replace' => "if (\$visibility === self::VISIBILITY_PRIVATE) {\n\t\t\treturn true;\n\t\t}",
		'test' => 'MailAclLogicTest',
		'label' => 'private mail readable by everyone',
	],
	[
		'file' => $root . '/lib/Service/MailAclLogic.php',
		'find' => "if (\$kind === self::KIND_PERSONAL && \$folderShareWithWorkspace) {\n\t\t\treturn self::VISIBILITY_SHARED;\n\t\t}\n\n\t\treturn self::VISIBILITY_PRIVATE;",
		'replace' => "if (\$kind === self::KIND_PERSONAL && \$folderShareWithWorkspace) {\n\t\t\treturn self::VISIBILITY_SHARED;\n\t\t}\n\n\t\treturn self::VISIBILITY_SHARED;",
		'test' => 'MailAclLogicTest',
		'label' => 'personal folders default to shared visibility',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "if (\$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\t\$owner = trim((string)(\$box['owner_user_id'] ?? ''));\n\t\t\tif (\$owner === '' || !hash_equals(\$owner, \$actor)) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_MAIL_SEND_DENIED,\n\t\t\t\t\t'mailboxId',\n\t\t\t\t\t\$this->l10n->t('You can only send from your own personal mailbox.'),\n\t\t\t\t);\n\t\t\t}\n\t\t\t\$workspaceId = (int)(\$box['workspace_id'] ?? 0);\n\t\t\tif (\$workspaceId <= 0) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_MAIL_SEND_DENIED,\n\t\t\t\t\t'mailboxId',\n\t\t\t\t\t\$this->l10n->t('You can only send from your own personal mailbox.'),\n\t\t\t\t);\n\t\t\t}\n\t\t\t\$settings = \$this->workspaces->getSettings(\$workspaceId);\n\t\t\tif (empty(\$settings['personalMailEnabled'])) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_MAIL_SEND_DENIED,\n\t\t\t\t\t'personalMail',\n\t\t\t\t\t\$this->l10n->t('Personal email sync is not enabled for this workspace. Ask a manager to turn it on under Team & mailbox settings.'),\n\t\t\t\t);\n\t\t\t}\n\n\t\t\treturn;\n\t\t}",
		'replace' => "if (\$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\treturn;\n\t\t}",
		'test' => 'MailSendServicePersonalAclTest',
		'label' => 'personal send skips owner check',
	],
	[
		'file' => $root . '/lib/Service/HtmlSanitizeService.php',
		'find' => "if (\$href === '' || preg_match('#^\\s*(javascript|data|vbscript)\\s*:#i', \$href)) {\n\t\t\treturn null;\n\t\t}\n\t\t// Compose links: http(s)/mailto only (normative); relative /# kept for legacy inbound.\n\t\tif (!preg_match('~^(https?:|mailto:|/|#)~i', \$href)) {\n\t\t\treturn null;\n\t\t}",
		'replace' => "if (\$href === '') {\n\t\t\treturn null;\n\t\t}",
		'test' => 'HtmlSanitizeServiceTest',
		'label' => 'sanitizer drops all URL scheme defenses',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "// AS-12: never promote private personal mail into Assign / unmatched UI.\n\t\t// Legacy rows without visibility column value are treated as shared (Team Inbox era).\n\t\t\$qb->andWhere(\$qb->expr()->orX(\n\t\t\t\$qb->expr()->eq('m.visibility', \$qb->createNamedParameter(MailAclLogic::VISIBILITY_SHARED)),\n\t\t\t\$qb->expr()->isNull('m.visibility'),\n\t\t\t\$qb->expr()->eq('m.visibility', \$qb->createNamedParameter('')),\n\t\t));",
		'replace' => "// MUTANT: promote all open triage including private\n\t\t;",
		'test' => 'PersonalMailReviewLeakContractTest',
		'label' => 'unmatchedPage promotes private personal mail',
	],
	[
		'file' => $root . '/lib/Service/MailboxAuthService.php',
		'find' => "\$raw = 'user=' . \$userEmail . \"\\x01auth=Bearer \" . \$accessToken . \"\\x01\\x01\";",
		'replace' => "\$raw = 'user=' . \$userEmail . \$accessToken;",
		'test' => 'MailboxAuthServiceTest',
		'label' => 'XOAUTH2 SASL missing separators',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (empty(\$settings['personalMailEnabled'])) {\n\t\t\t\t\$this->logger->info('personal_mail.sync_skip', [\n\t\t\t\t\t'event' => 'personal_mail.sync_skip',\n\t\t\t\t\t'reason' => 'disabled',\n\t\t\t\t\t'mailboxId' => \$mailboxId,\n\t\t\t\t\t'workspaceId' => \$workspaceId,\n\t\t\t\t]);\n\t\t\t\treturn ['ingested' => 0, 'status' => 'disabled'];\n\t\t\t}",
		'replace' => "if (false) {\n\t\t\t\treturn ['ingested' => 0, 'status' => 'disabled'];\n\t\t\t}",
		'test' => 'PersonalMailFacNnContractTest',
		'label' => 'NN-06 sync gate removed',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "\$companyId = isset(\$msg['companyId']) ? (int)\$msg['companyId'] : 0;\n\t\tif (!MailAclLogic::canReadMessage(\n\t\t\t\$actorUid,\n\t\t\t[\n\t\t\t\t'visibility' => (string)(\$msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),\n\t\t\t\t'ownerUserId' => \$msg['ownerUserId'] ?? null,\n\t\t\t\t'companyId' => \$companyId > 0 ? \$companyId : null,\n\t\t\t\t'mailboxKind' => (string)(\$msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM),\n\t\t\t],\n\t\t\t\$canReadCompany,\n\t\t\t\$canReadOrTriageMail,\n\t\t)) {",
		'replace' => "\$companyId = isset(\$msg['companyId']) ? (int)\$msg['companyId'] : 0;\n\t\t\$canReadCompany = \$companyId > 0;\n\t\tif (!MailAclLogic::canReadMessage(\n\t\t\t\$actorUid,\n\t\t\t[\n\t\t\t\t'visibility' => (string)(\$msg['visibility'] ?? MailAclLogic::VISIBILITY_SHARED),\n\t\t\t\t'ownerUserId' => \$msg['ownerUserId'] ?? null,\n\t\t\t\t'companyId' => \$companyId > 0 ? \$companyId : null,\n\t\t\t\t'mailboxKind' => (string)(\$msg['mailboxKind'] ?? MailAclLogic::KIND_TEAM),\n\t\t\t],\n\t\t\t\$canReadCompany,\n\t\t\t\$canReadOrTriageMail,\n\t\t)) {",
		'test' => 'PersonalMailFacNnContractTest',
		'label' => 'FAC-04 invent company.read from linkage',
	],
	[
		'file' => $root . '/lib/Service/MailboxAuthService.php',
		'find' => "if (\$refresh === '') {\n\t\t\tif (\$expired || \$access === '') {\n\t\t\t\t\$this->bumpOAuthAuthFailure(\$id, (int)(\$row['auth_fail_count'] ?? 0));\n\t\t\t}\n\n\t\t\treturn '';\n\t\t}",
		'replace' => "if (\$refresh === '') {\n\t\t\treturn \$access;\n\t\t}",
		'test' => 'MailboxAuthServiceTest',
		'label' => 'OAuth expired token returns stale bearer',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailOAuthService.php',
		'find' => "\$this->appConfig->setValueString(Application::APP_ID, \$key, \$this->crypto->encrypt(\$plain));",
		'replace' => "\$this->appConfig->setValueString(Application::APP_ID, \$key, \$plain);",
		'test' => 'PersonalMailOAuthSecretEncryptTest',
		'label' => 'OAuth client secret stored plaintext',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "['shared_mail', 'personal_mail_shared_unmatched']",
		'replace' => "['shared_mail']",
		'test' => 'PersonalMailFacCloseoutTest::testReviewPurgeIncludesPersonalUnmatchedNeedle',
		'label' => 'Review purge drops personal unmatched kind',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "\$forwardHints = [];\n\t\tif (\$meta['kind'] !== MailAclLogic::KIND_PERSONAL) {\n\t\t\t// Forward hints are Team Inbox / legacy only — never auto-file personal mail via Fwd: parsers.\n\t\t\t\$forwardHints = MailMatchLogic::normalizeAddressList(\n\t\t\t\tisset(\$msg['originalFromHints']) && is_array(\$msg['originalFromHints'])\n\t\t\t\t\t? array_map('strval', \$msg['originalFromHints'])\n\t\t\t\t\t: [],\n\t\t\t);\n\t\t}",
		'replace' => "\$forwardHints = MailMatchLogic::normalizeAddressList(\n\t\t\tisset(\$msg['originalFromHints']) && is_array(\$msg['originalFromHints'])\n\t\t\t\t? array_map('strval', \$msg['originalFromHints'])\n\t\t\t\t: [],\n\t\t);",
		'test' => 'PersonalMailFacCloseoutTest::testPersonalIngestIgnoresForwardHints',
		'label' => 'personal ingest uses forward hints',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "\$msg['bodyText'] = \$plainPart;",
		'replace' => "// mutant: skip live-probe bodyText assignment",
		'test' => 'PersonalMailMh05BodyPersistTest::testSyncPathAssignsBodyTextNeedleAndFetchCap',
		'label' => 'live-probe sync never assigns bodyText',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "\$msg['bodyHtml'] = \$htmlPart;",
		'replace' => "// mutant: skip live-probe bodyHtml",
		'test' => 'PersonalMailMh05BodyPersistTest::testSyncPathAssignsBodyTextNeedleAndFetchCap',
		'label' => 'live-probe sync never assigns bodyHtml',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "if (\$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\t\t\$visibility = \$folderMeta['shareWithWorkspace']\n\t\t\t\t\t? MailAclLogic::VISIBILITY_SHARED\n\t\t\t\t\t: MailAclLogic::VISIBILITY_PRIVATE;\n\t\t\t} else {\n\t\t\t\t\$visibility = MailAclLogic::VISIBILITY_SHARED;\n\t\t\t}",
		'replace' => "if (\$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\t\t\$visibility = MailAclLogic::VISIBILITY_PRIVATE;\n\t\t\t} else {\n\t\t\t\t\$visibility = MailAclLogic::VISIBILITY_SHARED;\n\t\t\t}",
		'test' => 'MailSendOutboundVisibilityTest',
		'label' => 'personal outbound always private ignoring share',
	],
	[
		'file' => $root . '/lib/Support/SharedMailboxSmtpClient.php',
		'find' => "\$headers[] = 'Message-ID: ' . \$messageId;",
		'replace' => "// mutant: omit Message-ID header",
		'test' => 'SharedMailboxSmtpClientTest::testBuildDataPayloadIncludesMessageId',
		'label' => 'SMTP payload omits Message-ID',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (\$actor === '') {\n\t\t\t// Argus SF-03: detectable personal mutate from Assign / null actor.\n\t\t\t\$this->logger->warning('personal_mail.mutate_denied', [\n\t\t\t\t'event' => 'personal_mail.mutate_denied',\n\t\t\t\t'reason' => 'assign_or_empty_actor',\n\t\t\t\t'workspaceId' => \$workspaceId,\n\t\t\t\t'mailboxId' => \$mailboxId,\n\t\t\t]);\n\t\t\tthrow new ValidationException(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\$this->l10n->t('Personal mailbox messages cannot be deleted or junked from Assign. Remove the Assign item only, or ask the owner.'),\n\t\t\t);\n\t\t}",
		'replace' => "if (false && \$actor === '') {\n\t\t\tthrow new ValidationException(Constants::ERR_VALIDATION, 'x');\n\t\t}",
		'test' => 'PersonalMailHardeningContractTest::testDeleteIndexedMessageRefusesPersonalMailbox',
		'label' => 'personal IMAP mutate gate removed',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "if (\$kind === MailAclLogic::KIND_PERSONAL && !\$allowPersonal) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'id',\n\t\t\t\t\$this->l10n->t('Personal mailboxes can only be disconnected by their owner under My email.'),\n\t\t\t);\n\t\t}",
		'replace' => "if (false) {\n\t\t\tthrow ValidationException::field(Constants::ERR_VALIDATION, 'id', 'x');\n\t\t}",
		'test' => 'PersonalMailHardeningContractTest::testTeamMailboxDeleteRefusesPersonalWithoutAllowFlag',
		'label' => 'team delete allows wiping personal',
	],
	[
		'file' => $root . '/lib/Service/MailAclLogic.php',
		'find' => "if (\$mailboxKind === self::KIND_PERSONAL) {\n\t\t\treturn \$canReadOrTriageMail;\n\t\t}\n\n\t\treturn true;",
		'replace' => "if (\$mailboxKind === self::KIND_PERSONAL) {\n\t\t\treturn true;\n\t\t}\n\n\t\treturn true;",
		'test' => 'MailAclLogicTest::testSharedUnmatchedPersonalRequiresTriage',
		'label' => 'AS-12 personal unmatched always readable',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (\$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\t\$settings = \$this->workspaces->getSettings(\$workspaceId);\n\t\t\tif (empty(\$settings['personalMailEnabled'])) {\n\t\t\t\t\$this->logger->info('personal_mail.sync_skip', [\n\t\t\t\t\t'event' => 'personal_mail.sync_skip',\n\t\t\t\t\t'reason' => 'disabled',\n\t\t\t\t\t'mailboxId' => \$mailboxId,\n\t\t\t\t\t'workspaceId' => \$workspaceId,\n\t\t\t\t]);\n\t\t\t\treturn ['ingested' => 0, 'status' => 'disabled'];\n\t\t\t}\n\t\t}",
		'replace' => "if (false && \$kind === MailAclLogic::KIND_PERSONAL) {\n\t\t\treturn ['ingested' => 0, 'status' => 'disabled'];\n\t\t}",
		'test' => 'PersonalMailHardeningContractTest::testSyncWorkspaceIsTeamOnlyAndPeerSyncRejectsPersonal',
		'label' => 'syncWorkspace crawls personal again',
	],
	[
		'file' => $root . '/lib/Controller/MobileMailController.php',
		'find' => "if (\$this->authorizationBasicAuthenticatesCurrentUser()) {\n\t\t\treturn;\n\t\t}",
		'replace' => "if (preg_match('/^(Basic|Bearer)\\s+/i', (string)\$this->request->getHeader('Authorization'))) {\n\t\t\treturn;\n\t\t}",
		'test' => 'ArgusPersonalMailSecurityContractTest::testMobileMailControllerVerifiesBasicAuthNotPresenceOnly',
		'label' => 'MobileMail CSRF presence-only Basic',
	],
	[
		'file' => $root . '/lib/Service/MailDraftService.php',
		'find' => "return MailAclLogic::actorMayAccessFailedDraft(\n\t\t\t\$actor,\n\t\t\tisset(\$row['mailbox_kind']) ? (string)\$row['mailbox_kind'] : null,\n\t\t\tisset(\$row['created_by']) ? (string)\$row['created_by'] : null,\n\t\t\tisset(\$row['mailbox_owner']) ? (string)\$row['mailbox_owner'] : null,\n\t\t);",
		'replace' => "return true;",
		'test' => 'MailDraftServiceTest::testListFailedHidesPeerPersonalDrafts',
		'label' => 'personal failed drafts visible to peers',
	],
	[
		'file' => $root . '/lib/Controller/PersonaApiController.php',
		'find' => "if (!\$canTriage) {\n\t\t\t\t\$needsTriage = \$this->l10n->t('Needs triage');\n\t\t\t\tforeach (\$items as &\$item) {\n\t\t\t\t\t\$item = MailAclLogic::redactPersonalUnmatchedListItem(\$item, \$needsTriage);\n\t\t\t\t}\n\t\t\t\tunset(\$item);\n\t\t\t}",
		'replace' => "// mutant: no personal unmatched redaction",
		'test' => 'ArgusPersonalMailSecurityContractTest::testUnmatchedMailRedactsPersonalPreviewWithoutTriage',
		'label' => 'unmatched personal preview not redacted',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailOAuthService.php',
		'find' => "if (\$sessionUserId === '' || !hash_equals(\$userId, \$sessionUserId)) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'state',\n\t\t\t\t\$this->l10n->t('OAuth session does not match the signed-in user. Start connect again.'),\n\t\t\t);\n\t\t}",
		'replace' => "// mutant: skip session bind",
		'test' => 'ArgusPersonalMailSecurityContractTest::testOAuthCompleteAuthorizeBindsSessionUser',
		'label' => 'OAuth callback skips session bind',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "if (empty(\$settings['personalMailEnabled'])) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_MAIL_SEND_DENIED,\n\t\t\t\t\t'personalMail',\n\t\t\t\t\t\$this->l10n->t('Personal email sync is not enabled for this workspace. Ask a manager to turn it on under Team & mailbox settings.'),\n\t\t\t\t);\n\t\t\t}",
		'replace' => "// mutant: ignore personalMailEnabled on send",
		'test' => 'MailSendServicePersonalAclTest::testPersonalSendDeniedWhenWorkspacePolicyOff',
		'label' => 'send ignores personalMailEnabled',
	],
	[
		'file' => $root . '/lib/Service/MailAclLogic.php',
		'find' => "\$item['subject'] = \$needsTriageLabel;\n\t\t\$item['from'] = '';\n\t\t\$item['snippet'] = '';\n\t\t\$item['preview'] = '';",
		'replace' => "// mutant: skip metadata redaction\n\t\t;",
		'test' => 'MailAclLogicTest::testRedactPersonalUnmatchedListItem',
		'label' => 'personal unmatched metadata not redacted',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailService.php',
		'find' => "if (!\$this->rateLimit->allow(\n\t\t\t'pm_imap_connect:' . \$userId,\n\t\t\tConstants::PERSONAL_MAIL_IMAP_CONNECT_USER_LIMIT,\n\t\t\tConstants::PERSONAL_MAIL_IMAP_CONNECT_USER_WINDOW,\n\t\t\ttrue,\n\t\t)) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_RATE_LIMITED,\n\t\t\t\t'rate',\n\t\t\t\t\$this->l10n->t('Too many connect attempts. Try again later.'),\n\t\t\t);\n\t\t}",
		'replace' => "// mutant: no IMAP connect rate limit",
		'test' => 'ArgusPersonalMailSecurityContractTest::testImapConnectIsRateLimited',
		'label' => 'IMAP connect rate limit removed',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "|| !MailAclLogic::actorMayAccessFailedDraft(\n\t\t\t\t\$actor,\n\t\t\t\tisset(\$existing['mailbox_kind']) ? (string)\$existing['mailbox_kind'] : null,\n\t\t\t\tisset(\$existing['created_by']) ? (string)\$existing['created_by'] : null,\n\t\t\t\tisset(\$existing['mailbox_owner']) ? (string)\$existing['mailbox_owner'] : null,\n\t\t\t)",
		'replace' => "|| false",
		'test' => 'MailSendServiceReuseDraftTest::testReuseDraftRejectsPeerPersonalDraft',
		'label' => 'reuseDraftId skips personal draft ACL',
	],
	[
		'file' => $root . '/lib/Service/MailAclLogic.php',
		'find' => "if (\$kind === self::KIND_TEAM) {\n\t\t\treturn true;\n\t\t}\n\t\t// personal OR unknown/orphan — fail closed\n\t\tif (\$createdBy !== '' && hash_equals(\$createdBy, \$actor)) {\n\t\t\treturn true;\n\t\t}\n\t\tif (\$owner !== '' && hash_equals(\$owner, \$actor)) {\n\t\t\treturn true;\n\t\t}\n\n\t\treturn false;",
		'replace' => "return true;",
		'test' => 'MailAclLogicTest::testActorMayAccessFailedDraftFailClosed',
		'label' => 'orphan drafts world-readable again',
	],
	[
		'file' => $root . '/lib/Constants.php',
		'find' => "public const MAIL_SYNC_MAX_MESSAGES = self::MAILBOX_SYNC_CAP;",
		'replace' => "// mutant: drop MAIL_SYNC_MAX_MESSAGES alias",
		'test' => 'ZeusPersonalMailArchitectureContractTest::testMailSyncMaxMessagesAliasExists',
		'label' => 'MAIL_SYNC_MAX_MESSAGES removed',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "if (!\$acked && !\$outbound) {\n\t\t\t// In-flight claim under our exclusive send lock ⇒ prior worker died before SMTP ACK.\n\t\t\t// Release so this attempt can re-send (does not fake success).\n\t\t\t\$this->releaseFailedDraftClaim((int)\$row['id']);\n\n\t\t\treturn null;\n\t\t}",
		'replace' => "// mutant: treat unacked claim as success\n\t\t;",
		'test' => 'ZeusPersonalMailArchitectureContractTest::testFindByIdempotencyRequiresSmtpAckOrOutbound',
		'label' => 'hollow idempotency replay restored',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailOAuthService.php',
		'find' => "if (count(\$existing) >= Constants::PERSONAL_MAIL_MAX_PER_USER) {\n\t\t\t\tthrow ValidationException::field(\n\t\t\t\t\tConstants::ERR_MAILBOX_LIMIT,\n\t\t\t\t\t'mailbox',\n\t\t\t\t\t\$this->l10n->t('You already connected the maximum number of personal mailboxes for this workspace.'),\n\t\t\t\t);\n\t\t\t}",
		'replace' => "// mutant: skip max-per-user under lock",
		'test' => 'ZeusPersonalMailArchitectureContractTest::testOAuthMaxPerUserCheckedUnderEmailLock',
		'label' => 'OAuth max-per-user outside lock',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailOAuthService.php',
		'find' => "if (\$healId > 0) {\n\t\t\t\treturn \$this->healExistingOAuthMailbox(\n\t\t\t\t\t\$workspaceId,\n\t\t\t\t\t\$userId,\n\t\t\t\t\t\$healId,\n\t\t\t\t\t\$provider,\n\t\t\t\t\t\$email,\n\t\t\t\t\t\$hosts,\n\t\t\t\t\t\$now,\n\t\t\t\t\t\$accessEnc,\n\t\t\t\t\t\$refreshEnc,\n\t\t\t\t\t\$expiresAt,\n\t\t\t\t);\n\t\t\t}",
		'replace' => "// mutant: never heal OAuth quarantine in place",
		'test' => 'AristotelesPersonalMailOAuthHealContractTest::testHealPathPresent',
		'label' => 'OAuth heal path removed',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailService.php',
		'find' => "if (\$id <= 0 && \$emailAddress !== '') {\n\t\t\tforeach (\$existing as \$box) {\n\t\t\t\t\$boxEmail = strtolower(trim((string)(\$box['emailAddress'] ?? '')));\n\t\t\t\tif (\$boxEmail === \$emailAddress) {\n\t\t\t\t\t\$id = (int)(\$box['id'] ?? 0);\n\t\t\t\t\tbreak;\n\t\t\t\t}\n\t\t\t}\n\t\t}",
		'replace' => "// mutant: never resolve IMAP heal id from same email",
		'test' => 'AristotelesPersonalMailImapHealContractTest::testSameEmailResolvesHealIdWithoutClientId',
		'label' => 'IMAP same-email heal removed',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailService.php',
		'find' => "if (\$stored !== \$emailAddress) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'emailAddress',\n\t\t\t\t\$this->l10n->t('To connect a different email address, disconnect this mailbox first. That removes synced messages from the workspace.'),\n\t\t\t);\n\t\t}",
		'replace' => "// mutant: allow email rebind on heal id",
		'test' => 'AristotelesPersonalMailEmailRebindGuardContractTest::testConnectImapGuardsSameEmailOnHeal',
		'label' => 'email rebind guard removed',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "->set('auth_fail_count', \$qb->createFunction('auth_fail_count + 1'))",
		'replace' => "->set('auth_fail_count', \$qb->createNamedParameter(\$currentFails + 1))",
		'test' => 'AristotelesPersonalMailEmailRebindGuardContractTest::testAuthFailCountIncrementsAtomically',
		'label' => 'auth_fail_count not atomic',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "if (\$this->mailboxes->isSyncDialCooldownActive(\$row, \$now)) {\n\t\t\t\t\t\$cooldownSkipped++;\n\t\t\t\t\tcontinue;\n\t\t\t\t}",
		'replace' => "// mutant: ignore sync dial cooldown",
		'test' => 'AristotelesPersonalMailHardeningContractTest::testSyncAllRespectsDialCooldown',
		'label' => 'sync dial cooldown skipped',
	],
	[
		'file' => $root . '/lib/Service/MailDraftService.php',
		'find' => "->andWhere(\$hollowQb->expr()->eq('smtp_acked', \$hollowQb->createNamedParameter(0)))",
		'replace' => "// mutant: purge acked ledger too\n\t\t\t\t->andWhere(\$hollowQb->expr()->gte('smtp_acked', \$hollowQb->createNamedParameter(0)))",
		'test' => 'AristotelesPersonalMailHardeningContractTest::testHollowClaimPurgeRequiresSmtpAckedZero',
		'label' => 'hollow purge deletes success ledger',
	],
	[
		'file' => $root . '/lib/Migration/Version1018Date20260815141000.php',
		'find' => "\$t->addUniqueIndex(['mailbox_id', 'message_id'], 'crm_mm_mid_uq');",
		'replace' => "// mutant: no message_id unique",
		'test' => 'AristotelesPersonalMailHardeningContractTest::testMessageIdUniqueMigrationPresent',
		'label' => 'message_id unique index removed',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "if (\$kind === MailAclLogic::KIND_PERSONAL && \$owner !== '') {\n\t\t\t\$this->notifications->notifyUser(\n\t\t\t\t\$owner,\n\t\t\t\tConstants::NOTIFY_DIGEST,\n\t\t\t\t'mailbox_error',\n\t\t\t\t(string)\$mailboxId,\n\t\t\t\t\$params,\n\t\t\t);\n\t\t\treturn;\n\t\t}",
		'replace' => "// mutant: always workspace fan-out for personal auth errors",
		'test' => 'MailboxServiceAuthQuarantineNotifyTest::testThirdFailureNotifiesPersonalOwnerOnly',
		'label' => 'personal auth error fans out to workspace',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailOAuthService.php',
		'find' => "if (\$expectedWorkspaceId !== null && \$expectedWorkspaceId > 0 && \$expectedWorkspaceId !== \$workspaceId) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t'workspaceId',\n\t\t\t\t\$this->l10n->t('OAuth session does not match this workspace. Start connect again.'),\n\t\t\t);\n\t\t}",
		'replace' => "// mutant: skip workspace bind on OAuth complete",
		'test' => 'AristotelesPersonalMailSpecCloseoutContractTest::testMobileOAuthCompleteRouteAndWorkspaceBind',
		'label' => 'OAuth complete skips workspace bind',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailOAuthService.php',
		'find' => "return (\$payload['client'] ?? '') === 'native';",
		'replace' => "return false;",
		'test' => 'PersonalMailOAuthNativeClientTest::testIsNativeOAuthStateTrueWhenFlagSet',
		'label' => 'native OAuth state always false',
	],
	[
		'file' => $root . '/lib/Controller/PersonaApiController.php',
		'find' => "if (\$this->personalMailOAuth->isNativeOAuthState(\$state)) {\n\t\t\t\t\$msg = \$this->l10n->t('Sign-in complete. You can return to the CustomerCheck app.');",
		'replace' => "if (false) {\n\t\t\t\t\$msg = \$this->l10n->t('Sign-in complete. You can return to the CustomerCheck app.');",
		'test' => 'AristotelesPersonalMailSpecCloseoutContractTest::testMobileOAuthCompleteRouteAndWorkspaceBind',
		'label' => 'web callback always exchanges native state',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => "// Only auth failures quarantine (US-002). Network/cert/unknown → dial backoff, not reconnect spam.\n\t\t\tif (\$errReason === 'auth') {\n\t\t\t\t\$status = \$this->mailboxes->recordAuthFailure(\$mailboxId, (int)\$row['auth_fail_count']);\n\t\t\t\treturn ['ingested' => 0, 'status' => \$status === 'error' ? 'error' : 'auth'];\n\t\t\t}",
		'replace' => "// mutant: treat all imap_open failures as auth\n\t\t\t\$status = \$this->mailboxes->recordAuthFailure(\$mailboxId, (int)\$row['auth_fail_count']);\n\t\t\treturn ['ingested' => 0, 'status' => \$status === 'error' ? 'error' : 'auth'];",
		'test' => 'AristotelesPersonalMailResidualHardeningContractTest::testPhpImapOpenOnlyQuarantinesOnAuthReason',
		'label' => 'php-imap open always quarantines',
	],
	[
		'file' => $root . '/lib/Service/MailboxAuthService.php',
		'find' => "if (\$fresh['status'] === 'auth') {\n\t\t\t\$this->bumpOAuthAuthFailure(\$id, (int)(\$row['auth_fail_count'] ?? 0));\n\t\t}",
		'replace' => "if (\$fresh['status'] === 'auth' || \$fresh['status'] === 'network') {\n\t\t\t\$this->bumpOAuthAuthFailure(\$id, (int)(\$row['auth_fail_count'] ?? 0));\n\t\t}",
		'test' => 'AristotelesPersonalMailResidualHardeningContractTest::testOAuthRefreshNetworkDoesNotBumpAuth',
		'label' => 'OAuth network refresh bumps auth',
	],
	[
		'file' => $root . '/lib/Service/MailSendService.php',
		'find' => "if (\$oauthResolveFailed) {\n\t\t\treturn \$this->l10n->t('Mail provider sign-in expired. Reconnect your mailbox under My email.');\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn \$this->l10n->t('Mail provider sign-in expired. Reconnect your mailbox under My email.');\n\t\t}",
		'test' => 'MailSendServiceCredentialCopyTest::testOAuthFailureUsesMyEmailReconnectCopy',
		'label' => 'OAuth send uses team mailbox copy',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "if (connectFormEl && !connectFormEl.hidden && imapDetailsEl && oauthConfiguredCount === 0) {\n        imapDetailsEl.open = true;\n        const serversEl = document.getElementById('crm-personal-mail-servers');\n        if (serversEl) serversEl.open = true;\n      }",
		'replace' => "// mutant: never auto-open IMAP when OAuth missing",
		'test' => 'AristotelesPersonalMailBachusP1CloseoutContractTest::testImapDetailsOpenWhenNoOauthProviders',
		'label' => 'IMAP details stay closed without OAuth',
	],
	[
		'file' => $root . '/js/common/components.js',
		'find' => "if (requireTypedConfirm && typedField\n          && typedField.value.trim().toUpperCase() !== String(typedConfirmPhrase).trim().toUpperCase()) {\n          ok = false;\n        }",
		'replace' => "if (requireTypedConfirm && typedField && typedField.value.trim() !== typedConfirmPhrase) {\n          ok = false;\n        }",
		'test' => 'AristotelesPersonalMailBachusP1CloseoutContractTest::testTypedConfirmIsCaseInsensitive',
		'label' => 'typed confirm case-sensitive again',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "if (connectFormEl && !connectFormEl.hidden && imapDetailsEl && oauthConfiguredCount === 0) {\n        imapDetailsEl.open = true;\n        const serversEl = document.getElementById('crm-personal-mail-servers');\n        if (serversEl) serversEl.open = true;\n      }\n      consumePersonalMailQueryFeedback();",
		'replace' => "if (connectFormEl && !connectFormEl.hidden && imapDetailsEl && oauthConfiguredCount === 0) {\n        imapDetailsEl.open = true;\n        const serversEl = document.getElementById('crm-personal-mail-servers');\n        if (serversEl) serversEl.open = true;\n      }\n      // mutant: skip oauth query feedback",
		'test' => 'AristotelesPersonalMailBachusP1CloseoutContractTest::testOauthCallbackQueryFeedbackSurfaced',
		'label' => 'OAuth query feedback never called',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "if (syncBtn) syncBtn.hidden = !!needsReconnect;\n        if (disconnectBtn) disconnectBtn.hidden = false;",
		'replace' => "if (syncBtn) syncBtn.hidden = !!needsReconnect;\n        if (disconnectBtn) disconnectBtn.hidden = !!needsReconnect;",
		'test' => 'AristotelesPersonalMailDisconnectWhileQuarantinedContractTest::testReconnectHidesSyncNotDisconnect',
		'label' => 'Disconnect hidden while quarantined',
	],
	[
		'file' => $root . '/templates/settings/my-email.php',
		'find' => 'id="crm-personal-mail-edit-connection"',
		'replace' => 'id="crm-personal-mail-edit-connection-disabled"',
		'test' => 'PersonalMailUpdateConnectionContractTest::testSettingsPageWiresEditConnection',
		'label' => 'Update connection button removed',
	],
	[
		'file' => $root . '/js/utils/personal-mail-settings-core.js',
		'find' => "return !!input.editConnection;",
		'replace' => 'return false;',
		'test' => 'PersonalMailUpdateConnectionContractTest::testCoreUtilsAndBrowserMirrorStayAligned',
		'label' => 'voluntary edit never shows connect form',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "if (connectForm) connectForm.hidden = !showForm;",
		'replace' => "if (connectForm) connectForm.hidden = !needsReconnect;",
		'test' => 'PersonalMailUpdateConnectionContractTest::testSettingsPageWiresEditConnection',
		'label' => 'ok inbox cannot open update form',
	],
	[
		'file' => $root . '/js/utils/personal-mail-settings-core.js',
		'find' => "if (boxEmail === '' || form === '' || boxEmail !== form) {\n    return 0;\n  }\n  return id;",
		'replace' => "if (boxEmail === '' || form === '' || boxEmail !== form) {\n    return 0;\n  }\n  return 0;",
		'test' => 'PersonalMailUpdateConnectionContractTest::testCoreUtilsAndBrowserMirrorStayAligned',
		'label' => 'heal id always zero',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "if (!ack || !ack.confirmed) {\n            if (shareEl) shareEl.checked = false;\n            // Keep the folder in the PUT payload — omit would delete it from sync (replacePersonalFolders).\n            folder.shareWithWorkspace = false;\n            out.push(folder);\n            continue;\n          }",
		'replace' => "if (!ack || !ack.confirmed) {\n            if (shareEl) shareEl.checked = false;\n            continue;\n          }",
		'test' => 'AristotelesPersonalMailShareCancelKeepsFolderContractTest::testShareCancelPushesPrivateFolderRow',
		'label' => 'share cancel omits folder from payload',
	],
	[
		'file' => $root . '/lib/Support/ImapUtf7.php',
		'find' => "return mb_strtolower(\$utf8, 'UTF-8');",
		'replace' => "return strtolower(\$utf8);",
		'test' => 'ImapUtf7Test::testFolderKeyCaseFoldsUmlauts',
		'label' => 'folderKey loses umlaut case-fold',
	],
	[
		'file' => $root . '/lib/Support/ImapLiveProbe.php',
		'find' => "\$name = ImapConnection::normalizeFolderPath(\$name);",
		'replace' => "\$name = trim(\$name);",
		'test' => 'ImapLiveProbeTest::testParseListLinesDecodesUmlautsAndKeepsSubfolders',
		'label' => 'LIST parse skips UTF-7 decode',
	],
	[
		'file' => $root . '/lib/Service/PersonalMailService.php',
		'find' => "function refreshFolders(int \$workspaceId, int \$mailboxId, string \$userId): array",
		'replace' => "function refreshFoldersDisabled(int \$workspaceId, int \$mailboxId, string \$userId): array",
		'test' => 'AristotelesPersonalMailFolderRefreshContractTest::testRefreshFoldersApiAndServiceExist',
		'label' => 'refreshFolders renamed away',
	],
	[
		'file' => $root . '/templates/settings/my-email.php',
		'find' => "id=\"crm-personal-mail-folders-refresh\"",
		'replace' => "id=\"crm-personal-mail-folders-reload\"",
		'test' => 'AristotelesPersonalMailFolderRefreshContractTest::testSettingsUxHasRefreshFoldersControl',
		'label' => 'Refresh folders button id removed',
	],
	[
		'file' => $root . '/lib/Service/ReviewService.php',
		'find' => "if (\$kind === 'mail') {\n\t\t\treturn ['shared_mail', 'personal_mail_shared_unmatched'];\n\t\t}",
		'replace' => "if (\$kind === 'mail') {\n\t\t\treturn ['shared_mail'];\n\t\t}",
		'test' => 'ReviewMailKindFilterTest',
		'label' => 'mail filter drops personal shared unmatched',
	],
	[
		'file' => $root . '/lib/Service/ReviewService.php',
		'find' => "return \$kind === 'shared_mail' || \$kind === 'personal_mail_shared_unmatched';",
		'replace' => "return \$kind === 'shared_mail';",
		'test' => 'ReviewMailKindFilterTest',
		'label' => 'isMailSourceKind ignores personal shared',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "const mailMsgId = isMailSourceKind(item.sourceKind)\n          ? (parseInt(String(ref.mailMsgId || '0'), 10) || 0)\n          : 0;",
		'replace' => "const mailMsgId = item.sourceKind === 'shared_mail'\n          ? (parseInt(String(ref.mailMsgId || '0'), 10) || 0)\n          : 0;",
		'test' => 'InboxAssignMergeContractTest',
		'label' => 'JS preview skips personal shared mail',
	],
	[
		'file' => $root . '/js/pages/sales-inbox.js',
		'find' => "if (!handOffRetry) {\n        assignBusy = false;\n        setActionBusy(false);\n      }",
		'replace' => "assignBusy = false;\n      setActionBusy(false);",
		'test' => 'BachusPersonalMailSimplifyContractTest::testInboxBusyRetryDoesNotUnlockDuringHandOff',
		'label' => 'busy_retry_finally_always_unlocks',
	],
	[
		'file' => $root . '/js/pages/sales-inbox.js',
		'find' => "return postInboxMutate(pathSuffix, { _busyRetried: true, mailId: mid });",
		'replace' => "return postInboxMutate(pathSuffix, { _busyRetried: true });",
		'test' => 'BachusPersonalMailSimplifyContractTest::testInboxBusyRetryDoesNotUnlockDuringHandOff',
		'label' => 'busy_retry_drops_pinned_mail_id',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "if (shareEl) shareEl.setAttribute('data-was-shared', '1');",
		'replace' => "/* mutated: skip data-was-shared mark */",
		'test' => 'BachusPersonalMailSimplifyContractTest::testFolderTogglesAutoSave',
		'label' => 'share_ack_skips_was_shared_mark',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "function schedulePersonalFoldersAutoSave() {\n    personalFoldersSaveDirty = true;",
		'replace' => "function schedulePersonalFoldersAutoSave() {\n    /* mutated: never mark dirty before debounce */",
		'test' => 'BachusPersonalMailSimplifyContractTest::testFolderTogglesAutoSave',
		'label' => 'folder_auto_save_dirty_flag_renamed',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => "/* ≤767px: NC mobile — stack all My email action rows; Sync already full-width above. */\n@media (max-width: 767px) {\n\t#app-content.crm-app .crm-personal-mail-connected-actions,",
		'replace' => "/* mutant: drop My email mobile stack */\n@media (max-width: 1px) {\n\t#app-content.crm-app .crm-personal-mail-connected-actions,",
		'test' => 'BachusPersonalMailSimplifyContractTest::testCssGiantPersonalMailTargets',
		'label' => 'my_email_mobile_stack_mq_removed',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => "/* ≤767px: NC mobile — Sales Inbox chrome (tabs/actions/decide) without changing ≥768 desktop. */\n@media (max-width: 767px) {\n\t#app-content.crm-app .crm-sales-inbox__toolbar {\n\t\tflex-direction: column;",
		'replace' => "/* mutant: Sales Inbox always row */\n@media (max-width: 767px) {\n\t#app-content.crm-app .crm-sales-inbox__toolbar {\n\t\tflex-direction: row;",
		'test' => 'BachusPersonalMailSimplifyContractTest::testResponsiveDesktopSacredDoesNotStackInBaseRules',
		'label' => 'sales_inbox_mobile_toolbar_not_column',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => '+ (var(--crm-folder-depth, 0) * 0.85rem)',
		'replace' => '+ (var(--crm-folder-depth, 0) * 1.75rem)',
		'test' => 'BachusPersonalMailSimplifyContractTest::testResponsiveDesktopSacredDoesNotStackInBaseRules',
		'label' => 'folder_mobile_indent_not_reduced',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => "/* ≤1023px: NC tablet+phone — deep folder trees scroll inside the picker; ≥1024 desktop unchanged. */\n@media (max-width: 1023px) {\n\t#app-content.crm-app .crm-personal-mail-folders,",
		'replace' => "/* mutant: no tablet folder scroll wrapper */\n@media (max-width: 1px) {\n\t#app-content.crm-app .crm-personal-mail-folders,",
		'test' => 'BachusPersonalMailSimplifyContractTest::testCssGiantPersonalMailTargets',
		'label' => 'folder_tablet_overflow_mq_removed',
	],
];

$failed = 0;
$passed = 0;
foreach ($cases as $case) {
	$original = file_get_contents($case['file']);
	if ($original === false || !str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP missing needle: {$case['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($case['file'], str_replace($case['find'], $case['replace'], $original));
	$out = [];
	$code = 1;
	try {
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' --configuration ' . escapeshellarg($root . '/phpunit.xml')
			. ' --filter ' . escapeshellarg($case['test']);
		exec($cmd . ' 2>&1', $out, $code);
	} finally {
		file_put_contents($case['file'], $original);
	}
	// Mutation killed = tests failed under mutant = PASS for gauntlet
	if ($code !== 0) {
		echo "KILLED: {$case['label']}\n";
		$passed++;
	} else {
		echo "SURVIVED (bad): {$case['label']}\n";
		$failed++;
	}
}

echo "Focused personal-mail mutations: killed={$passed} survived/skipped={$failed}\n";
exit($failed > 0 ? 1 : 0);
