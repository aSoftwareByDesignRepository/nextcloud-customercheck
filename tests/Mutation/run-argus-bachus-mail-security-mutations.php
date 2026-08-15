<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Argus Bachus mail triage AuthZ (MF-01).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-argus-bachus-mail-security-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$lockPath = $appRoot . '/.mutation.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another mutation run holds {$lockPath} — aborting.\n");
	exit(1);
}
register_shutdown_function(static function () use ($lockFh, $lockPath): void {
	flock($lockFh, LOCK_UN);
	fclose($lockFh);
	@unlink($lockPath);
});

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function argus_bachus_mail_security_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Controller/PersonaApiController.php',
			'from' => "\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'mail.read');\n\t\t\t\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'write');\n\t\t\t\$body = \$this->requestBody();\n\t\t\t\$this->mailbox->triageAssign",
			'to' => "\$this->workspaces->assertCapability(\$uid, \$workspaceId, 'mail.read');\n\t\t\t\$body = \$this->requestBody();\n\t\t\t\$this->mailbox->triageAssign",
			'label' => 'triage_drops_write_capability',
			'filter' => 'ArgusBachusMailSecurityContractTest::testTriageMailRequiresWriteNotOnlyMailRead',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'const assignHtml = canWrite',
			'to' => 'const assignHtml = true || canWrite',
			'label' => 'triage_assign_ui_ignores_canWrite',
			'filter' => 'ArgusBachusMailSecurityContractTest::testViewerCannotSeeMailTriageAssignForm',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "form.setAttribute('aria-busy', 'true');\n        submitBtns.forEach(function (b) { b.disabled = true; });\n        try {\n          await apiPost('/api/v1/workspaces/' + ws + '/mail/' + id + '/triage'",
			'to' => "try {\n          await apiPost('/api/v1/workspaces/' + ws + '/mail/' + id + '/triage'",
			'label' => 'triage_submit_loses_busy_lock',
			'filter' => 'ArgusBachusMailSecurityContractTest::testMailTriageAssignSubmitLocksBusy',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'pre.textContent = bodyText !== \'\'',
			'to' => 'pre.innerHTML = bodyText !== \'\'',
			'label' => 'preview_uses_innerHTML',
			'filter' => 'ArgusBachusMailSecurityContractTest::testMailPreviewBodiesStayTextContentOnly|ReviewInboxUxContractTest',
		],
		[
			'file' => $appRoot . '/js/common/api.js',
			'from' => "if (signal) {\n\t\t\topts.signal = signal;\n\t\t}",
			'to' => '/* signal wiring removed */',
			'label' => 'api_js_drops_abort_signal',
			'filter' => 'ArgusBachusMailSecurityContractTest::testMailPreviewAbortControllerCancelsPriorFetch|WorkspaceAclSurfaceContractTest::testApiJsPinsWorkspaceFromUrl',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'talkMailPreviewAbort.abort();',
			'to' => '/* talk abort dropped */;',
			'label' => 'talk_preview_abort_dropped',
			'filter' => 'ArgusBachusMailSecurityContractTest::testMailPreviewAbortControllerCancelsPriorFetch|ZeusBachusUxArchitectureContractTest::testTalkMailPreviewUsesGenerationAgainstArrowStampede',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);

	return (int) $code;
}

$mutations = argus_bachus_mail_security_mutations($appRoot);
$failed = 0;
$killed = 0;
$skipped = 0;

foreach ($mutations as $m) {
	$src = (string) file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "SKIP missing needle: {$m['label']}\n");
		$skipped++;
		continue;
	}
	$count = 0;
	$mutated = str_replace($m['from'], $m['to'], $src, $count);
	if ($count < 1) {
		fwrite(STDERR, "SKIP replace failed: {$m['label']}\n");
		$skipped++;
		continue;
	}
	file_put_contents($m['file'], $mutated);
	$code = run_phpunit($phpunit, $appRoot, $m['filter']);
	file_put_contents($m['file'], $src);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$m['label']}\n");
		$failed++;
	} else {
		fwrite(STDOUT, "KILLED  {$m['label']}\n");
		$killed++;
	}
}

$total = $killed + $failed;
$msi = $total > 0 ? round(100.0 * $killed / $total, 1) : 0.0;
fwrite(STDOUT, "MSI (argus-bachus-mail-security): {$msi}% killed={$killed} survived={$failed} skipped={$skipped}\n");
exit($failed > 0 || $skipped > 0 ? 1 : 0);
