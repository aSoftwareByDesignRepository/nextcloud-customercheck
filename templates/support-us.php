<?php

declare(strict_types=1);

/**
 * Dedicated page: Support & us (partner / invoiceable care).
 * Layout matches ArbeitszeitCheck admin-support-us (family standard).
 *
 * Informational CTAs only — never gates AGPL use of the app.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\IURLGenerator $urlGenerator
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$l = $_['l'] ?? \OCP\Util::getL10N('customercheck');
$urlGenerator = $_['urlGenerator'] ?? null;
$appDisplayName = 'CustomerCheck';
$vendorMarkUrl = '';
if ($urlGenerator instanceof \OCP\IURLGenerator) {
	$vendorMarkFs = __DIR__ . '/../img/vendor-logo-mark.png';
	$vendorMarkUrl = $urlGenerator->imagePath('customercheck', 'vendor-logo-mark.png');
	if (is_readable($vendorMarkFs)) {
		$vendorMarkUrl .= '?v=' . (string)filemtime($vendorMarkFs);
	}
}
$vendorSiteUrl = 'https://nextcloud.software-by-design.de/';

include __DIR__ . '/common/page-start.php';
?>

<div class="crm-support-us-page" data-crm-support-us-page="1" data-crm-support-us-layout="offer-grid">
	<section class="crm-support-us-page__hero" aria-labelledby="crm-support-us-hero-title">
		<div class="crm-support-us-page__hero-main">
			<?php if ($vendorMarkUrl !== ''): ?>
			<div class="crm-support-us-page__brand">
				<a
					class="crm-support-us-page__brand-link"
					href="<?php p($vendorSiteUrl); ?>"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="<?php p($l->t('Software by Design — open website in a new tab')); ?>"
				>
					<span class="crm-support-us-page__lockup" data-crm-vendor-logo="1">
						<img
							class="crm-support-us-page__mark"
							src="<?php p($vendorMarkUrl); ?>"
							width="56"
							height="56"
							alt=""
							decoding="async"
						>
						<span class="crm-support-us-page__wordmark" aria-hidden="true">
							<span class="crm-support-us-page__wordmark-software">SOFTWARE</span>
							<span class="crm-support-us-page__wordmark-by">BY DESIGN</span>
						</span>
					</span>
				</a>
			</div>
			<?php endif; ?>
			<p class="crm-support-us-page__kicker"><?php p($l->t('Software by Design · Check apps')); ?></p>
			<h2 id="crm-support-us-hero-title" class="crm-support-us-page__headline">
				<?php p($l->t('Bookable help for your organisation — while the app stays free')); ?>
			</h2>
			<p class="crm-support-us-page__lead">
				<?php p($l->t('%s is AGPL software on your Nextcloud. Bug reports and ideas on GitHub stay welcome. When you need priority email, hour packs, workshops, or a commissioned feature, we invoice only after you accept a quote.', [$appDisplayName])); ?>
			</p>
		</div>
		<ul class="crm-support-us-page__trust" aria-label="<?php p($l->t('What stays free')); ?>">
			<li><?php p($l->t('Free to run under AGPL')); ?></li>
			<li><?php p($l->t('Open-source care on GitHub stays free')); ?></li>
			<li><?php p($l->t('Partner and project work are optional and invoiceable')); ?></li>
		</ul>
	</section>

	<?php
	$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
	$supportUsCssPrefix = 'crm';
	$supportUsShellPrefix = 'crm';
	$supportUsBtnPrimaryClass = 'crm-btn crm-btn--primary';
	$supportUsBtnSecondaryClass = 'crm-btn crm-btn--secondary';
	$supportUsPresentation = 'page';
	$supportUsLinks = new \OCA\CustomerCheck\Support\SupportUsLinks(
		$appDisplayName,
		false,
		null
	);
	include __DIR__ . '/parts/support-us-section.php';
	?>
</div>

<?php include __DIR__ . '/common/page-end.php'; ?>
