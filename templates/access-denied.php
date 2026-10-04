<?php
/** @var array $_ */
$l = \OCP\Util::getL10N('customercheck');
$message = (string)($_['message'] ?? $l->t('You do not have access to CustomerCheck.'));
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
\OCP\Util::addStyle('customercheck', 'app');
?>
<div id="app-content" class="crm-app crm-app--access-denied">
	<main id="crm-denied-main" class="crm-shell crm-shell--minimal" tabindex="-1" aria-labelledby="crm-denied-title">
		<section class="crm-card crm-access-denied" role="alert">
			<h1 id="crm-denied-title"><?php p($l->t('Access denied')); ?></h1>
			<p class="crm-access-denied__message"><?php p($message); ?></p>
			<?php if ($hint !== ''): ?>
				<p class="crm-access-denied__hint"><?php p($hint); ?></p>
			<?php endif; ?>
			<p class="crm-access-denied__actions">
				<a class="crm-btn crm-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
			</p>
		</section>
	</main>
</div>
