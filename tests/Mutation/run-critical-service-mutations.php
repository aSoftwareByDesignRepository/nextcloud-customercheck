<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$cases = [

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
		'file' => $root . '/lib/Service/EmailOverviewPresenter.php',
		'find' => "if (\$direction === 'inbound') {\n\t\t\treturn \$from !== '' ? \$from : \$to;\n\t\t}\n\t\treturn \$to !== '' ? \$to : \$from;",
		'replace' => "if (\$direction === 'inbound') {\n\t\t\treturn \$to !== '' ? \$to : \$from;\n\t\t}\n\t\treturn \$from !== '' ? \$from : \$to;",
		'test' => 'EmailOverviewPresenterTest',
		'label' => 'withWhom swaps inbound/outbound counterpart',
	],
];

$phpunit = $root . '/vendor/bin/phpunit';
$failed = 0;
$killed = 0;

foreach ($cases as $case) {
	$original = file_get_contents($case['file']);
	if (!str_contains($original, $case['find'])) {
		fwrite(STDERR, "SKIP missing needle: {$case['label']}\n");
		$failed++;
		continue;
	}
	file_put_contents($case['file'], str_replace($case['find'], $case['replace'], $original, $count));
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' --filter ' . escapeshellarg($case['test']);
	exec($cmd . ' 2>&1', $out, $code);
	file_put_contents($case['file'], $original);
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
