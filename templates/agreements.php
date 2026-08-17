<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$filterKind = (string)($_['filterKind'] ?? '');
$filterStatus = (string)($_['filterStatus'] ?? '');
$filterExpiry = (string)($_['filterExpiry'] ?? '');
$filterOpen = ($filterKind !== '' || $filterStatus !== '' || $filterExpiry !== '');
$kinds = ['license', 'partner', 'onetime'];
$statuses = ['draft', 'active', 'expiring', 'expired', 'superseded', 'cancelled', 'completed'];
$labels = $_['labels'] ?? null;
$agreementsUrl = $urlGenerator->linkToRoute('customercheck.page.agreements');
$canWrite = !empty($_['canWrite']);
$crmListFragment = !empty($_['crmListFragment']);
if (!$crmListFragment) {
	include __DIR__ . '/common/page-start.php';
}
?>
<?php if (!$crmListFragment): ?>
<div class="crm-page-actions-source header-actions" hidden>
	<?php if ($canWrite): ?>
	<a class="crm-btn crm-btn--primary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>"><?php p($l->t('New agreement')); ?></a>
	<?php endif; ?>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-agreements-filter-toggle" aria-expanded="<?php p($filterOpen ? 'true' : 'false'); ?>" aria-controls="crm-agreements-filter"><?php p($l->t('More filters')); ?></button>
