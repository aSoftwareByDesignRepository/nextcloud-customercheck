<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
?>
<section class="crm-card" id="section-tokens" aria-labelledby="section-tokens-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-tokens-heading" class="crm-card__title"><?php p($l->t('Public feeds & intake')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Anyone with these URLs can read renewal titles or post leads and licence orders. Rotate if a token leaks.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (!empty($settings['tokensVisible'])): ?>
				<div class="form-group">
					<label class="form-label" for="crm-ics-url"><?php p($l->t('ICS renewal feed')); ?></label>
					<div class="crm-token-row">
						<input id="crm-ics-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['icsFeedUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-ics-url"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="ics"><?php p($l->t('Rotate')); ?></button>
					</div>
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-lead-url"><?php p($l->t('Lead webhook')); ?></label>
					<div class="crm-token-row">
						<input id="crm-lead-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['leadWebhookUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-lead-url"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="lead"><?php p($l->t('Rotate')); ?></button>
					</div>
					<p class="form-help"><?php p($l->t('Website lead forms POST JSON to this URL. Rate limit: 30 requests per IP per 10 minutes.')); ?></p>
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-order-url"><?php p($l->t('Website order webhook')); ?></label>
					<div class="crm-token-row">
						<input id="crm-order-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['orderWebhookUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-order-url"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="order"><?php p($l->t('Rotate')); ?></button>
					</div>
					<p class="form-help"><?php p($l->t('Licence order form dual-write. Creates draft agreements plus “Issue licence” and “Write invoice” tasks. Same rate limit as leads.')); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</section>
