<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: narrative prompt fields must stay textareas (not tiny one-line inputs).
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-prompt-field-control-mutations.php
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
function prompt_field_control_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "name: 'title',\n          label: t('What should you remember?'),\n          type: 'textarea',\n          rows: 2,\n          required: true,\n          maxlength: 255,",
			'to' => "name: 'title',\n          label: t('What should you remember?'),\n          required: true,\n          maxlength: 255,",
			'label' => 'add_todo_title_reverts_to_text',
			'filter' => 'PromptFieldControlUxContractTest::testNarrativeTitlesUseTextareaNotBareTextInput',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "name: 'title',\n          label: t('What did you tell them?'),\n          type: 'textarea',\n          rows: 3,\n          required: true,\n          maxlength: 255,",
			'to' => "name: 'title',\n          label: t('Title'),\n          required: true,",
			'label' => 'promise_title_reverts_to_bare_text',
			'filter' => 'PromptFieldControlUxContractTest::testNarrativeTitlesUseTextareaNotBareTextInput',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "name: 'topic',\n        label: t('Topic'),\n        type: 'textarea',\n        rows: 2,\n        required: true,\n        maxlength: 255,",
			'to' => "name: 'topic', label: t('Topic'), required: true,",
			'label' => 'log_hours_topic_reverts_to_text',
			'filter' => 'PromptFieldControlUxContractTest::testNarrativeTitlesUseTextareaNotBareTextInput',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "name: 'title',\n          label: t('What needs doing?'),\n          type: 'textarea',\n          rows: 2,\n          required: true,\n          maxlength: 255,",
			'to' => "name: 'title', label: t('What needs doing?'), type: 'text', required: true,",
			'label' => 'new_task_title_reverts_to_text',
			'filter' => 'PromptFieldControlUxContractTest::testNarrativeTitlesUseTextareaNotBareTextInput',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "html += '<textarea class=\"crm-input form-input form-textarea\" id=\"' + id + '-' + fname + '\" name=\"' + fname + '\" rows=\"' + rows + '\"' +",
			'to' => "html += '<textarea class=\"crm-input form-input\" id=\"' + id + '-' + fname + '\" name=\"' + fname + '\" rows=\"3\"' +",
			'label' => 'prompt_renderer_drops_rows_and_form_textarea',
			'filter' => 'PromptFieldControlUxContractTest::testPromptRendererSupportsTextareaRowsAndFormTextareaClass',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "white-space: pre-wrap;\n  overflow-wrap: anywhere;",
			'to' => "/* mutated: no multiline wrap */",
			'label' => 'todo_title_loses_multiline_wrap',
			'filter' => 'PromptFieldControlUxContractTest::testTodoListTitlesWrapMultilinePromptText',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

$mutations = prompt_field_control_mutations($appRoot);
$failed = 0;

fwrite(STDOUT, "Baseline PromptFieldControlUxContractTest…\n");
if (run_phpunit($phpunit, $appRoot, 'PromptFieldControlUxContractTest') !== 0) {
	fwrite(STDERR, "Baseline failed — aborting mutations.\n");
	exit(1);
}

foreach ($mutations as $m) {
	$orig = (string)file_get_contents($m['file']);
	if (!str_contains($orig, $m['from'])) {
		fwrite(STDERR, "SKIP/FAIL {$m['label']}: from-string not found in {$m['file']}\n");
		$failed++;
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $orig));
	fwrite(STDOUT, "Mutant {$m['label']}…\n");
	$code = run_phpunit($phpunit, $appRoot, $m['filter']);
	file_put_contents($m['file'], $orig);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED {$m['label']} — contract did not catch mutant.\n");
		$failed++;
	} else {
		fwrite(STDOUT, "Killed {$m['label']}.\n");
	}
}

if ($failed > 0) {
	fwrite(STDERR, "{$failed} mutation(s) failed.\n");
	exit(1);
}

fwrite(STDOUT, "All prompt-field-control mutants killed.\n");
exit(0);
