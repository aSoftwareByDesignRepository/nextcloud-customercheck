<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-actions-source header-actions" hidden>
	<?php if (!empty($_['canWrite'])): ?>
	<a class="crm-btn crm-btn--primary" id="crm-my-accounts-add" href="<?php p($_['urls']['companyNew'] ?? '#'); ?>"><?php p($l->t('Add customer')); ?></a>
	<?php endif; ?>
</div>
<div class="crm-page-stack" id="crm-my-accounts-root">
	<div
		class="crm-skeleton crm-persona-mount"
		data-crm-persona-mount="my-accounts"
		aria-busy="true"
		aria-live="polite">
		<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
