<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-strategy-root" data-crm-strategy-filter="all">
	<section class="crm-card" id="crm-strategy-board" aria-labelledby="crm-strategy-board-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-strategy-board-title" class="crm-card__title"><?php p($l->t('Account plans')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Goals and health for every customer with a plan. Open a company to change the full plan.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<div
				class="crm-skeleton crm-persona-mount"
				data-crm-persona-mount="strategy"
				aria-busy="true"
				aria-live="polite">
				<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
			</div>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
