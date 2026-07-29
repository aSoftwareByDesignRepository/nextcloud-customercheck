<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-mail-triage-root">
	<section class="crm-card" aria-labelledby="crm-mail-triage-board-title">
		<header class="crm-card__header">
			<h2 id="crm-mail-triage-board-title" class="crm-card__title"><?php p($l->t('Unmatched mail')); ?></h2>
			<p class="crm-card__lead"><?php p($l->t('Assign unmatched shared mailbox messages.')); ?></p>
		</header>
		<div class="crm-card__body">
			<p class="crm-callout" role="status"><?php p($l->t('These messages come from the shared mailbox. Everyone in this workspace who can read mail can see them.')); ?></p>
			<div
				class="crm-skeleton crm-persona-mount"
				data-crm-persona-mount="mail-triage"
				aria-busy="true"
				aria-live="polite">
				<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
			</div>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
