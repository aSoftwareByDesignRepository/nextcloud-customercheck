<?php

declare(strict_types=1);

/**
 * Visible expand/collapse affordance for CRM card <summary> rows.
 *
 * Expects in scope: $l — IL10N
 *
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
?>
<span class="crm-card__disclosure" aria-hidden="true">
	<span class="crm-card__disclosure-label crm-card__disclosure-label--closed"><?php p($l->t('Open')); ?></span>
	<span class="crm-card__disclosure-label crm-card__disclosure-label--open"><?php p($l->t('Close')); ?></span>
	<span class="crm-card__disclosure-icon" aria-hidden="true"></span>
</span>
