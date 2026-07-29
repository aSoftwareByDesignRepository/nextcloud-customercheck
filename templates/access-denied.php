<?php
/** @var array $_ */
$l = \OCP\Util::getL10N('customercheck');
$message = (string)($_['message'] ?? $l->t('You do not have access to CustomerCheck.'));
$homeUrl = (string)($_['homeUrl'] ?? '/');
\OCP\Util::addStyle('customercheck', 'app');
?>
<div id="app-content" class="crm-app crm-app--access-denied">
	<div class="crm-shell crm-shell--minimal">
		<section class="crm-card" role="alert" aria-labelledby="crm-denied-title">
			<h1 id="crm-denied-title"><?php p($l->t('Access denied')); ?></h1>
			<p><?php p($message); ?></p>
			<a class="crm-btn crm-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
		</section>
	</div>
</div>
