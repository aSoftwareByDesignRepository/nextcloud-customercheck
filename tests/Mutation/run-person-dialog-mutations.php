<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Add person native dialog (host/inert, save-dismiss, Contacts race).
 *
 * Host (from customercheck/):
 *   php tests/Mutation/run-person-dialog-mutations.php
 *
 * Docker (from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-person-dialog-mutations.php
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
 * @return list<array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string}>
 */
function person_dialog_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => "if (name === '') {\n      return { ok: false, code: 'name', name: name, email: email, phone: phone };\n    }",
			'to' => "if (false && name === '') {\n      return { ok: false, code: 'name', name: name, email: email, phone: phone };\n    }",
			'label' => 'validate_allows_empty_name',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => 'if (email !== \'\' && !EMAIL_RE.test(email)) {',
			'to' => 'if (email !== \'\' && false && !EMAIL_RE.test(email)) {',
			'label' => 'validate_skips_email',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => 'if (phone !== \'\' && !PHONE_RE.test(phone)) {',
			'to' => 'if (phone !== \'\' && false && !PHONE_RE.test(phone)) {',
			'label' => 'validate_skips_phone',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => "return mode === 'add' && saveBusy !== true && dialogOpen === true;",
			'to' => "return mode === 'add' && saveBusy !== true;",
			'label' => 'suggest_ignores_dialog_open',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => 'return saveBusy === true;',
			'to' => 'return false;',
			'label' => 'never_blocks_dismiss_during_save',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => "if (uid !== '') {\n      payload.contactsUid = uid;\n    }",
			'to' => "if (false && uid !== '') {\n      payload.contactsUid = uid;\n    }",
			'label' => 'create_drops_contacts_uid',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => 'if (dialog.parentElement === body) {',
			'to' => 'if (false && dialog.parentElement === body) {',
			'label' => 'host_reappends_every_call',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => "if (dialog.open === true) {\n      return true;\n    }",
			'to' => "if (false && dialog.open === true) {\n      return true;\n    }",
			'label' => 'open_ignores_dialog_open_flag',
			'kind' => 'vitest',
			'vitest' => 'tests/js/person-dialog-core.test.js',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => '<dialog id="crm-person-dialog"',
			'to' => '<div id="crm-person-dialog"',
			'label' => 'markup_loses_native_dialog',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testMarkupIsANativePersistDialogInsidePeople',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'data-azc-modal-persist="true"',
			'to' => 'data-azc-modal-persist="false"',
			'label' => 'markup_drops_persist',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testMarkupIsANativePersistDialogInsidePeople',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'aria-controls="crm-person-dialog"',
			'to' => 'aria-controls="crm-person-form"',
			'label' => 'add_button_controls_form_not_dialog',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testMarkupIsANativePersistDialogInsidePeople',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => 'C.openModal(dlg);',
			'to' => 'void dlg;',
			'label' => 'js_skips_openModal',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testPageScriptOpensNativeModalAndBlocksInlineToggle',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "if (isPersonDialogOpen()) return;",
			'to' => 'if (false && isPersonDialogOpen()) return;',
			'label' => 'escape_steals_native_dialog_cancel',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testPageScriptOpensNativeModalAndBlocksInlineToggle',
		],
		[
			'file' => $appRoot . '/js/common/person-dialog-core.js',
			'from' => "azcModalPersist = 'true'",
			'to' => "azcModalPersist = 'false'",
			'label' => 'core_persist_false',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testCoreEncodesSaveAndHostInvariants',
		],
		[
			'file' => $appRoot . '/css/common/dialog-forms.css',
			'from' => 'dialog.crm-native-dialog.crm-dialog--content .crm-input--lg,',
			'to' => 'dialog.crm-native-dialog.crm-dialog--content .crm-input--sm,',
			'label' => 'css_drops_body_hosted_lg_inputs',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testDialogFormCssSurvivesBodyHost',
		],
		[
			'file' => $appRoot . '/lib/Controller/PageController.php',
			'from' => "'common/person-dialog-core', ",
			'to' => '',
			'label' => 'page_skips_core_script',
			'kind' => 'phpunit',
			'filter' => 'PersonDialogUxContractTest::testAssetsLoadCoreBeforeCompanyDetail',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string} $m
 */
function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Needle not found for ' . $m['label'] . ' in ' . $m['file']);
	}
	$mutated = str_replace($m['from'], $m['to'], $src, $count);
	if ($count !== 1) {
		throw new RuntimeException('Expected 1 replace for ' . $m['label'] . ', got ' . $count);
	}
	file_put_contents($m['file'], $mutated);

	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

/**
 * Prefer Docker PHPUnit when the host cannot reach the NC DB (bootstrap.php loads OC).
 */
function person_dialog_phpunit_command(string $phpunit, string $appRoot, string $filter): string
{
	$composeDir = dirname($appRoot, 2);
	$dockerPhpunit = '/var/www/html/custom_apps/customercheck/vendor/bin/phpunit';
	$dockerConfig = '/var/www/html/custom_apps/customercheck/phpunit.xml';
	if (is_file($composeDir . '/docker-compose.yml')) {
		$probe = 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";');
		exec($probe . ' 2>/dev/null', $probeOut, $probeCode);
		if ($probeCode === 0) {
			return 'docker compose -f ' . escapeshellarg($composeDir . '/docker-compose.yml')
				. ' exec -T -u www-data nextcloud php ' . escapeshellarg($dockerPhpunit)
				. ' --configuration ' . escapeshellarg($dockerConfig)
				. ' --filter ' . escapeshellarg($filter);
		}
	}

	return escapeshellarg($phpunit)
		. ' --configuration ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
}

/**
 * @param array{file:string,from:string,to:string,label:string,kind:string,filter?:string,vitest?:string} $m
 */
function run_kill_check(string $appRoot, string $phpunit, array $m): bool
{
	if ($m['kind'] === 'vitest') {
		$cmd = 'npm run test:js -- --run ' . escapeshellarg((string)$m['vitest']) . ' 2>&1';
		$cwd = $appRoot;
	} else {
		$filter = (string)($m['filter'] ?? 'PersonDialogUxContractTest');
		$cmd = person_dialog_phpunit_command($phpunit, $appRoot, $filter) . ' 2>&1';
		$cwd = $appRoot;
	}
	$des = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
	$proc = proc_open($cmd, $des, $pipes, $cwd);
	if (!is_resource($proc)) {
		return false;
	}
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);
	return $code !== 0;
}

$mutations = person_dialog_mutations($appRoot);
$failed = [];
$killed = 0;

echo 'Person dialog mutation gauntlet (' . count($mutations) . " mutants)\n";

foreach ($mutations as $m) {
	$label = $m['label'];
	echo "— {$label} … ";
	$original = null;
	try {
		$original = apply_mutation($m);
		$dead = run_kill_check($appRoot, $phpunit, $m);
		restore_file($m['file'], $original);
		if ($dead) {
			echo "KILLED\n";
			$killed++;
		} else {
			echo "SURVIVED\n";
			$failed[] = $label;
		}
	} catch (Throwable $e) {
		if ($original !== null) {
			restore_file($m['file'], $original);
		}
		echo 'ERROR: ' . $e->getMessage() . "\n";
		$failed[] = $label . ' (error)';
	}
}

echo "\nKilled {$killed}/" . count($mutations) . "\n";
if ($failed !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $failed) . "\n");
	exit(1);
}

echo "Person dialog mutation gauntlet OK.\n";
exit(0);
