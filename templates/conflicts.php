<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-conflicts-root">
	<p class="crm-callout" role="status" id="crm-conflicts-lead"><?php p($l->t('Two people changed the same thing. Keep what’s saved is already selected — add a short reason and Resolve.')); ?></p>
	<div
		class="crm-skeleton crm-persona-mount"
		data-crm-persona-mount="conflicts"
		aria-busy="true"
		aria-live="polite">
		<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
