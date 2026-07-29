<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$cases = [

	[
		'file' => $root . '/lib/Controller/PageController.php',
		'find' => "\$this->l10n->t('Overdue, unpaid, paid and unknown invoice states.'),",
		'replace' => "\$this->l10n->t('Overdue, unpaid, paid and unknown — day-to-day ops, not tax filing.'),",
		'test' => 'ExtremeSimplicityUxContractTest',
		'label' => 'Money page lead reintroduces tax lecture',
	],
	[
		'file' => $root . '/lib/Service/PulseService.php',
		'find' => "// No tax/GoBD disclaimer chrome — Pulse is an ops glance; honesty lives in docs/Store ban-list.\n\t\t\t'updatedAt' => \$today->format(DATE_ATOM),",
		'replace' => "'disclaimer' => \$this->l10n->t('Day-to-day ops amounts — not for tax filing.'),\n\t\t\t'updatedAt' => \$today->format(DATE_ATOM),",
		'test' => 'ExtremeSimplicityUxContractTest',
		'label' => 'Pulse API reintroduces tax disclaimer field',
	],
	[
		'file' => $root . '/lib/Service/PageQuickStartCatalog.php',
		'find' => "\$l->t('Pulse shows health. Open Money when you need overdue and unpaid details.'),",
		'replace' => "\$l->t('Pulse is a planning glance. Money figures are for day-to-day ops — not for tax filing.'),",
		'test' => 'PageQuickStartCatalogTest',
		'label' => 'Pulse quickstart reintroduces tax lecture',
	],

	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "t('Add a promise')",
		'replace' => "t('Confirm commitment')",
		'test' => 'ExtremeSimplicityUxContractTest',
		'label' => 'Brief add card reverts to Confirm commitment jargon',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "t('At a glance')",
		'replace' => "t('Health metrics')",
		'test' => 'ExtremeSimplicityUxContractTest',
		'label' => 'Pulse glance title reverts to Health metrics',
	],
	[
		'file' => $root . '/lib/Service/SiblingCustomerWriteService.php',
		'find' => "\$existingId > 0 ? ['existingPcCustomerId' => \$existingId] : [],",
		'replace' => '[],',
		'test' => 'SiblingCustomerWriteServiceTest',
		'label' => 'duplicate_name drops existingPcCustomerId',
	],
	[
		'file' => $root . '/lib/Service/MoneyService.php',
		'find' => "'paid30d' => \$this->buildPaidRecent(\$limit, \$facadeAvailable, \$companyIds, \$workspaceId),",
		'replace' => "'paid30d' => \$this->mapPaidRecentCrm(\$limit, \$companyIds),",
		'test' => 'MoneyServiceTest',
		'label' => 'Money Paid ignores IC listRecentlyPaid',
	],
	[
		'file' => $root . '/lib/Service/MoneyService.php',
		'find' => "if (\$workspaceId !== null && \$workspaceId > 0 && \$companyIds === []) {",
		'replace' => "if (false && \$workspaceId !== null && \$workspaceId > 0 && \$companyIds === []) {",
		'test' => 'WorkspaceMoneyIsolationTest',
		'label' => 'workspace empty book still queries global money',
	],
	[
		'file' => $root . '/lib/Service/ImportMatcher.php',
		'find' => "return [\n\t\t\t'action' => 'needs_decision',",
		'replace' => "return [\n\t\t\t'action' => 'link',",
		'test' => 'ImportMatcherTest',
		'label' => 'ambiguity auto-links instead of needs_decision',
	],
	[
		'file' => $root . '/lib/Service/InvoicePaidSyncService.php',
		'find' => 'return $becamePaid || $paidIncreased || $paidGrewWhilePaid;',
		'replace' => 'return $becamePaid || $paidIncreased;',
		'test' => 'InvoicePaidSyncServiceTest',
		'label' => 'paid growth while paid ignored',
	],
	[
		'file' => $root . '/lib/Service/WeeklyDigestService.php',
		'find' => 'return $isoDow === $weekday && (int)$now->format(\'G\') === $hour;',
		'replace' => 'return $isoDow === $weekday;',
		'test' => 'WeeklyDigestServiceTest',
		'label' => 'digest ignores hour gate',
	],

	[
		'file' => $root . '/lib/Service/AgreementStatusService.php',
		'find' => 'if ($daysUntil <= Constants::RENEWAL_WINDOW_DAYS)',
		'replace' => 'if ($daysUntil < Constants::RENEWAL_WINDOW_DAYS)',
		'test' => 'AgreementStatusServiceTest',
		'label' => 'expiry window off-by-one (< vs <=)',
	],
	[
		'file' => $root . '/lib/Service/SlugService.php',
		'find' => "\$slug .= '-co';",
		'replace' => "\$slug .= 'co';",
		'test' => 'SlugServiceTest',
		'label' => 'slug padding loses hyphen',
	],
	[
		'file' => $root . '/lib/Service/ReminderScheduler.php',
		'find' => 'if ($daysUntil <= $offset)',
		'replace' => 'if ($daysUntil < $offset)',
		'test' => 'ReminderSchedulerTest',
		'label' => 'reminder dueOffsets off-by-one',
	],
	[
		'file' => $root . '/lib/Service/ReminderScheduler.php',
		'find' => "return (int)\$now->format('G') === \$hour;",
		'replace' => 'return true;',
		'test' => 'ReminderSchedulerTest',
		'label' => 'reminder ignores hour gate',
	],
	[
		'file' => $root . '/lib/Service/QuotaService.php',
		'find' => 'return $usedMinutes * 100 >= $quotaMinutes * (int)Constants::QUOTA_ALERT_PERCENT;',
		'replace' => 'return $usedMinutes * 100 > $quotaMinutes * (int)Constants::QUOTA_ALERT_PERCENT;',
		'test' => 'QuotaServiceTest',
		'label' => 'quota 80% boundary uses > instead of >=',
	],
	[
		'file' => $root . '/lib/Service/AccessControlService.php',
		'find' => 'if (!$this->isSystemAdmin($userId))',
		'replace' => 'if (false && !$this->isSystemAdmin($userId))',
		'test' => 'AccessControlServiceTest',
		'label' => 'isAppAdmin skips system-admin requirement',
	],
	[
		'file' => $root . '/lib/Service/StageSequenceService.php',
		'find' => "['title' => \$this->l10n->t('Follow up quote'), 'type' => 'call', 'offsetDays' => 3],",
		'replace' => "['title' => \$this->l10n->t('Follow up quote'), 'type' => 'call', 'offsetDays' => 2],",
		'test' => 'StageSequenceServiceTest',
		'label' => 'quoted sequence +3 becomes +2',
	],
	[
		'file' => $root . '/lib/Service/LeadWebhookService.php',
		'find' => 'if (mb_strlen($companyName) < 2) {',
		'replace' => 'if (false && mb_strlen($companyName) < 2) {',
		'test' => 'LeadWebhookServiceTest',
		'label' => 'lead webhook skips companyName validation',
	],
	[
		'file' => $root . '/lib/Service/LicenseKeyEventService.php',
		'find' => "return ['action' => 'inbox', 'importJobId' => (int)\$stored->getId()];",
		'replace' => "return ['action' => 'draft', 'importJobId' => (int)\$stored->getId()];",
		'test' => 'LicenseKeyEventServiceTest',
		'label' => 'orphan license event reports draft instead of inbox',
	],
	[
		'file' => $root . '/lib/Db/AgreementMapper.php',
		'find' => "\t\t// Archived companies stop generating renewal work (Today + reminder job).\n\t\t\$this->excludeArchivedCompanies(\$qb);\n",
		'replace' => '',
		'test' => 'ArchivedCompanyExclusionIntegrationTest',
		'label' => 'renewal queue leaks archived companies',
	],
	[
		'file' => $root . '/lib/Db/ActivityMapper.php',
		'find' => "\t\t// Archived companies stop generating open work (PSD §4.1).\n\t\t\$this->excludeArchivedCompanies(\$qb);\n",
		'replace' => '',
		'test' => 'ArchivedCompanyExclusionIntegrationTest',
		'label' => 'Today activities leak archived companies',
	],
	[
		'file' => $root . '/lib/Db/DealMapper.php',
		'find' => "\t\t// Archived companies stop generating stale-deal nudges (PSD §4.1).\n\t\t\$this->excludeArchivedCompanies(\$qb);\n",
		'replace' => '',
		'test' => 'ArchivedCompanyExclusionIntegrationTest',
		'label' => 'stale-deal queue leaks archived companies',
	],
	[
		'file' => $root . '/lib/License/Cuc2Codec.php',
		'find' => 'if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, VendorPublicKey::bytes())) {',
		'replace' => 'if (false && !sodium_crypto_sign_verify_detached($signature, $payloadBytes, VendorPublicKey::bytes())) {',
		'test' => 'Cuc2CodecTest',
		'label' => 'CUC2 skips Ed25519 signature verification',
	],
	[
		'file' => $root . '/lib/License/Cuc2Codec.php',
		'find' => 'return $today <= $validUntil;',
		'replace' => 'return $today < $validUntil;',
		'test' => 'Cuc2CodecTest',
		'label' => 'CUC2 validUntil last day off-by-one',
	],
	[
		'file' => $root . '/lib/License/Cuc2Codec.php',
		'find' => 'if (!is_int($seats) || $seats < 1 || $seats > 10000) {',
		'replace' => 'if (!is_int($seats) || $seats < 0 || $seats > 10000) {',
		'test' => 'Cuc2CodecTest',
		'label' => 'CUC2 accepts zero mobile seats',
	],
	[
		'file' => $root . '/lib/Service/SeatRank.php',
		'find' => 'return $ranks[$seatId] <= $limit;',
		'replace' => 'return $ranks[$seatId] < $limit;',
		'test' => 'SeatRankTest',
		'label' => 'seat limit boundary off-by-one',
	],
	[
		'file' => $root . '/lib/Service/SeatRank.php',
		'find' => "return \$a['assignedAt'] <=> \$b['assignedAt'];",
		'replace' => "return \$b['assignedAt'] <=> \$a['assignedAt'];",
		'test' => 'SeatRankTest',
		'label' => 'seat ranking pauses oldest instead of newest',
	],
	[
		'file' => $root . '/lib/Service/LicenseService.php',
		'find' => 'if ($limit <= 0 || $this->seats->countAll() >= $limit) {',
		'replace' => 'if ($limit <= 0 || $this->seats->countAll() > $limit) {',
		'test' => 'LicenseServiceTest',
		'label' => 'seat assignment allows one seat over limit',
	],
	[
		'file' => $root . '/lib/Service/LicenseService.php',
		'find' => "if (\$state === null) {\n\t\t\t\t\tthrow new LicenseException(\n\t\t\t\t\t\t'NO_LICENSE',\n\t\t\t\t\t\t\$this->l10n->t('Apply a CustomerCheck Mobile license before assigning seats.'),\n\t\t\t\t\t\t409,\n\t\t\t\t\t);\n\t\t\t\t}",
		'replace' => 'if (false && $state === null) { throw new LicenseException(\'NO_LICENSE\', \'x\', 409); }',
		'test' => 'LicenseServiceTest',
		'label' => 'assign without license falls through to zero-limit math',
	],
	[
		'file' => $root . '/lib/Service/LicenseService.php',
		'find' => '$expiresSoon = $daysLeft >= 0 && $daysLeft <= 30;',
		'replace' => '$expiresSoon = $daysLeft >= 0 && $daysLeft < 30;',
		'test' => 'LicenseServiceTest',
		'label' => 'expiresSoon 30-day boundary off-by-one',
	],
	[
		'file' => $root . '/lib/Service/MobileGateService.php',
		'find' => "\$enabledForUser = \$state['seatAssigned'] && \$state['seatWithinLimit'] && \$state['licenseValid'];",
		'replace' => "\$enabledForUser = \$state['seatAssigned'] && \$state['seatWithinLimit'];",
		'test' => 'MobileGateServiceTest',
		'label' => 'mobile bootstrap ignores license validity',
	],
	[
		'file' => $root . '/lib/Service/MobileApiService.php',
		'find' => "(!empty(\$item['inGrace']) || (int)(\$item['daysUntil'] ?? 99) <= 7) ? 'danger' : 'warning',",
		'replace' => "(!empty(\$item['inGrace']) || (int)(\$item['daysUntil'] ?? 99) < 7) ? 'danger' : 'warning',",
		'test' => 'MobileApiServiceTest',
		'label' => 'renewal urgency 7-day boundary off-by-one',
	],
	[
		'file' => $root . '/lib/Service/EmailOverviewPresenter.php',
		'find' => "if (\$direction === 'inbound') {\n\t\t\treturn \$from !== '' ? \$from : \$to;\n\t\t}\n\t\treturn \$to !== '' ? \$to : \$from;",
		'replace' => "if (\$direction === 'inbound') {\n\t\t\treturn \$to !== '' ? \$to : \$from;\n\t\t}\n\t\treturn \$from !== '' ? \$from : \$to;",
		'test' => 'EmailOverviewPresenterTest',
		'label' => 'withWhom swaps inbound/outbound counterpart',
	],
	[
		'file' => $root . '/lib/Support/DensityCounts.php',
		'find' => "private const CLOSED_PROJECT = ['completed', 'cancelled', 'archived', 'done'];",
		'replace' => "private const CLOSED_PROJECT = ['completed'];",
		'test' => 'DensityCountsTest',
		'label' => 'open project count ignores only completed',
	],
	[
		'file' => $root . '/lib/Support/DensityCounts.php',
		'find' => "'finalized',\n\t\t'partially_paid',\n\t\t'paid_pending_sync',",
		'replace' => "'partially_paid',\n\t\t'paid_pending_sync',",
		'test' => 'DensityCountsTest',
		'label' => 'unpaid invoice set drops finalized',
	],
	[
		'file' => $root . '/lib/Support/DensityCounts.php',
		'find' => "if (array_key_exists('overdue', \$inv) && filter_var(\$inv['overdue'], FILTER_VALIDATE_BOOLEAN)) {\n\t\t\treturn true;\n\t\t}",
		'replace' => "if (false && array_key_exists('overdue', \$inv) && filter_var(\$inv['overdue'], FILTER_VALIDATE_BOOLEAN)) {\n\t\t\treturn true;\n\t\t}",
		'test' => 'DensityCountsTest',
		'label' => 'overdue facade flag ignored',
	],
	[
		'file' => $root . '/lib/Support/DensityCounts.php',
		'find' => "if (\$status !== '' && str_contains(\$status, 'wait')) {",
		'replace' => "if (\$status === '' || str_contains(\$status, 'wait') || str_contains(\$status, 'open')) {",
		'test' => 'DensityCountsTest',
		'label' => 'waiting tickets over-count open/empty',
	],
	[
		'file' => $root . '/lib/Service/CompanyMergeService.php',
		'find' => 'if ($sourceId === $intoCompanyId) {',
		'replace' => 'if (false && $sourceId === $intoCompanyId) {',
		'test' => 'CompanyMergeServiceTest',
		'label' => 'merge into self check disabled',
	],
	[
		'file' => $root . '/lib/Service/CompanyMergeService.php',
		'find' => 'if ($keeper->isArchived()) {',
		'replace' => 'if (false && $keeper->isArchived()) {',
		'test' => 'CompanyMergeServiceTest',
		'label' => 'merge into archived target allowed',
	],
	[
		'file' => $root . '/lib/Service/MobilePushService.php',
		'find' => "Constants::NOTIFY_REMINDER,\n\t\tConstants::NOTIFY_DIGEST,\n\t\tConstants::NOTIFY_ACTIVITY_DUE,",
		'replace' => "Constants::NOTIFY_REMINDER,\n\t\tConstants::NOTIFY_DIGEST,",
		'test' => 'MobilePushServiceTest',
		'label' => 'push subjects drop activity due',
	],
	[
		'file' => $root . '/lib/Service/ActivityDueReminderService.php',
		'find' => 'if ($this->ledger->exists($activityId, $dueYmd)) {',
		'replace' => 'if (false && $this->ledger->exists($activityId, $dueYmd)) {',
		'test' => 'ActivityDueReminderServiceTest',
		'label' => 'activity due skips ledger exists check',
	],
	[
		'file' => $root . '/lib/Service/MobileIdempotencyService.php',
		'find' => "if (preg_match('/^[A-Za-z0-9:_.-]+$/', \$key) !== 1) {",
		'replace' => "if (false && preg_match('/^[A-Za-z0-9:_.-]+$/', \$key) !== 1) {",
		'test' => 'MobileIdempotencyServiceTest',
		'label' => 'idempotency key charset check disabled',
	],
	[
		'file' => $root . '/lib/Service/MobileIdempotencyService.php',
		'find' => "if (\$existingId !== null) {\n\t\t\treturn \$this->activities->get(\$existingId);\n\t\t}",
		'replace' => "if (false && \$existingId !== null) {\n\t\t\treturn \$this->activities->get(\$existingId);\n\t\t}",
		'test' => 'MobileIdempotencyServiceTest',
		'label' => 'idempotency skips existing replay',
	],
	[
		'file' => $root . '/lib/Service/MobilePushService.php',
		'find' => "Constants::NOTIFY_DIGEST,\n\t\tConstants::NOTIFY_ACTIVITY_DUE,",
		'replace' => 'Constants::NOTIFY_ACTIVITY_DUE,',
		'test' => 'MobilePushServiceTest',
		'label' => 'push subjects drop digest',
	],
	[
		'file' => $root . '/lib/Service/MobileGateService.php',
		'find' => "'push' => true,",
		'replace' => "'push' => false,",
		'test' => 'MobileGateServiceTest',
		'label' => 'bootstrap push capability flipped off',
	],
	[
		'file' => $root . '/lib/Service/CompanyService.php',
		'find' => "if (str_contains(\$haystack, 'crm_co_pc_uq')\n\t\t\t|| (str_contains(\$haystack, 'pc_customer_id') && str_contains(\$haystack, 'unique'))) {\n\t\t\treturn 'pc';\n\t\t}",
		'replace' => "if (false) {\n\t\t\treturn 'pc';\n\t\t}",
		'test' => 'CompanyServiceSiblingUniqueTest',
		'label' => 'pc unique race misclassified as slug',
	],
	[
		'file' => $root . '/lib/Service/BillingIntegrityService.php',
		'find' => "if (\$overdue >= 1) {",
		'replace' => 'if ($overdue > 1) {',
		'test' => 'CommandCenterCoreLogicTest',
		'label' => 'billing critical off-by-one overdue',
	],
	[
		'file' => $root . '/lib/Service/BillingIntegrityService.php',
		'find' => "\$state = 'attention';",
		'replace' => "\$state = 'ok';",
		'test' => 'CommandCenterCoreLogicTest',
		'label' => 'billing attention becomes ok',
	],
	[
		'file' => $root . '/lib/Service/NextActionPriority.php',
		'find' => "'callback_overdue', 'activity_overdue' => 700 + (!empty(\$c['preferCall']) ? 10 : 0),",
		'replace' => "'callback_overdue', 'activity_overdue' => 100,",
		'test' => 'CommandCenterCoreLogicTest',
		'label' => 'next-action overdue score demoted',
	],
	[
		'file' => $root . '/lib/Service/CommunicationFeedService.php',
		'find' => "\$cmp = strcmp((string)(\$b['occurredAt'] ?? ''), (string)(\$a['occurredAt'] ?? ''));",
		'replace' => "\$cmp = strcmp((string)(\$a['occurredAt'] ?? ''), (string)(\$b['occurredAt'] ?? ''));",
		'test' => 'CommandCenterCoreLogicTest',
		'label' => 'feed sort ascending instead of DESC',
	],
	[
		'file' => $root . '/lib/Service/ContactsUidValidator.php',
		'find' => 'if (mb_strlen($uid) > 255) {',
		'replace' => 'if (mb_strlen($uid) > 512) {',
		'test' => 'CommandCenterCoreLogicTest',
		'label' => 'contacts uid max length raised',
	],
	[
		'file' => $root . '/lib/Service/CallbackService.php',
		'find' => "if (\$dueRaw === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', \$dueRaw)) {",
		'replace' => "if (false && (\$dueRaw === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', \$dueRaw))) {",
		'test' => 'CallbackServiceTest',
		'label' => 'callback due date validation disabled',
	],
	[
		'file' => $root . '/lib/Service/CallbackService.php',
		'find' => 'if ($activity->isCallback()) {',
		'replace' => 'if (false && $activity->isCallback()) {',
		'test' => 'CallbackServiceTest',
		'label' => 'callback flag detection disabled',
	],
	[
		'file' => $root . '/lib/Service/DisplayLabelService.php',
		'find' => "'critical' => \$this->l10n->t('Overdue'),",
		'replace' => "'critical' => \$this->l10n->t('Paid up'),",
		'test' => 'DisplayLabelServiceTest',
		'label' => 'billing critical mislabelled as paid up',
	],
	[
		'file' => $root . '/lib/Service/CompanyCommandService.php',
		'find' => "'filesLinked' => \$company->getFilesFolderId() !== null && (int)\$company->getFilesFolderId() > 0,",
		'replace' => "'filesLinked' => false,",
		'test' => 'CompanyCommandServiceBuildTest',
		'label' => 'filesLinked always false',
	],
	[
		'file' => $root . '/lib/Service/CompanyCommandService.php',
		'find' => 'if ($act->isCallback() || (string)$act->getType() === \'call\') {',
		'replace' => 'if (false && ($act->isCallback() || (string)$act->getType() === \'call\')) {',
		'test' => 'CompanyCommandServiceBuildTest',
		'label' => 'openCallbacks ignores callback flag',
	],
	[
		'file' => $root . '/lib/Service/ChaseLogic.php',
		'find' => 'return $skipYmd <= $max;',
		'replace' => 'return $skipYmd < $max;',
		'test' => 'PersonaOsLogicTest',
		'label' => 'chase skip max off-by-one',
	],
	[
		'file' => $root . '/lib/Service/BriefLogic.php',
		'find' => 'return $currentCommittedWip < $limit;',
		'replace' => 'return $currentCommittedWip <= $limit;',
		'test' => 'PersonaOsLogicTest',
		'label' => 'WIP confirm allows over-limit',
	],
	[
		'file' => $root . '/lib/Service/BriefLogic.php',
		'find' => "'expectedInThisMonthCents' => \$arThis + \$renThis,",
		'replace' => "'expectedInThisMonthCents' => \$arThis + \$renThis + \$pipeline,",
		'test' => 'PersonaOsLogicTest',
		'label' => 'cash calendar includes pipeline',
	],
	[
		'file' => $root . '/lib/Service/BriefLogic.php',
		'find' => "if (\$code === 'manager_override' && trim((string)\$note) === '') {",
		'replace' => 'if (false && $code === \'manager_override\' && trim((string)$note) === \'\') {',
		'test' => 'PersonaOsLogicTest',
		'label' => 'manager_override accepts empty note',
	],
	[
		'file' => $root . '/lib/Service/BriefLogic.php',
		'find' => "if (\$code === '' || !in_array(\$code, self::doneEvidenceCodes(), true)) {",
		'replace' => 'if (false && ($code === \'\' || !in_array($code, self::doneEvidenceCodes(), true))) {',
		'test' => 'PersonaOsLogicTest',
		'label' => 'done evidence code check disabled',
	],
	[
		'file' => $root . '/lib/Service/WorkspaceCapability.php',
		'find' => "'read', 'write', 'mail.read', 'mail.send', 'brief.confirm',",
		'replace' => "'read', 'write', 'mail.read', 'mail.send',",
		'test' => 'PersonaOsLogicTest',
		'label' => 'member loses brief.confirm',
	],
	[
		'file' => $root . '/lib/Service/PulseLogic.php',
		'find' => "'state' => 'honesty_blocked',",
		'replace' => "'state' => 'ok',",
		'test' => 'PersonaOsLogicTest',
		'label' => 'honesty_blocked becomes fake ok',
	],
	[
		'file' => $root . '/lib/Service/DisplayLabelService.php',
		'find' => "'honesty_blocked' => \$this->l10n->t('Unbilled hours are not ready yet — we will not guess zero.'),",
		'replace' => "'honesty_blocked' => \$this->l10n->t('Not available yet'),",
		'test' => 'DisplayLabelServiceTest',
		'label' => 'unbilled hint reverts to Not available yet',
	],
	[
		'file' => $root . '/lib/Service/FacadeProbeService.php',
		'find' => 'if ($version < 2) {',
		'replace' => 'if (false && $version < 2) {',
		'test' => 'ExtremeSimplicityUxContractTest',
		'label' => 'v1 stub facade accepted as known billable',
	],
	[
		'file' => $root . '/lib/Service/DisplayLabelService.php',
		'find' => "'plan_stalled' => \$this->l10n->t('Plan stalled'),",
		'replace' => "'plan_stalled' => \$this->l10n->t('Paid up'),",
		'test' => 'DisplayLabelServiceTest',
		'label' => 'plan_stalled mislabelled as paid up',
	],
	[
		'file' => $root . '/lib/Service/DisplayLabelService.php',
		'find' => "'champion_gap' => \$this->l10n->t('Missing champion'),",
		'replace' => "'champion_gap' => 'champion_gap',",
		'test' => 'DisplayLabelServiceTest',
		'label' => 'coverage flag leaks snake_case',
	],
	[
		'file' => $root . '/lib/Service/MailMatchLogic.php',
		'find' => 'if ($domain === null || self::isFreemail($domain)) {',
		'replace' => 'if ($domain === null) {',
		'test' => 'PersonaOsLogicTest',
		'label' => 'freemail domain auto-matched',
	],
	[
		'file' => $root . '/lib/Service/CoverageLogic.php',
		'find' => '$enforce = in_array($kamTier, [\'key\', \'partner\'], true);',
		'replace' => '$enforce = false;',
		'test' => 'PersonaOsLogicTest',
		'label' => 'coverage gap never enforced',
	],
	[
		'file' => $root . '/lib/Service/OooLogic.php',
		'find' => 'if ($days > Constants::OOO_MAX_DAYS) {',
		'replace' => 'if ($days > 9999) {',
		'test' => 'PersonaOsLogicTest',
		'label' => 'OOO max days disabled',
	],
	[
		'file' => $root . '/lib/Service/WorkspaceCapability.php',
		'find' => 'return in_array($capability, $caps, true);',
		'replace' => 'return true;',
		'test' => 'PersonaOsLogicTest',
		'label' => 'workspace capability always allows',
	],
	[
		'file' => $root . '/lib/Service/WorkspaceAccessService.php',
		'find' => 'if ($companyWorkspaceId <= 0 || $workspaceId <= 0 || $companyWorkspaceId !== $workspaceId) {',
		'replace' => 'if (false && ($companyWorkspaceId <= 0 || $workspaceId <= 0 || $companyWorkspaceId !== $workspaceId)) {',
		'test' => 'WorkspaceAccessIsolationTest',
		'label' => 'cross-workspace company access allowed',
	],
	[
		'file' => $root . '/lib/Support/SharedMailboxSmtpClient.php',
		'find' => "if (\$host === '' || \$from === '' || \$to === '' || \$user === '' || \$pass === '') {",
		'replace' => "if (\$host === '' || \$from === '' || \$to === '') {",
		'test' => 'SharedMailboxSmtpClientTest',
		'label' => 'SMTP accepts empty password',
	],
	[
		'file' => $root . '/lib/Service/CoachingService.php',
		'find' => 'if (in_array(\'deals\', $types, true) || in_array(\'deal\', $types, true)) {',
		'replace' => 'if (false && (in_array(\'deals\', $types, true) || in_array(\'deal\', $types, true))) {',
		'test' => 'CoachingBulkReassignTest',
		'label' => 'bulk entityTypes deals resolution skipped',
	],
	[
		'file' => $root . '/lib/Service/CoachingService.php',
		'find' => "if (!\$isAppAdmin) {\n\t\t\treturn \$members;\n\t\t}",
		'replace' => "if (false && !\$isAppAdmin) {\n\t\t\treturn \$members;\n\t\t}",
		'test' => 'CoachingBoardTest',
		'label' => 'team heal auto-adds non-admin missing membership',
	],
	[
		'file' => $root . '/lib/Service/CoachingService.php',
		'find' => "\$rows[] = [\n\t\t\t\t'userId' => \$uid,\n\t\t\t\t'displayName' => \$this->displayNameFor(\$uid),",
		'replace' => "\$rows[] = [\n\t\t\t\t'userId' => \$uid,\n\t\t\t\t'displayName' => \$uid,",
		'test' => 'CoachingBoardTest',
		'label' => 'team board skips displayName resolution',
	],
	[
		'file' => $root . '/lib/Service/CoachingService.php',
		'find' => "'summary' => [\n\t\t\t\t'people' => count(\$rows),\n\t\t\t\t'stuck' => \$stuckCount,\n\t\t\t],",
		'replace' => "'summary' => [\n\t\t\t\t'people' => 0,\n\t\t\t\t'stuck' => \$stuckCount,\n\t\t\t],",
		'test' => 'CoachingBoardTest',
		'label' => 'team summary people always zero',
	],
	[
		'file' => $root . '/lib/Service/DealService.php',
		'find' => 'if ($code === \'\' || !in_array($code, Constants::LOSS_REASON_CODES, true)) {',
		'replace' => 'if (false && ($code === \'\' || !in_array($code, Constants::LOSS_REASON_CODES, true))) {',
		'test' => 'ChaseOooAndLossCodeTest',
		'label' => 'lost deal accepts missing loss code',
	],
	[
		'file' => $root . '/lib/Service/ChaseService.php',
		'find' => 'if ($owner !== \'\' && !in_array($owner, $mineOwners, true)) {',
		'replace' => 'if ($owner !== \'\' && $owner !== $userId) {',
		'test' => 'ChaseOooAndLossCodeTest',
		'label' => 'chase ignores OOO covered primaries',
	],
	[
		'file' => $root . '/lib/Service/OooCoverService.php',
		'find' => 'if (!OooLogic::isActive($starts, $ends, $today)) {',
		'replace' => 'if (false && !OooLogic::isActive($starts, $ends, $today)) {',
		'test' => 'OooCoverServiceTest',
		'label' => 'OOO cover ignores date window',
	],
	[
		'file' => $root . '/lib/Service/CompanyCommandService.php',
		'find' => '$waitingTickets = DensityCounts::waitingTickets(',
		'replace' => '$waitingTickets = count(',
		'test' => 'CompanyCommandServiceBuildTest',
		'label' => '360 waiting tickets counts all open',
	],
	[
		'file' => $root . '/lib/Service/CompanyDocumentsService.php',
		'find' => "if ((int)\$node->getId() === (int)\$userFolder->getId()) {",
		'replace' => 'if (false && (int)$node->getId() === (int)$userFolder->getId()) {',
		'test' => 'CompanyDocumentsServiceTest',
		'label' => 'documents allows linking Files home root',
	],
	[
		'file' => $root . '/lib/Service/CompanyDocumentsService.php',
		'find' => "if (\$part === '.' || \$part === '..' || \$part === '') {",
		'replace' => "if (\$part === '.' || \$part === '') {",
		'test' => 'CompanyDocumentsServiceTest',
		'label' => 'documents path accepts .. traversal',
	],
	[
		'file' => $root . '/lib/Service/DisplayLabelService.php',
		'find' => "'email.logged' => \$this->l10n->t('Email logged'),",
		'replace' => "'email.logged' => 'email.logged',",
		'test' => 'DisplayLabelServiceTest',
		'label' => 'timeline email.logged leaks machine key',
	],
	[
		'file' => $root . '/lib/Service/DisplayLabelService.php',
		'find' => "default => \$this->l10n->t('Update'),",
		'replace' => 'default => $type,',
		'test' => 'DisplayLabelServiceTest',
		'label' => 'timeline unknown type leaks dotted key',
	],
	[
		'file' => $root . '/lib/Support/WorkspaceSwitchUrlBuilder.php',
		'find' => "if (\$fallbackKey !== null) {\n\t\t\t\$base = (string)(\$urls[\$fallbackKey] ?? \$urls['today'] ?? '#');\n\t\t} else {",
		'replace' => "if (false && \$fallbackKey !== null) {\n\t\t\t\$base = (string)(\$urls[\$fallbackKey] ?? \$urls['today'] ?? '#');\n\t\t} else {",
		'test' => 'WorkspaceSwitchUrlBuilderTest',
		'label' => 'Entity workspace switch keeps company detail URL',
	],
	[
		'file' => $root . '/lib/Support/WorkspaceSwitchUrlBuilder.php',
		'find' => "if (preg_match('#^[a-z][a-z0-9+.-]*:#i', \$base) === 1 || str_starts_with(\$base, '//')) {\n\t\t\treturn '#';\n\t\t}\n\t\t\$parts = parse_url(\$base);\n\t\tif (!is_array(\$parts)) {\n\t\t\treturn '#';\n\t\t}\n\t\tif (isset(\$parts['scheme']) || isset(\$parts['host'])) {\n\t\t\treturn '#';\n\t\t}",
		'replace' => "\$parts = parse_url(\$base);\n\t\tif (!is_array(\$parts)) {\n\t\t\treturn '#';\n\t\t}",
		'test' => 'WorkspaceSwitchUrlBuilderTest',
		'label' => 'Workspace switch allows external href bases',
	],
	[
		'file' => $root . '/templates/common/navigation.php',
		'find' => "\$switchable = count(\$workspaces) > 1;",
		'replace' => "\$switchable = count(\$workspaces) >= 1;",
		'test' => 'WorkspaceSwitcherContractTest',
		'label' => 'Single workspace still renders disclosure trigger',
	],

];

