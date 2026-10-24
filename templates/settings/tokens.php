<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
?>
<section class="crm-card" id="section-tokens" aria-labelledby="section-tokens-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-tokens-heading" class="crm-card__title"><?php p($l->t('Public feeds & intake')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Anyone with these URLs can read renewal titles or post website leads and orders. Rotate if a link leaks.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (!empty($settings['tokensVisible'])): ?>
				<div class="form-group">
					<label class="form-label" id="crm-ics-url-label" for="crm-ics-url"><?php p($l->t('Calendar feed for renewals')); ?></label>
					<div class="crm-token-row">
						<input id="crm-ics-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['icsFeedUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-ics-url" aria-describedby="crm-ics-url-label"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="ics" aria-describedby="crm-ics-url-label"><?php p($l->t('Rotate')); ?></button>
					</div>
					<p class="form-help"><?php p($l->t('Public calendar URL for agreement renewals. Rotate if the link leaks.')); ?></p>
				</div>
				<div class="form-group">
					<label class="form-label" id="crm-lead-url-label" for="crm-lead-url"><?php p($l->t('Website lead link')); ?></label>
					<div class="crm-token-row">
						<input id="crm-lead-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['leadWebhookUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-lead-url" aria-describedby="crm-lead-url-label"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="lead" aria-describedby="crm-lead-url-label"><?php p($l->t('Rotate')); ?></button>
					</div>
					<p class="form-help"><?php p($l->t('Website lead forms POST JSON to this URL. Rate limit: 30 requests per IP per 10 minutes.')); ?></p>
				</div>
				<div class="form-group">
					<label class="form-label" id="crm-order-url-label" for="crm-order-url"><?php p($l->t('Website order link')); ?></label>
					<div class="crm-token-row">
						<input id="crm-order-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['orderWebhookUrl'] ?? ''); ?>" autocomplete="off" spellcheck="false" aria-describedby="crm-order-url-help">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-order-url" aria-describedby="crm-order-url-label"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="order" aria-describedby="crm-order-url-label"><?php p($l->t('Rotate')); ?></button>
					</div>
					<p id="crm-order-url-help" class="form-help"><?php p($l->t('Licence orders from the website. Creates the company (or finds it by email), a deal, draft licences, and “Issue licence” / “Write invoice” tasks. On the website set customercheck_order_url to the part ending in /hooks/order and customercheck_order_token to the secret after /order/. Same rate limit as leads.')); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</section>
