<?php
/**
 * Deal freshness meta — age, last change, stage idle, follow-up badge.
 *
 * @var array $d deal row (ageDays, idleDays, stageIdleDays, stale/rotting)
 * @var \OCP\IL10N $l
 * @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels
 */
$freshLine = '';
if ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
	$freshLine = $labels->dealFreshnessLine($d);
} else {
	$bits = [];
	if (isset($d['ageDays']) && is_int($d['ageDays'])) {
		$bits[] = $l->n('Open %n day', 'Open %n days', (int)$d['ageDays']);
	}
	if (isset($d['idleDays']) && is_int($d['idleDays'])) {
		$bits[] = $l->n('Changed %n day ago', 'Changed %n days ago', (int)$d['idleDays']);
	}
	if (isset($d['stageIdleDays']) && is_int($d['stageIdleDays'])) {
		$bits[] = $l->n('%n day in stage', '%n days in stage', (int)$d['stageIdleDays']);
	}
	$freshLine = implode(' · ', $bits);
}
$needsFollowUp = !empty($d['stale']) || !empty($d['rotting']);
$stageIdleTitle = isset($d['stageIdleDays']) && is_int($d['stageIdleDays']) && (int)$d['stageIdleDays'] > 0
	? $l->n('No stage change for %n day', 'No stage change for %n days', (int)$d['stageIdleDays'])
	: $l->t('No stage change for a while');
?>
<?php if ($needsFollowUp): ?>
	<span class="crm-badge crm-badge--warning" title="<?php p($stageIdleTitle); ?>"><?php p($l->t('Needs follow-up')); ?></span>
<?php endif; ?>
<?php if ($freshLine !== ''): ?>
	<p class="crm-muted crm-deal-freshness" data-crm-deal-freshness><?php p($freshLine); ?></p>
<?php endif; ?>
