<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<section class="crm-card" id="section-circles-roll-call" aria-labelledby="section-circles-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-circles-heading" class="crm-card__title"><?php p($l->t('Weekly promise check')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Did the team keep its weekly promises?')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php include __DIR__ . '/../parts/workspace-boot-status.php'; ?>
			<div id="crm-circles-roll-call" class="crm-skeleton" aria-busy="true" aria-live="polite">
				<p><?php p($l->t('Loading…')); ?></p>
			</div>
		</div>
	</section>
