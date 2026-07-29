<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
?>
<section class="crm-card" id="section-preferences" aria-labelledby="section-preferences-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-preferences-heading" class="crm-card__title"><?php p($l->t('Your preferences')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Personal choices that do not affect other users.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<form id="crm-settings-me-form" class="crm-form">
				<div class="form-group">
					<label for="crm-pipeline-view" class="form-label"><?php p($l->t('Default pipeline view')); ?></label>
					<select id="crm-pipeline-view" name="pipelineView" class="form-select crm-input">
						<option value="table" <?php if (($settings['pipelineView'] ?? 'table') === 'table'): ?>selected<?php endif; ?>><?php p($l->t('Table')); ?></option>
						<option value="board" <?php if (($settings['pipelineView'] ?? '') === 'board'): ?>selected<?php endif; ?>><?php p($l->t('Board')); ?></option>
					</select>
				</div>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Save preference')); ?></button>
				</div>
			</form>
		</div>
	</section>
