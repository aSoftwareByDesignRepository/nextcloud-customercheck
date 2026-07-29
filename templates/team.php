<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-team-root">
	<section class="crm-card" id="crm-team-board" aria-labelledby="crm-team-board-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-team-board-title" class="crm-card__title"><?php p($l->t('Who needs help')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Green = fine. Amber = coach. Open a row for 1:1 prep.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<div
				class="crm-skeleton crm-persona-mount"
				data-crm-persona-mount="team"
				aria-busy="true"
				aria-live="polite">
				<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
			</div>
		</div>
	</section>

	<section class="crm-card" id="capacity" tabindex="-1" aria-labelledby="crm-capacity-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-capacity-title" class="crm-card__title"><?php p($l->t('Capacity tools')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Cover absences, move ownership, or escalate when someone is stuck.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<div
				class="crm-team-capacity"
				data-crm-team-capacity
				aria-live="polite">
				<p class="crm-muted"><?php p($l->t('Loading…')); ?></p>
			</div>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
