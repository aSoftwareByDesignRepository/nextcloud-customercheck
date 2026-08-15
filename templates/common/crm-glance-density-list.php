<?php

declare(strict_types=1);

/**
 * Linked-app KPI tiles for Company 360 glance density.
 * Visual language matches crm-stat-tile / peer check-app metric cards:
 * label → big number → hint; whole tile is the hit target.
 *
 * Expects in scope:
 *   $l — IL10N
 *   $glanceTiles — list from GlanceDensityPresenter::tiles()
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var list<array{tone?:string,href?:string,ariaLabel?:string,label?:string,value?:int,hint?:string,cta?:string}> $glanceTiles */

if (!isset($glanceTiles) || !is_array($glanceTiles) || $glanceTiles === []) {
	return;
}
?>
<ul class="crm-glance-density__list" aria-labelledby="crm-glance-density-title">
	<?php foreach ($glanceTiles as $tile):
		$tone = (string)($tile['tone'] ?? 'calm');
		$tileHref = (string)($tile['href'] ?? '#crm-glance');
		?>
		<li class="crm-glance-density__item">
			<a class="crm-glance-kpi crm-glance-kpi--<?php p($tone); ?>"
				href="<?php p($tileHref); ?>"
				aria-label="<?php p((string)($tile['ariaLabel'] ?? $tile['label'] ?? '')); ?>">
				<span class="crm-glance-kpi__label"><?php p((string)($tile['label'] ?? '')); ?></span>
				<span class="crm-glance-kpi__value" aria-hidden="true"><?php p((string)(int)($tile['value'] ?? 0)); ?></span>
				<span class="crm-glance-kpi__hint"><?php p((string)($tile['hint'] ?? '')); ?></span>
				<span class="crm-glance-kpi__cta" aria-hidden="true"><?php p((string)($tile['cta'] ?? $l->t('Open'))); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
