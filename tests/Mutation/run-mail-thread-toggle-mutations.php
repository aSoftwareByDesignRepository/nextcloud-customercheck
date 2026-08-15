<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: mail thread second-click collapse.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mail-thread-toggle-mutations.php
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
function mail_thread_toggle_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "if (li.classList.contains('is-selected')) {\n                  closeTalkReader(li);\n                  return;\n                }",
			'to' => '',
			'label' => 'drop_company_toggle_close',
			'filter' => 'MailThreadToggleCloseUxContractTest::testCompanyTalkFeedTogglesCloseOnSecondActivation',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "if (!(opts && opts.forceOpen) && selectedId === String(id)) {\n        clearReviewSelection(opts);\n        return;\n      }",
			'to' => '',
			'label' => 'drop_review_toggle_close',
			'filter' => 'MailThreadToggleCloseUxContractTest::testReviewInboxTogglesCloseOnSecondActivation',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "if (id === '' && firstVisible) {\n        firstVisible.tabIndex = 0;\n      }",
			'to' => '',
			'label' => 'drop_collapsed_tabindex_restore',
			'filter' => 'MailThreadToggleCloseUxContractTest::testReviewInboxTogglesCloseOnSecondActivation',
		],
	];
}

/**
 * @param array{file:string,from:string,to:string,label:string,filter:string} $m
 */
function apply_mutation(array $m): string
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('Mutation anchor missing for ' . $m['label']);
	}
	$next = str_replace($m['from'], $m['to'], $src);
	if ($next === $src) {
		throw new RuntimeException('Mutation did not change file for ' . $m['label']);
	}
	file_put_contents($m['file'], $next);
	return $src;
}

function restore_file(string $file, string $original): void
{
	file_put_contents($file, $original);
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = mail_thread_toggle_mutations($appRoot);
$caught = 0;
$failed = [];
echo 'Mail-thread toggle mutations: ' . count($mutations) . "\n";
foreach ($mutations as $m) {
	echo "\n=== MUTATION: {$m['label']} ===\n";
	$original = apply_mutation($m);
	try {
		$code = run_phpunit($phpunit, $appRoot, $m['filter']);
		if ($code === 0) {
			$failed[] = $m['label'] . ' (survived)';
			echo "SURVIVED (bad)\n";
		} else {
			$caught++;
			echo "CAUGHT (good)\n";
		}
	} finally {
		restore_file($m['file'], $original);
	}
}
echo "\nResult: {$caught}/" . count($mutations) . " caught\n";
if ($failed !== []) {
	fwrite(STDERR, "Survivors:\n- " . implode("\n- ", $failed) . "\n");
	exit(1);
}
exit(0);