$phpunit = $root . '/vendor/bin/phpunit';
$failed = 0;
$killed = 0;

/** @var array<string, string> $pendingRestores path => original contents */
$pendingRestores = [];
$backupDir = sys_get_temp_dir() . '/crm-mutation-backups-' . getmypid();
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
	fwrite(STDERR, "Cannot create mutation backup dir: {$backupDir}\n");
	exit(1);
}
$restoreAll = static function () use (&$pendingRestores, $backupDir): void {
	foreach ($pendingRestores as $path => $contents) {
		file_put_contents($path, $contents);
	}
	$pendingRestores = [];
	if (is_dir($backupDir)) {
		foreach (glob($backupDir . '/*') ?: [] as $f) {
			@unlink($f);
		}
		@rmdir($backupDir);
	}
};
register_shutdown_function($restoreAll);
if (function_exists('pcntl_async_signals')) {
	pcntl_async_signals(true);
	foreach ([SIGINT, SIGTERM] as $sig) {
		if (function_exists('pcntl_signal')) {
			pcntl_signal($sig, static function () use ($restoreAll): void {
				$restoreAll();
				exit(1);
			});
		}
	}
}

foreach ($cases as $case) {
	$original = file_get_contents($case['file']);
	if (!str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP missing needle: {$case['label']}\n");
		$failed++;
		continue;
	}
	$backupFile = $backupDir . '/' . sha1($case['file'] . "\0" . $case['label']) . '.php';
	file_put_contents($backupFile, $original);
	$pendingRestores[$case['file']] = $original;
	$code = 1;
	try {
		file_put_contents($case['file'], str_replace($case['find'], $case['replace'], $original, $count));
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' --filter ' . escapeshellarg($case['test']);
		exec($cmd . ' 2>&1', $out, $code);
	} finally {
		// Prefer on-disk backup so a crashed parent can still recover via shutdown.
		$restore = is_file($backupFile) ? (string)file_get_contents($backupFile) : $original;
		file_put_contents($case['file'], $restore);
		unset($pendingRestores[$case['file']]);
		@unlink($backupFile);
	}
	if ($code !== 0) {
		echo "KILLED  {$case['label']}\n";
		$killed++;
	} else {
		echo "SURVIVED {$case['label']}\n";
		$failed++;
	}
	$out = [];
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100 * $killed / $total, 1) : 0.0;
echo "\nMSI (critical mutants): {$msi}% ({$killed}/{$total} killed)\n";
exit($failed === 0 && $killed > 0 ? 0 : 1);
