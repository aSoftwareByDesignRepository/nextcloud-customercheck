<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$companyId = (int)($_['companyId'] ?? 0);
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-prepare-root">
	<div
		class="crm-skeleton crm-persona-mount"
		data-crm-persona-mount="prepare-meeting"
		data-company-id="<?php p((string)$companyId); ?>"
		aria-busy="true"
		aria-live="polite">
		<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
