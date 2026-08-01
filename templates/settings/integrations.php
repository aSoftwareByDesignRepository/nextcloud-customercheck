<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<section class="crm-card" id="section-integrations" aria-labelledby="section-integrations-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-integrations-heading" class="crm-card__title"><?php p($l->t('Integrations')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Sibling apps are read-only. Missing apps hide their panel without breaking pages.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<ul class="crm-simple-list">
			<?php foreach (($_['capabilities'] ?? []) as $app => $ok): ?>
				<li>
					<strong><?php p($app); ?></strong>
					· <span class="crm-badge crm-badge--<?php p($ok ? 'success' : 'neutral'); ?>"><?php p($ok ? $l->t('available') : $l->t('not available')); ?></span>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
	</section>
