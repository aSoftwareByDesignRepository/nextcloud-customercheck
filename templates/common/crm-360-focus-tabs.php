<?php

declare(strict_types=1);

/**
 * Pipedrive-style focus tabs for Company 360 — one panel visible at a time.
 *
 * Expects in scope:
 *   $l — IL10N
 *   $focusTabs — list<array{id: string, label: string}>
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var list<array{id: string, label: string}> $focusTabs */

if (!isset($focusTabs) || !is_array($focusTabs) || $focusTabs === []) {
	return;
}
?>
<div class="crm-360-focus__chrome">
	<div class="crm-360-focus__tabs" role="tablist" aria-orientation="horizontal" aria-label="<?php p($l->t('Customer sections')); ?>">
		<?php foreach ($focusTabs as $tab):
			$tabId = (string)($tab['id'] ?? '');
			$tabLabel = (string)($tab['label'] ?? '');
			if ($tabId === '' || $tabLabel === '') {
				continue;
			}
			$panelId = 'crm-360-panel-' . $tabId;
			$tabButtonId = 'crm-360-tab-' . $tabId;
			$isDefault = $tabId === 'today';
			?>
			<button type="button"
				class="crm-360-focus__tab"
				id="<?php p($tabButtonId); ?>"
				role="tab"
				data-crm-focus-tab="<?php p($tabId); ?>"
				aria-controls="<?php p($panelId); ?>"
				aria-selected="<?php p($isDefault ? 'true' : 'false'); ?>"
				tabindex="<?php p($isDefault ? '0' : '-1'); ?>">
				<?php p($tabLabel); ?>
			</button>
		<?php endforeach; ?>
	</div>
</div>
