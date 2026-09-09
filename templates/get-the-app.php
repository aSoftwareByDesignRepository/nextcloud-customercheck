<?php

/**
 * Get the App — CustomerCheck Mobile companion (not publicly listed yet).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\CustomerCheck\Service\IconCatalog;
use OCA\CustomerCheck\Support\MobileAppLinks;

$urls = is_array($_['urls'] ?? null) ? $_['urls'] : [];
$playListed = MobileAppLinks::PLAY_LISTED;
$playStore = (string)($urls['playStore'] ?? MobileAppLinks::PLAY_STORE_URL);
if (!str_starts_with($playStore, 'https://play.google.com/')) {
	$playStore = MobileAppLinks::PLAY_STORE_URL;
}
$productPage = (string)($urls['mobileProductPage'] ?? '');
$privacyPage = (string)($urls['mobilePrivacyPage'] ?? '');
if ($productPage !== '' && !str_starts_with($productPage, 'https://')) {
	$productPage = '';
}
if ($privacyPage !== '' && !str_starts_with($privacyPage, 'https://')) {
	$privacyPage = '';
}

$features = [
	[
		'icon' => 'building-2',
		'title' => $l->t('Your companies in your pocket'),
		'hint' => $l->t('Open the same customers you use in the browser — notes and next steps stay on this Nextcloud.'),
	],
	[
		'icon' => 'check-square',
		'title' => $l->t('Follow-ups on the go'),
		'hint' => $l->t('See open calls and to-dos without waiting until you are back at the desk.'),
	],
	[
		'icon' => 'users',
		'title' => $l->t('People and deals'),
		'hint' => $l->t('Look up contacts and deal status while you are with the customer.'),
	],
	[
		'icon' => 'lock',
		'title' => $l->t('Sign in safely'),
		'hint' => $l->t('Uses Nextcloud Login Flow — your main password is never stored in the app.'),
	],
];

include __DIR__ . '/common/page-start.php';
?>
<div class="crm-get-app-page crm-page-stack">
	<section class="crm-get-app__hero" aria-labelledby="crm-get-app-intro-title">
		<p class="crm-get-app__eyebrow"><?php p($l->t('Official Android companion')); ?></p>
		<h2 id="crm-get-app-intro-title" class="crm-get-app__title"><?php p($l->t('CustomerCheck Mobile')); ?></h2>
		<p class="crm-get-app__lead">
			<?php p($l->t('The official Android app connects to this Nextcloud. Keep companies and follow-ups with you — records stay on your server.')); ?>
		</p>
		<div class="crm-get-app__cta">
			<?php if ($playListed): ?>
				<a class="crm-btn crm-btn--primary crm-get-app__play" href="<?php p($playStore); ?>" target="_blank" rel="noopener noreferrer">
					<span class="crm-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('smartphone')); ?></span>
					<span class="crm-get-app__play-label"><?php p($l->t('Get it on Google Play')); ?></span>
				</a>
			<?php endif; ?>
			<p class="crm-get-app__price-hint">
				<?php if ($playListed): ?>
					<?php p($l->t('On Google Play. Your organisation licenses official mobile seats — the web app stays free.')); ?>
				<?php else: ?>
					<?php p($l->t('Not yet publicly listed on Google Play. Until then the web app covers the feature set. Your IT assigns official mobile seats on the License page.')); ?>
				<?php endif; ?>
			</p>
		</div>
	</section>

	<section class="crm-get-app__features-block" aria-labelledby="crm-get-app-features-title">
		<h2 id="crm-get-app-features-title" class="crm-get-app__section-title"><?php p($l->t('What you can do')); ?></h2>
		<ul class="crm-get-app__features">
			<?php foreach ($features as $feature): ?>
				<li class="crm-get-app__feature">
					<span class="crm-get-app__icon-well crm-get-app__icon-well--feature" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render((string)$feature['icon'])); ?>
					</span>
					<div class="crm-get-app__feature-copy">
						<span class="crm-get-app__feature-title"><?php p((string)$feature['title']); ?></span>
						<span class="crm-get-app__feature-hint"><?php p((string)$feature['hint']); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php
	$actionRows = [];
	if ($productPage !== '') {
		$actionRows[] = ['href' => $productPage, 'label' => $l->t('Product page')];
	}
	if ($privacyPage !== '') {
		$actionRows[] = ['href' => $privacyPage, 'label' => $l->t('Privacy policy for the mobile app')];
	}
	?>
	<?php if ($actionRows !== []): ?>
		<nav class="crm-get-app__actions" aria-label="<?php p($l->t('More information')); ?>">
			<?php foreach ($actionRows as $row): ?>
				<a class="crm-get-app__action" href="<?php p((string)$row['href']); ?>" target="_blank" rel="noopener noreferrer">
					<span class="crm-get-app__action-label"><?php p((string)$row['label']); ?></span>
					<span class="crm-get-app__action-external" aria-hidden="true">↗</span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
