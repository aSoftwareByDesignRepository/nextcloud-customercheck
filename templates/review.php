<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
$reviewKind = (string)($_['reviewKind'] ?? '');
?>
<div class="crm-page-stack" id="crm-review-root">
	<p class="crm-callout" role="status" id="crm-review-disclosure"><?php p($l->t('These items come from sources your team turned on. Everyone with Assign access can see them.')); ?></p>
	<div
		class="crm-skeleton crm-persona-mount"
		data-crm-persona-mount="review"
		data-crm-review-kind="<?php p($reviewKind); ?>"
		aria-busy="true"
		aria-live="polite">
		<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
