<?php
/**
 * Checklist templates — reusable deal step lists (CUC-DEAL-CHECKLIST-1 / BQ-01 b).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="crm-card" id="section-checklists" aria-labelledby="section-checklists-heading">
	<header class="crm-card__header">
		<div class="crm-card__header-text">
			<h2 id="section-checklists-heading" class="crm-card__title"><?php p($l->t('Checklist templates')); ?></h2>
			<p class="crm-muted"><?php p($l->t('Make a step list once. On any deal, tap Checklist → From template — no typing the same handoffs again.')); ?></p>
		</div>
		<div class="crm-card__actions">
			<button type="button" class="crm-btn crm-btn--primary" id="crm-cl-tmpl-new"><?php p($l->t('New template')); ?></button>
		</div>
	</header>
	<div class="crm-card__body">
		<p id="crm-cl-tmpl-status" class="crm-muted" role="status" aria-live="polite"><?php p($l->t('Loading templates…')); ?></p>
		<p id="crm-cl-tmpl-error" class="form-error" role="alert" hidden></p>
		<ul id="crm-checklist-templates" class="crm-checklist-tmpl-list" aria-label="<?php p($l->t('Checklist templates')); ?>"></ul>
		<div class="crm-empty-state" id="crm-cl-tmpl-empty" role="status" hidden>
			<h3 class="crm-empty-state__title"><?php p($l->t('No custom templates yet')); ?></h3>
			<p class="crm-empty-state__text"><?php p($l->t('Built-in lists (license, consulting, project, hardware/goods) are ready. Add your own for mixed carts, or copy a built-in list and tweak it.')); ?></p>
		</div>
	</div>
</section>
