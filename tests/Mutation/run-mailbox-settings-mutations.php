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
		'find' => "await Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes', payload);\n        if (C && C.announce) C.announce(tr('Mailbox saved'));\n        resetMailboxForm();\n        await refreshMailboxes();",
		'replace' => "await Api.post('/workspaces/' + currentWorkspaceId + '/mailboxes', payload);\n        if (C && C.announce) C.announce(tr('Mailbox saved'));\n        location.reload();",
		'test' => 'MailboxSettingsUxContractTest',
		'label' => 'mailbox save reintroduces full page reload',
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
		'find' => "registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'common/mailbox-settings-present', 'workspace-settings-legacy-redirect']);",
		'replace' => "registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'workspace-settings-legacy-redirect']);",
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
		'find' => "registerFrontEndAssets('pages/settings', null, [], ['common/folder-picker', 'common/mailbox-settings-present', 'workspace-settings-legacy-redirect']);",
		'replace' => "registerFrontEndAssets('pages/settings', null, [], ['workspace-settings-legacy-redirect']);",
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
