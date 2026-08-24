<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

/** @var array<string, string> $capabilityLabels */
$capabilityLabels = [
	'sbdlicenseops' => $l->t('License Ops (sales log)'),
	'invoicecheck' => $l->t('InvoiceCheck'),
	'projectcheck' => $l->t('ProjectCheck (read)'),
	'ticketcheck' => $l->t('TicketCheck (read)'),
	'projectcheckWrite' => $l->t('ProjectCheck (write)'),
	'ticketcheckWrite' => $l->t('TicketCheck (write)'),
	'maintenancecheck' => $l->t('MaintenanceCheck (field customer)'),
	'mail' => $l->t('Nextcloud Mail'),
];

$capabilities = is_array($_['capabilities'] ?? null) ? $_['capabilities'] : [];
$rows = [];
foreach ($capabilityLabels as $key => $label) {
	if (!array_key_exists($key, $capabilities)) {
		continue;
	}
	$raw = $capabilities[$key];
	// Matrix may include *_detail arrays — never treat those as availability.
	if (!is_bool($raw)) {
		continue;
	}
	$rows[] = ['key' => $key, 'label' => $label, 'ok' => $raw];
}
?>
<section class="crm-card" id="section-integrations" aria-labelledby="section-integrations-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-integrations-heading" class="crm-card__title"><?php p($l->t('Integrations')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Optional Check apps and Nextcloud Mail on this server. Missing apps hide their company panels — nothing breaks.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if ($rows === []): ?>
				<p class="crm-muted" role="status"><?php p($l->t('No integration probes are available yet.')); ?></p>
			<?php else: ?>
			<ul class="crm-simple-list" aria-label="<?php p($l->t('Linked apps')); ?>">
			<?php foreach ($rows as $row): ?>
				<li>
					<strong><?php p($row['label']); ?></strong>
					· <span class="crm-badge crm-badge--<?php p($row['ok'] ? 'success' : 'neutral'); ?>"><?php p($row['ok'] ? $l->t('available') : $l->t('not available')); ?></span>
				</li>
			<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
	</section>
