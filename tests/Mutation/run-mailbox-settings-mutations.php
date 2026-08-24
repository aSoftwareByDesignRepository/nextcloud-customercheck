<?php

declare(strict_types=1);

/**
 * Focused mailbox settings mutants — prove MailboxSettingsUxContractTest + DirectoryPicker needles kill real bugs.
 */

$root = dirname(__DIR__, 2);

$cases = [
	[
		'file' => $root . '/templates/settings/workspace-mailbox.php',
		'find' => 'class="crm-mailbox-settings" id="section-workspace-mailbox"',
		'replace' => 'class="crm-card" id="section-workspace-mailbox"',
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox settings reverts to mega-card shell',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => 'const generation = ++mailboxesGeneration;',
		'replace' => 'const generation = mailboxesGeneration;',
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox list refresh drops generation bump',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "await Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes', payload);\n        const saved = (res && res.data) || res || {};\n        const savedId = parseInt(String(saved.id || payload.id || 0), 10) || 0;\n        const savedLabel = String(saved.label || payload.label || '').trim();\n        if (C && C.announce) C.announce(tr('Mailbox saved'));\n        // Keep fields visible: wipe-after-save looked like data loss even though the API persisted.\n        await refreshMailboxes();\n        const savedStatus = savedLabel\n          ? tr('Saved. Your mailbox is listed above: %s. Change something only after you test the connection again.', [savedLabel])\n          : tr('Saved. Your mailbox is listed above. Change something only after you test the connection again.');\n        if (savedId > 0) {\n          fillMailboxFormFromCache(savedId, { statusMessage: savedStatus, scrollForm: false });\n          highlightSavedMailbox(savedId);",
		'replace' => "await Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes', payload);\n        if (C && C.announce) C.announce(tr('Mailbox saved'));\n        location.reload();",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox save reintroduces full page reload',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => 'fillMailboxFormFromCache(savedId, { statusMessage: savedStatus, scrollForm: false });',
		'replace' => 'resetMailboxForm();',
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox save wipes form after success again',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "'hasSecret' => trim((string)(\$row['secret_enc'] ?? '')) !== '',",
		'replace' => "'hasSecret' => true,",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'listMailboxes always claims hasSecret',
	],
	[
		'file' => $root . '/js/utils/mailbox-settings-present.js',
		'find' => "case 'ok':\n      return 'success';",
		'replace' => "case 'ok':\n      return 'neutral';",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox ok status tone becomes neutral',
	],
	[
		'file' => $root . '/lib/Controller/PageController.php',
		'find' => "registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'common/signature-logo-core', 'common/personal-mail-settings-core', 'common/rich-mail-composer', 'common/mailbox-settings-present']);",
		'replace' => "registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'common/mailbox-settings-present']);",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'settings page drops mailbox-settings-present',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "empty.querySelector('.crm-empty-state__title').textContent = tr('No members yet');",
		'replace' => "empty.textContent = tr('No members yet');",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'members empty drops granny empty-state title',
	],
	[
		'file' => $root . '/lib/Controller/PageController.php',
		'find' => "registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'common/signature-logo-core', 'common/personal-mail-settings-core', 'common/rich-mail-composer', 'common/mailbox-settings-present']);",
		'replace' => "registerFrontEndAssets('pages/settings', null, [], ['common/mailbox-settings-present']);",
		'test' => 'DirectoryPickerContractTest',
		'label' => 'settings page drops folder-picker asset',
	],
	[
		'file' => $root . '/templates/settings/workspace-mailbox.php',
		'find' => '<summary class="crm-advanced__summary"><?php p($l->t(\'Import a Nextcloud group once\')); ?></summary>',
		'replace' => '<summary><?php p($l->t(\'Import a Nextcloud group once\')); ?></summary>',
		'test' => 'DesignSystemAlignmentContractTest',
		'label' => 'mailbox advanced summary drops touch class',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => 'function setReviewSourcesLocked(locked) {',
		'replace' => "function setReviewSourcesLocked(locked) { if (locked) { return; } /* mutated: never lock */",
		'test' => 'DirectoryPickerContractTest',
		'label' => 'review sources never locks during boot hydrate',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "if (array_key_exists('folders', \$data)) {",
		'replace' => "if (/* mutated: ignore folders key */ false) {",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox save drops folders key guard',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "const folderList = Array.isArray(box.folders) ? box.folders.map((f) => String(f || '').trim()).filter(Boolean) : [];",
		'replace' => 'const folderList = [];',
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'edit mailbox clears folders instead of hydrate',
	],
	[
		'file' => $root . '/lib/Support/ImapConnection.php',
		'find' => "\$stripped = preg_replace('/\\s*\\([mw]\\d+[a-z0-9]*\\)\\s*\$/i', '', \$user);",
		'replace' => '$stripped = $user;',
		'test' => 'ImapConnectionTest',
		'label' => 'username normalize drops All-Inkl suffix strip',
	],
	[
		'file' => $root . '/lib/Support/ImapConnection.php',
		'find' => "if (\$port === 465) {\n\t\t\treturn self::ENC_SSL;\n\t\t}",
		'replace' => "if (\$port === 465) {\n\t\t\treturn self::ENC_TLS;\n\t\t}",
		'test' => 'ImapConnectionTest',
		'label' => 'smtp 465 resolves to tls instead of ssl',
	],
	[
		'file' => $root . '/lib/Support/ImapConnection.php',
		'find' => "\$flags[] = 'novalidate-cert';",
		'replace' => "\$flags[] = 'validate-cert';",
		'test' => 'ImapConnectionTest',
		'label' => 'novalidate-cert flag mutated away',
	],
	[
		'file' => $root . '/templates/settings/workspace-mailbox.php',
		'find' => '<details class="crm-advanced" id="crm-mailbox-servers">',
		'replace' => '<fieldset class="crm-fieldset" id="crm-mailbox-servers">',
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mail servers escape progressive disclosure',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "->set('imap_enc', \$qb->createNamedParameter(\$imapEnc))",
		'replace' => "->set('label', \$qb->createNamedParameter((string)\$data['label']))",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox update drops imap_enc persistence',
	],
	[
		'file' => $root . '/js/common/api.js',
		'find' => "payload = {\n\t\t\t\t\tsuccess: false,\n\t\t\t\t\terror: 'PARSE_ERROR',\n\t\t\t\t\tmessage: humanMessageFromBody(text, response.status),\n\t\t\t\t};",
		'replace' => "payload = { success: false, error: 'PARSE_ERROR', message: text };",
		'test' => 'WorkspaceAclSurfaceContractTest',
		'label' => 'api.js dumps raw HTML body into message again',
	],
	[
		'file' => $root . '/templates/settings/workspace-mailbox.php',
		'find' => '<div id="crm-mbox-folder-picker" class="crm-folder-picker"',
		'replace' => '<textarea id="crm-mbox-folders" class="form-input"',
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'folder picker reverts to textarea',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => 'return $this->testConnectionViaLiveProbe($host, $port, $user, $pass);',
		'replace' => "return ['ok' => false, 'message' => 'PHP IMAP extension is not available on this server.', 'reason' => 'extension'];",
		'test' => 'MailboxSyncServiceTest',
		'label' => 'testConnection drops live probe fallback',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "\$qb->set('status', \$qb->createNamedParameter('ok'))\n\t\t\t\t->set('auth_fail_count', \$qb->createNamedParameter(0))\n\t\t\t\t->set('last_error', \$qb->createNamedParameter(null));",
		'replace' => "/* mutated: keep error quarantine after save */;",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox save drops error quarantine clear',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "if (!kind || kind === 'shared_mail') {\n      await renderFailedSendsSection(mount, ws, function () { return loadReview(mount); });\n    }",
		'replace' => "/* mutated: hide failed sends on Review */",
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review drops failed sends section',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => 'return $this->doSyncViaLiveProbe($mailboxId, $workspaceId, $row);',
		'replace' => "return ['ingested' => 0, 'status' => 'no_imap'];",
		'test' => 'MailboxSyncServiceTest',
		'label' => 'sync drops live probe ingest fallback',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "\$delBox->delete('crm_mailbox')",
		'replace' => "\$delBox->delete('crm_mail_fld')",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox delete drops crm_mailbox row delete',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => 'purgeOpenSharedMailAssignForMessages',
		'replace' => 'purgeOpenSharedMailAssignForMessagesDISABLED',
		'test' => 'MailboxServiceDeleteTest',
		'label' => 'mailbox delete drops open Review purge',
	],
	[
		'file' => $root . '/lib/Service/MailboxSyncService.php',
		'find' => '$this->mailboxes->delete($workspaceId, $mailboxId);',
		'replace' => '/* mutated: skip delete under lock */',
		'test' => 'MailboxServiceDeleteTest',
		'label' => 'sync deleteMailbox skips service delete',
	],
	[
		'file' => $root . '/appinfo/routes.php',
		'find' => "/mailboxes/{id}/remove', 'verb' => 'POST'",
		'replace' => "/mailboxes/{id}/remove', 'verb' => 'PUT'",
		'test' => 'MailboxServiceDeleteTest',
		'label' => 'mailbox remove route verb mutated away from POST',
	],
	[
		'file' => $root . '/js/pages/settings.js',
		'find' => "Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes/' + mailboxId + '/remove', {})",
		'replace' => "Api.del('/workspaces/' + currentWorkspaceId + '/mailboxes/' + mailboxId)",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox remove UI reverts to brittle DELETE-only',
	],
	[
		'file' => $root . '/lib/Support/ImapLiveProbe.php',
		'find' => 'MimeHeader::decode(trim($m[1]))',
		'replace' => 'trim($m[1])',
		'test' => 'MimeHeaderTest',
		'label' => 'live probe subject skips MIME decode',
	],
	[
		'file' => $root . '/lib/Support/MimeHeader.php',
		'find' => "if (!str_contains(\$raw, '=?')) {\n\t\t\treturn \$raw;\n\t\t}",
		'replace' => "if (!str_contains(\$raw, '=?')) {\n\t\t\treturn 'RAW:' . \$raw;\n\t\t}",
		'test' => 'MimeHeaderTest',
		'label' => 'mime decode corrupts plain subjects',
	],
	[
		'file' => $root . '/lib/Service/MailboxService.php',
		'find' => "MimeHeader::decode((string)(\$msg['subject'] ?? ''))",
		'replace' => "(string)(\$msg['subject'] ?? '')",
		'test' => 'MimeHeaderTest',
		'label' => 'ingest drops MIME subject decode',
	],
	[
		'file' => $root . '/lib/Service/ReviewService.php',
		'find' => "MimeHeader::decode((string)(\$data['subject'] ?? ''))",
		'replace' => "(string)(\$data['subject'] ?? '')",
		'test' => 'MimeHeaderTest',
		'label' => 'review insert drops MIME subject decode',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "table table--hover crm-table--responsive",
		'replace' => 'table table--hover',
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'suite table drops responsive class on table',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "'crm-table-actions-col actions-cell'",
		'replace' => "'crm-table-actions-col'",
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'suite table drops actions-cell on action column',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => 'function renderReviewBoard',
		'replace' => 'function renderReviewStack',
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review board renderer renamed away',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "t('Assign to a company')",
		'replace' => "t('Assign company')",
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review assign-to-company label mutated',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => 'crm-review-pick-company',
		'replace' => 'crm-review-pick-firm',
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review progressive company pick class mutated',
	],
	[
		'file' => $root . '/css/app.css',
		'find' => '.crm-review-row',
		'replace' => '.crm-review-tile',
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review card CSS class mutated',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => 'data-crm-review-discard',
		'replace' => 'data-crm-review-trash',
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review drops per-row discard button',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => "/review/items/discard-bulk",
		'replace' => "/review/items/bulk-discard",
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'review bulk discard API path mutated',
	],
	[
		'file' => $root . '/lib/Service/ReviewService.php',
		'find' => 'if (count($normalized) > Constants::REVIEW_DISCARD_BULK_MAX) {',
		'replace' => 'if (false && count($normalized) > Constants::REVIEW_DISCARD_BULK_MAX) {',
		'test' => 'ReviewDiscardBulkTest',
		'label' => 'discard bulk drops size cap',
	],
	[
		'file' => $root . '/lib/Service/ReviewService.php',
		'find' => "if (\$id > 0 && !isset(\$out[\$id])) {\n\t\t\t\t\$out[\$id] = \$id;\n\t\t\t}",
		'replace' => "if (\$id >= 0 && !isset(\$out[\$id])) {\n\t\t\t\t\$out[\$id] = \$id;\n\t\t\t}",
		'test' => 'ReviewDiscardBulkTest',
		'label' => 'discard bulk normalize accepts zero ids',
	],
	[
		'file' => $root . '/appinfo/routes.php',
		'find' => "'name' => 'review_api#discardBulk'",
		'replace' => "'name' => 'review_api#bulkDiscard'",
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'discard bulk route name mutated',
	],
	[
		'file' => $root . '/js/pages/persona.js',
		'find' => 'pre.textContent',
		'replace' => 'pre.innerHTML',
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'mail preview injects HTML instead of textContent',
	],
	[
		'file' => $root . '/appinfo/routes.php',
		'find' => "['name' => 'persona_api#previewMail', 'url' => '/api/v1/workspaces/{workspaceId}/mail/{id}/preview', 'verb' => 'POST'",
		'replace' => "['name' => 'persona_api#mailPreview', 'url' => '/api/v1/workspaces/{workspaceId}/mail/{id}/preview', 'verb' => 'POST'",
		'test' => 'ReviewInboxUxContractTest',
		'label' => 'mail preview route name mutated',
	],
	[
		'file' => $root . '/lib/Support/MailPreviewText.php',
		'find' => "\$text = html_entity_decode(strip_tags(\$scrubbed), ENT_QUOTES | ENT_HTML5, 'UTF-8');",
		'replace' => '$text = $raw;',
		'test' => 'MailPreviewTextTest',
		'label' => 'mail preview skips HTML strip',
	],
	[
		'file' => $root . '/lib/Support/ImapLiveProbe.php',
		'find' => "if (preg_match('/\\{(\\d+)\\}\\s*$/', \$line) === 1 && isset(\$lines[\$i + 1])) {\n\t\t\t\treturn (string)\$lines[\$i + 1];\n\t\t\t}",
		'replace' => "if (preg_match('/\\{(\\d+)\\}\\s*$/', \$line) === 1 && isset(\$lines[\$i + 1])) {\n\t\t\t\treturn '';\n\t\t\t}",
		'test' => 'ImapLiveProbePreviewParseTest',
		'label' => 'fetch body literal parser returns empty',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';
$failed = 0;
$killed = 0;
$backupDir = sys_get_temp_dir() . '/crm-mbox-mut-' . getmypid();
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
	fwrite(STDERR, "Cannot create backup dir\n");
	exit(1);
}
$pending = [];
$restoreAll = static function () use (&$pending, $backupDir): void {
	foreach ($pending as $path => $contents) {
		file_put_contents($path, $contents);
	}
	$pending = [];
	foreach (glob($backupDir . '/*') ?: [] as $f) {
		@unlink($f);
	}
	@rmdir($backupDir);
};
register_shutdown_function($restoreAll);

foreach ($cases as $case) {
	$original = (string)file_get_contents($case['file']);
	if (!str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP missing needle: {$case['label']}\n");
		$failed++;
		continue;
	}
	$pending[$case['file']] = $original;
	$code = 1;
	try {
		file_put_contents($case['file'], str_replace($case['find'], $case['replace'], $original));
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' --configuration ' . escapeshellarg($config)
			. ' --filter ' . escapeshellarg($case['test']);
		exec($cmd . ' 2>&1', $out, $code);
	} finally {
		file_put_contents($case['file'], $pending[$case['file']]);
		unset($pending[$case['file']]);
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
echo "\nMSI (mailbox-focused): {$msi}% ({$killed}/{$total} killed)\n";
exit($failed === 0 && $killed > 0 ? 0 : 1);
