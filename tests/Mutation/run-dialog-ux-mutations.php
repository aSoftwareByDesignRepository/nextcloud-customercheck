<?php

declare(strict_types=1);

/**
 * Focused dialog/modal mutants — DialogUxContractTest + DesignSystemAlignment.
 */

$root = dirname(__DIR__, 2);

$cases = [
	[
		'file' => $root . '/js/common/components.js',
		'find' => "dialog.setAttribute('aria-modal', 'true');",
		'replace' => "/* mutated: no aria-modal on confirm */",
		'test' => 'DialogUxContractTest',
		'label' => 'confirm dialog drops aria-modal',
	],
	[
		'file' => $root . '/js/common/components.js',
		'find' => "if (!modal.classList.contains('confirm-dialog')) {\n        this._closeOtherContentDialogs(modal);\n      }",
		'replace' => "/* mutated: no single-flight */",
		'test' => 'DialogUxContractTest',
		'label' => 'content dialogs lose single-flight close',
	],
	[
		'file' => $root . '/js/utils/dialog-present.js',
		'find' => "if (lower.startsWith('javascript:') || lower.startsWith('data:') || lower.startsWith('vbscript:')) {\n    return fallback;\n  }",
		'replace' => "/* mutated: no scheme block */",
		'test' => 'DialogUxContractTest',
		'label' => 'safeExternalHref allows javascript URLs',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => 'const flabel = escapeHtml(f.label || fname);',
		'replace' => 'const flabel = f.label || fname;',
		'test' => 'DialogUxContractTest',
		'label' => 'promptFields label drops escapeHtml',
	],
	[
		'file' => $root . '/js/pages/pipeline.js',
		'find' => "t('Win chance')",
		'replace' => "t('Forecast')",
		'test' => 'DialogUxContractTest',
		'label' => 'edit deal reintroduces Forecast jargon',
	],
	[
		'file' => $root . '/lib/Service/FrontEndAssetService.php',
		'find' => "Util::addScript(Application::APP_ID, 'common/dialog-present');\n\t\tUtil::addScript(Application::APP_ID, 'common/company-picker');\n\t\tUtil::addScript(Application::APP_ID, 'common/components');",
		'replace' => "Util::addScript(Application::APP_ID, 'common/components');",
		'test' => 'DialogUxContractTest',
		'label' => 'core assets drop dialog-present',
	],
	[
		'file' => $root . '/js/common/components.js',
		'find' => "const dialog = document.createElement('dialog');\n      dialog.className = 'crm-native-dialog modal modal--sm confirm-dialog crm-dialog';",
		'replace' => "const dialog = document.createElement('div');\n      dialog.className = 'modal modal--sm confirm-dialog crm-dialog';",
		'test' => 'DesignSystemAlignmentContractTest',
		'label' => 'confirm dialog reverts to div modal',
	],
	[
		'file' => $root . '/js/common/components.js',
		'find' => 'crm-dialog crm-dialog--content',
		'replace' => 'crm-dialog',
		'test' => 'DialogUxContractTest',
		'label' => 'createModal drops content polish class',
	],
	[
		'file' => $root . '/js/common/shared-mail-send.js',
		'find' => "class=\"crm-form crm-form--stack\"",
		'replace' => "class=\"crm-form\"",
		'test' => 'DialogUxContractTest',
		'label' => 'mailbox pick dialog drops form stack',
	],
	[
		'file' => $root . '/js/pages/company-detail.js',
		'find' => "type: 'company'",
		'replace' => "type: 'select'",
		'test' => 'DialogUxContractTest',
		'label' => 'merge dialog reverts to mega-select type',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';
$failed = 0;
$killed = 0;
$pending = [];
$restoreAll = static function () use (&$pending): void {
	foreach ($pending as $path => $contents) {
		// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
		// a kill-test that is red unmutated turns nonzero exits into fake kills.
		require_once __DIR__ . '/MutationBaseline.php';
		mutationBaselineSelfCheck(__FILE__);
		file_put_contents($path, $contents);
	}
	$pending = [];
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
echo "\nMSI (dialog-focused): {$msi}% ({$killed}/{$total} killed)\n";
exit($failed === 0 && $killed > 0 ? 0 : 1);