</div>
<div class="crm-page-stack">
<?php endif; ?>
	<nav class="crm-quick-filters" data-crm-filter-swap="nav" aria-label="<?php p($l->t('Quick filters')); ?>">
		<a class="crm-quick-filter<?php if ($filterKind === '' && $filterStatus === '' && $filterExpiry === ''): ?> is-active<?php endif; ?>" href="<?php p($agreementsUrl); ?>"<?php if ($filterKind === '' && $filterStatus === '' && $filterExpiry === ''): ?> aria-current="page"<?php endif; ?>><?php p($l->t('All')); ?></a>
		<a class="crm-quick-filter<?php if ($filterKind === 'license'): ?> is-active<?php endif; ?>" href="<?php p($agreementsUrl); ?>?kind=license"<?php if ($filterKind === 'license'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Licenses')); ?></a>
		<a class="crm-quick-filter<?php if ($filterKind === 'partner'): ?> is-active<?php endif; ?>" href="<?php p($agreementsUrl); ?>?kind=partner"<?php if ($filterKind === 'partner'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Partner')); ?></a>
		<a class="crm-quick-filter<?php if ($filterExpiry === '60'): ?> is-active<?php endif; ?>" href="<?php p($agreementsUrl); ?>?expiry=60"<?php if ($filterExpiry === '60'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Expiring soon')); ?></a>
	</nav>
<?php if (!$crmListFragment): ?>
	<section id="crm-agreements-filter" class="crm-card crm-filter-panel" <?php if (!$filterOpen): ?>hidden<?php endif; ?> aria-labelledby="crm-agreements-filter-title">
		<header class="crm-filter-panel__head">
			<h2 id="crm-agreements-filter-title"><?php p($l->t('Filter agreements')); ?></h2>
			<p class="crm-filter-panel__intro"><?php p($l->t('Pick a type, status or expiry window — applies instantly.')); ?></p>
		</header>
		<div class="crm-filter-panel__body">
			<form method="get" class="crm-filter-panel__form form" action="<?php p($agreementsUrl); ?>" data-crm-live-filter novalidate>
				<div class="crm-filter-grid" role="group">
					<div class="crm-filter-field">
						<label for="crm-filter-kind" class="form-label"><?php p($l->t('Type')); ?></label>
						<select id="crm-filter-kind" name="kind" class="form-select crm-input">
							<option value=""><?php p($l->t('All kinds')); ?></option>
							<?php foreach ($kinds as $k): ?>
								<option value="<?php p($k); ?>" <?php if ($filterKind === $k): ?>selected<?php endif; ?>><?php
									p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->agreementKind($k) : $k);
								?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-status" class="form-label"><?php p($l->t('Status')); ?></label>
						<select id="crm-filter-status" name="status" class="form-select crm-input">
							<option value=""><?php p($l->t('All statuses')); ?></option>
							<?php foreach ($statuses as $s): ?>
								<option value="<?php p($s); ?>" <?php if ($filterStatus === $s): ?>selected<?php endif; ?>><?php
									p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->agreementStatus($s) : $s);
								?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-expiry" class="form-label"><?php p($l->t('Expiry window')); ?></label>
						<select id="crm-filter-expiry" name="expiry" class="form-select crm-input">
							<option value=""><?php p($l->t('Any')); ?></option>
							<option value="60" <?php if ($filterExpiry === '60'): ?>selected<?php endif; ?>><?php p($l->t('Within 60 days')); ?></option>
							<option value="7" <?php if ($filterExpiry === '7'): ?>selected<?php endif; ?>><?php p($l->t('Within 7 days')); ?></option>
						</select>
					</div>
					<div class="crm-filter-field crm-filter-field--actions">
						<button type="submit" class="crm-btn crm-btn--primary" id="btn-apply-filter"><?php p($l->t('Apply')); ?></button>
						<a class="crm-btn crm-btn--secondary" id="btn-clear-filter" href="<?php p($agreementsUrl); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
			</form>
		</div>
	</section>
<?php endif; ?>
	<div data-crm-filter-swap="main" id="crm-agreements-results" data-crm-filter-empty="<?php p($l->t('No agreements yet')); ?>" data-crm-filter-done="<?php p($l->t('List updated.')); ?>" data-crm-filter-failed="<?php p($l->t('Could not update the list. Try again.')); ?>">
	<section class="crm-card">
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($l->t('All agreements')); ?></h2></header>
		<div class="crm-card__body">
			<?php if (($_['agreements'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No agreements yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Create a license or partner package for a company. Import is optional.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>"><?php p($l->t('New agreement')); ?></a>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Label')); ?></th>
						<th scope="col"><?php p($l->t('Company')); ?></th>
						<th scope="col"><?php p($l->t('Product')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Expiry')); ?></th>
						<th scope="col"><?php p($l->t('Invoice')); ?></th>
						<th scope="col"><?php p($l->t('Amount')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['agreements'] as $a): ?>
						<tr>
							<td data-label="<?php p($l->t('Label')); ?>"><?php if ($canWrite): ?><a href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => $a['id']])); ?>"><?php p($a['label']); ?></a><?php else: ?><?php p($a['label']); ?><?php endif; ?></td>
							<td data-label="<?php p($l->t('Company')); ?>">
								<?php
								$crmCompanyLinkId = (int)($a['companyId'] ?? 0);
								$crmCompanyLinkLabel = (string)($a['companyName'] ?? '');
								include __DIR__ . '/common/crm-company-link.php';
								?>
							</td>
							<td data-label="<?php p($l->t('Product')); ?>"><code><?php p($a['productCode']); ?></code></td>
							<td data-label="<?php p($l->t('Status')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->agreementStatus((string)$a['status'])
									: (string)$a['status']);
							?></span></td>
							<td data-label="<?php p($l->t('Expiry')); ?>"><span class="crm-badge crm-badge--<?php p($a['chip']['variant'] ?? 'neutral'); ?>"><?php p($a['chip']['label'] ?? ($a['validUntil'] ?? '—')); ?></span></td>
							<td data-label="<?php p($l->t('Invoice')); ?>"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->invoiceStatus((string)$a['invoiceStatus'])
									: (string)$a['invoiceStatus']);
							?></td>
							<td data-label="<?php p($l->t('Amount')); ?>"><?php p(number_format(($a['amountCents'] ?? 0)/100, 2)); ?> €</td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if ($canWrite): ?>
										<?php
										$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => (int)$a['id']]);
										$crmRowOpenLabel = $l->t('Edit');
										include __DIR__ . '/common/crm-row-open.php';
										?>
										<?php if (($a['kind'] ?? '') === 'partner'): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-partner-session="<?php p($a['id']); ?>" data-company-id="<?php p((int)($a['companyId'] ?? 0)); ?>"><?php p($l->t('Email & log hours')); ?></button>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-log-hours="<?php p($a['id']); ?>"><?php p($l->t('Hours only')); ?></button>
										<?php endif; ?>
										<?php if (($a['kind'] ?? '') === 'license' && (!empty($a['salesLogId']) || !empty($a['keyFingerprint']))): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-delivery-pack="<?php p($a['id']); ?>" data-company-id="<?php p((int)($a['companyId'] ?? 0)); ?>"><?php p($l->t('Delivery pack')); ?></button>
										<?php endif; ?>
									<?php else: ?>
										<?php
										$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => (int)($a['companyId'] ?? 0)]);
										$crmRowOpenLabel = $l->t('Open');
										include __DIR__ . '/common/crm-row-open.php';
										?>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</section>
	</div>
<?php if (!$crmListFragment): ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
<?php endif; ?>
