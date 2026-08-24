<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for Team capacity accordion UX.
 */

$appRoot = dirname(__DIR__, 2);
$pageFile = $appRoot . '/js/pages/persona.js';
$uiFile = $appRoot . '/js/common/team-capacity-ui.js';
$cssFile = $appRoot . '/css/app.css';

$vitest = 'cd ' . escapeshellarg($appRoot) . ' && npm run test:js -- tests/js/team-capacity-ux.test.js tests/js/team-capacity-ui.test.js tests/js/datepicker-wrap.test.js 2>&1';
$phpunit = 'cd ' . escapeshellarg(dirname($appRoot, 2)) . ' && docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/vendor/bin/phpunit -c /var/www/html/custom_apps/customercheck/phpunit.xml --filter TeamUxContractTest 2>&1';

function runCmd(string $cmd): bool
{
	exec($cmd, $out, $code);
	return $code === 0;
}

function applyMutant(string $file, string $find, string $replace): ?string
{
	$original = file_get_contents($file);
	if ($original === false || !str_contains($original, $find)) {
		return null;
	}
	file_put_contents($file, str_replace($find, $replace, $original));
	return $original;
}

$mutants = [
	[
		'file' => $pageFile,
		'label' => 'bulk step stays open by default',
		'find' => "        false,\n      );",
		'replace' => "        true,\n      );",
	],
	[
		'file' => $pageFile,
		'label' => 'accordion panels removed',
		'find' => "'<details class=\"crm-team-capacity__panel\"'",
		'replace' => "'<section class=\"crm-team-capacity__panel\"'",
	],
	[
		'file' => $pageFile,
		'label' => 'exclusive accordion wiring removed',
		'find' => "    if (typeof capacityUi.bindExclusiveAccordion === 'function') {\n      capacityUi.bindExclusiveAccordion(capacityHost, 0);\n    }\n",
		'replace' => "    /* exclusive accordion removed */\n",
	],
	[
		'file' => $uiFile,
		'label' => 'ooo client validation removed',
		'find' => 'validateOooCoverInput: validateOooCoverInput,',
		'replace' => 'validateOooCoverInput: null,',
	],
	[
		'file' => $uiFile,
		'label' => 'exclusive accordion closes siblings',
		'find' => "            other.open = false;\n",
		'replace' => "            other.open = true;\n",
	],
	[
		'file' => $cssFile,
		'label' => 'ooo datepicker row alignment removed',
		'find' => "\talign-items: stretch;\n\twidth: 100%;\n\tmax-width: 100%;\n}\n#app-content.crm-app .crm-team-capacity__dates .crm-datepicker-host .datepicker-input",
		'replace' => "\talign-items: flex-start;\n\twidth: auto;\n\tmax-width: none;\n}\n#app-content.crm-app .crm-team-capacity__dates .crm-datepicker-host .datepicker-input",
	],
	[
		'file' => $cssFile,
		'label' => 'full-width stack removed',
		'find' => "\tmax-width: none;\n",
		'replace' => "\tmax-width: 36rem;\n",
	],
];

echo "Baseline…\n";
if (!runCmd($vitest) || !runCmd($phpunit)) {
	fwrite(STDERR, "Baseline failed.\n");
	exit(1);
}

$killed = 0;
foreach ($mutants as $mutant) {
	$original = applyMutant($mutant['file'], $mutant['find'], $mutant['replace']);
	if ($original === null) {
		fwrite(STDERR, "Needle missing: {$mutant['label']}\n");
		exit(1);
	}
	$survived = runCmd($vitest) && runCmd($phpunit);
	file_put_contents($mutant['file'], $original);
	if ($survived) {
		echo "SURVIVED: {$mutant['label']}\n";
	} else {
		echo "KILLED: {$mutant['label']}\n";
		$killed += 1;
	}
}

$total = count($mutants);
echo "Mutation score: {$killed}/{$total} killed\n";
exit($killed < $total ? 1 : 0);
