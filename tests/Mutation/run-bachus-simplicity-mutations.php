<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: Bachus company / deal / log-email simplicity.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-bachus-simplicity-mutations.php
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
function bachus_simplicity_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/templates/company-form.php',
			'from' => '<details class="crm-advanced" id="crm-company-advanced">',
			'to' => '<details class="crm-advanced" id="crm-company-advanced"<?php if ($mode === \'edit\'): ?> open<?php endif; ?>>',
			'label' => 'company_edit_force_opens_advanced',
			'filter' => 'BachusSimplicityUxContractTest::testCompanyFormNeverForceOpensAdvancedOnEdit|ExtremeSimplicityUxContractTest::testCompanyFormDemotesSlugToAdvanced',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "name: 'notes',\n          label: t('Notes'),\n          type: 'textarea',\n          advanced: true,\n          maxlength: 4000,",
			'to' => "name: 'notes',\n          label: t('Notes'),\n          type: 'textarea',\n          maxlength: 4000,",
			'label' => 'add_deal_notes_leave_primary',
			'filter' => 'DealNotesUxContractTest::testCompanyDetailEditDealHandlerAndNotesFields',
		],
		[
			'file' => $appRoot . '/js/pages/pipeline.js',
			'from' => 'async function offerCreateCompanyFirst() {',
			'to' => "async function offerCreateCompanyFirstBroken() {\n    return;\n  }\n  async function offerCreateCompanyFirst() {\n    await C.alertDialog({ title: t('No companies yet'), message: t('Create a company first, then add a deal.'), variant: 'info' });\n    return;\n    // dead",
			'label' => 'new_deal_empty_reverts_to_alert_deadend',
			'filter' => 'NewDealDialogUxContractTest|BachusSimplicityUxContractTest::testPipelineEmptyStateOffersCreateCompanyWhenNoCompanies',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => "confirmLabel: t('Add person'),\n        cancelLabel: t('Not now'),",
			'to' => "confirmLabel: t('OK'),\n        cancelLabel: t('Cancel'),",
			'label' => 'log_email_loses_add_person_cta',
			'filter' => 'EmailsSectionContractTest::testLogDialogUsesPersonAndSubjectOnly',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'data-crm-review-quick-assign',
			'to' => 'data-crm-review-slow-assign',
			'label' => 'review_loses_one_click_suggest_assign',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'bulkBar.hidden = n === 0',
			'to' => 'bulkBar.hidden = false',
			'label' => 'review_bulk_bar_always_visible',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'crm-review-toolbar',
			'to' => 'crm-review-chrome',
			'label' => 'review_search_toolbar_renamed_away',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips|ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'data-crm-review-open',
			'to' => 'data-crm-review-peek',
			'label' => 'review_open_action_removed',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips|ReviewInboxUxContractTest::testSuiteTablePutsResponsiveClassOnTableAndActionCell',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'data-crm-review-sort',
			'to' => 'data-crm-review-order',
			'label' => 'review_sort_headers_removed',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips|ReviewInboxUxContractTest::testSuiteTablePutsResponsiveClassOnTableAndActionCell',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'crm-review-table',
			'to' => 'crm-review-dump',
			'label' => 'review_inbox_table_removed',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips|ReviewInboxUxContractTest::testSuiteTablePutsResponsiveClassOnTableAndActionCell',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'let reviewLoadGeneration = 0',
			'to' => 'let reviewLoadGenerationUnused = 0',
			'label' => 'review_load_generation_renamed',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'loadGen !== reviewLoadGeneration',
			'to' => 'loadGen !== reviewLoadGenerationUnused',
			'label' => 'review_load_generation_guard_broken',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'crm-review-search__field',
			'to' => 'crm-review-search__dump',
			'label' => 'review_search_field_layout_removed',
			'filter' => 'BachusSimplicityUxContractTest::testReviewUsesProgressivePrimaryAndKindChips|ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'dealField +',
			'to' => '/* dealField buried */ laterBlock + dealField +',
			'label' => 'review_deal_field_order_broken',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testDealFieldLivesWithAssignNotInsideSnooze',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'crm-sr-only crm-review-open',
			'to' => 'crm-btn crm-btn--ghost crm-btn--sm crm-review-open',
			'label' => 'review_open_button_visible_again',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testReviewOpenIsScreenReaderOnlyAndStatusSortGone',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "escapeHtml(t('Remove selected'))",
			'to' => "escapeHtml(t('Delete / remove selected'))",
			'label' => 'review_bulk_label_verbose_again',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testReviewOpenIsScreenReaderOnlyAndStatusSortGone',
		],
		[
			'file' => $appRoot . '/templates/company-detail.php',
			'from' => 'crm-person-more',
			'to' => 'crm-person-dump',
			'label' => 'people_roster_more_disclosure_removed',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testPeopleRosterIsThreeColumnsWithMoreDisclosure|ExtremeSimplicityUxContractTest|PersonsRowUxContractTest',
		],
		[
			'file' => $appRoot . '/templates/settings/workspace-mailbox.php',
			'from' => 'id="crm-ws-members-card"',
			'to' => 'id="crm-ws-members-card-moved"',
			'label' => 'mailbox_settings_members_card_id_broken',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testMailboxSettingsLeadWithMembersThenMailbox',
		],
		[
			'file' => $appRoot . '/templates/today.php',
			'from' => 'data-crm-complete-checklist="<?php p($checklistItemId); ?>"',
			'to' => 'data-crm-noop="<?php p($checklistItemId); ?>"',
			'label' => 'today_checklist_done_hook_removed',
			'filter' => 'BachusExtremeSimplifyUxContractTest::testTodayChecklistDoneHasNoSiblingOpen',
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

$mutations = bachus_simplicity_mutations($appRoot);
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
fwrite(STDOUT, "MSI (bachus-simplicity): {$msi}% killed={$killed} survived={$failed} skipped={$skipped}\n");
exit($failed > 0 || $skipped > 0 ? 1 : 0);
