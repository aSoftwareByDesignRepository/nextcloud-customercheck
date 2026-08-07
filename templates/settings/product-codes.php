<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$canManage = !empty($_['canManage']);
?>
<section class="crm-card" id="section-product-codes" aria-labelledby="section-product-codes-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-product-codes-heading" class="crm-card__title"><?php p($l->t('Agreement catalogue')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Built-in product codes stay available. Add custom codes (lowercase letters, digits, hyphens) for your own packages.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<p class="crm-muted" id="crm-product-codes-status" aria-live="polite"><?php p($l->t('Loading catalogue…')); ?></p>
			<ul class="crm-simple-list" id="crm-product-codes-list" aria-label="<?php p($l->t('Product codes')); ?>"></ul>
			<?php if ($canManage): ?>
			<form id="crm-product-codes-form" class="crm-form" novalidate>
				<div class="form-group">
					<label class="form-label" for="crm-product-code-new"><?php p($l->t('New custom code')); ?></label>
					<input type="text" id="crm-product-code-new" class="form-input crm-input" maxlength="32" pattern="[a-z0-9-]{2,32}"
						placeholder="<?php p($l->t('e.g. workshop-half')); ?>" autocomplete="off" spellcheck="false">
					<p class="form-help"><?php p($l->t('2–32 characters: a–z, 0–9, hyphen.')); ?></p>
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-product-code-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="crm-product-code-label" class="form-input crm-input" maxlength="80"
						placeholder="<?php p($l->t('Shown on agreements')); ?>">
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-product-code-kind"><?php p($l->t('Type')); ?></label>
					<select id="crm-product-code-kind" class="form-select crm-input">
						<option value="onetime"><?php p($l->t('One-time')); ?></option>
						<option value="license"><?php p($l->t('License')); ?></option>
						<option value="partner"><?php p($l->t('Partner')); ?></option>
					</select>
				</div>
				<div class="form-group">
					<label class="crm-switch">
						<input type="checkbox" id="crm-product-code-active" checked>
						<span><?php p($l->t('Active for new agreements')); ?></span>
					</label>
				</div>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Add custom code')); ?></button>
					<button type="button" class="crm-btn crm-btn--secondary" id="crm-product-codes-save"><?php p($l->t('Save catalogue changes')); ?></button>
				</div>
				<p id="crm-product-codes-error" class="form-error" role="alert" hidden></p>
				<p class="form-help"><?php p($l->t('Custom codes can stay inactive for old agreements while disappearing from new forms.')); ?></p>
			</form>
			<?php endif; ?>
		</div>
	</section>
