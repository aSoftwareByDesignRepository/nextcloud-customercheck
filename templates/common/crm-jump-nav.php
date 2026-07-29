<?php

declare(strict_types=1);

/**
 * In-page section jump navigation for long CustomerCheck pages (360 company view).
 *
 * Expects in scope:
 *   $l — IL10N
 *   $jumpNavAriaLabel — string (nav aria-label)
 *   $jumpNavItems — list<array{href: string, label: string, primary?: bool}>
 *   $jumpNavLayout — optional: "bar" (default) or "sidebar"
 *   $jumpNavPrimaryMax — optional; when > 0, items without primary (or overflow) go under “More”
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var string $jumpNavAriaLabel */
/** @var list<array{href: string, label: string, primary?: bool}> $jumpNavItems */

if (!isset($jumpNavItems) || !is_array($jumpNavItems) || $jumpNavItems === []) {
	return;
}

$jumpNavLayout = isset($jumpNavLayout) ? (string)$jumpNavLayout : 'bar';
$jumpNavIsBar = $jumpNavLayout !== 'sidebar';
$jumpNavClass = 'crm-jump-nav' . ($jumpNavIsBar ? ' crm-jump-nav--bar' : '');
$labelId = 'crm-jump-nav-label';
$primaryMax = isset($jumpNavPrimaryMax) ? max(0, (int)$jumpNavPrimaryMax) : 0;

$primary = [];
$more = [];
foreach ($jumpNavItems as $item) {
	$href = (string)($item['href'] ?? '');
	$label = (string)($item['label'] ?? '');
	if ($href === '' || $label === '' || !str_starts_with($href, '#')) {
		continue;
	}
	$row = ['href' => $href, 'label' => $label];
	if ($primaryMax <= 0) {
		$primary[] = $row;
		continue;
	}
	$wantsPrimary = !empty($item['primary']);
	if ($wantsPrimary && count($primary) < $primaryMax) {
		$primary[] = $row;
	} else {
		$more[] = $row;
	}
}
if ($primary === [] && $more !== []) {
	$primary = $more;
	$more = [];
}
?>
<nav class="<?php p($jumpNavClass); ?>" aria-label="<?php p($jumpNavAriaLabel); ?>" data-crm-jump-nav="1">
	<?php if ($jumpNavIsBar): ?>
		<p class="crm-jump-nav__label" id="<?php p($labelId); ?>"><?php p($l->t('On this page')); ?></p>
	<?php else: ?>
		<h2 class="crm-jump-nav__title" id="<?php p($labelId); ?>"><?php p($l->t('Quick navigation')); ?></h2>
	<?php endif; ?>
	<ol class="crm-jump-nav__list" aria-labelledby="<?php p($labelId); ?>">
		<?php foreach ($primary as $item): ?>
			<li class="crm-jump-nav__item">
				<a class="crm-jump-nav__link" href="<?php p($item['href']); ?>"><?php p($item['label']); ?></a>
			</li>
		<?php endforeach; ?>
	</ol>
	<?php if ($more !== []): ?>
		<details class="crm-jump-nav__more">
			<summary class="crm-jump-nav__more-summary"><?php p($l->t('More sections')); ?></summary>
			<ol class="crm-jump-nav__list crm-jump-nav__list--more">
				<?php foreach ($more as $item): ?>
					<li class="crm-jump-nav__item">
						<a class="crm-jump-nav__link" href="<?php p($item['href']); ?>"><?php p($item['label']); ?></a>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
	<?php endif; ?>
</nav>
