<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$filterState = (string)($_['filterState'] ?? '');
$groups = $_['overview']['groups'] ?? ['overdue' => [], 'sent' => [], 'paid30d' => [], 'unknown' => []];
$items = $_['overview']['items'] ?? [];
$filterOpen = $filterState !== '';
$labels = $_['labels'] ?? null;
$moneyUrl = $urlGenerator->linkToRoute('customercheck.page.money');
$canWrite = !empty($_['canWrite']);
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-actions-source header-actions" hidden>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-money-filter-toggle" aria-expanded="<?php p($filterOpen ? 'true' : 'false'); ?>" aria-controls="crm-money-filter"><?php p($l->t('More filters')); ?></button>
</div>
<div class="crm-page-stack">
	<nav class="crm-quick-filters" aria-label="<?php p($l->t('Quick filters')); ?>">
		<a class="crm-quick-filter<?php if ($filterState === ''): ?> is-active<?php endif; ?>" href="<?php p($moneyUrl); ?>"<?php if ($filterState === ''): ?> aria-current="page"<?php endif; ?>><?php p($l->t('All')); ?></a>
		<a class="crm-quick-filter<?php if ($filterState === 'overdue'): ?> is-active<?php endif; ?>" href="<?php p($moneyUrl); ?>?state=overdue"<?php if ($filterState === 'overdue'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Overdue')); ?></a>
		<a class="crm-quick-filter<?php if ($filterState === 'sent'): ?> is-active<?php endif; ?>" href="<?php p($moneyUrl); ?>?state=sent"<?php if ($filterState === 'sent'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Unpaid')); ?></a>
		<a class="crm-quick-filter<?php if ($filterState === 'paid30d'): ?> is-active<?php endif; ?>" href="<?php p($moneyUrl); ?>?state=paid30d"<?php if ($filterState === 'paid30d'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Paid')); ?></a>
		<a class="crm-quick-filter<?php if ($filterState === 'unknown'): ?> is-active<?php endif; ?>" href="<?php p($moneyUrl); ?>?state=unknown"<?php if ($filterState === 'unknown'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Unknown')); ?></a>
	</nav>
	<section id="crm-money-filter" class="crm-card crm-filter-panel" <?php if (!$filterOpen): ?>hidden<?php endif; ?> aria-labelledby="crm-money-filter-title">
		<header class="crm-filter-panel__head">
			<h2 id="crm-money-filter-title"><?php p($l->t('Filter by payment state')); ?></h2>
			<p class="crm-filter-panel__intro"><?php p($l->t('Show overdue, unpaid, paid, or agreements without an invoice state — applies instantly.')); ?></p>
		</header>
		<div class="crm-filter-panel__body">
			<form method="get" class="crm-filter-panel__form form" action="<?php p($moneyUrl); ?>" data-crm-live-filter novalidate>
				<div class="crm-filter-grid" role="group">
					<div class="crm-filter-field">
						<label for="crm-filter-state" class="form-label"><?php p($l->t('Invoice state')); ?></label>
						<select id="crm-filter-state" name="state" class="form-select crm-input">
							<option value=""><?php p($l->t('All')); ?></option>
							<option value="overdue" <?php if ($filterState === 'overdue'): ?>selected<?php endif; ?>><?php p($l->t('Overdue')); ?></option>
							<option value="sent" <?php if ($filterState === 'sent'): ?>selected<?php endif; ?>><?php p($l->t('Sent / unpaid')); ?></option>
							<option value="paid30d" <?php if ($filterState === 'paid30d'): ?>selected<?php endif; ?>><?php p($l->t('Paid (30 days)')); ?></option>
							<option value="unknown" <?php if ($filterState === 'unknown'): ?>selected<?php endif; ?>><?php p($l->t('Unknown / none')); ?></option>
						</select>
					</div>
					<div class="crm-filter-field crm-filter-field--actions">
						<button type="submit" class="crm-btn crm-btn--primary" id="btn-apply-filter"><?php p($l->t('Apply')); ?></button>
						<a class="crm-btn crm-btn--secondary" id="btn-clear-filter" href="<?php p($moneyUrl); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
			</form>
		</div>
	</section>
	<?php
	$sections = [
		'overdue' => [$l->t('Overdue'), $l->t('Past due — send a payment reminder.'), 'danger'],
		'sent' => [$l->t('Sent / unpaid'), $l->t('Invoices sent but not marked paid.'), 'warning'],
		'paid30d' => [$l->t('Paid (last 30 days)'), $l->t('Recently paid agreements.'), 'success'],
		'unknown' => [$l->t('Unknown / none'), $l->t('Agreements without an invoice state yet.'), 'neutral'],
	];
	foreach ($sections as $key => [$title, $lead, $badge]):
		$rows = $groups[$key] ?? [];
		if ($filterState !== '' && $filterState !== $key) {
			continue;
		}
		if ($rows === [] && $filterState === '') {
			continue;
		}
	?>
	<section class="crm-card">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 class="crm-card__title"><?php p($title); ?></h2>
				<p class="crm-card__lead"><?php p($lead); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if ($rows === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Nothing in this group')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Try another filter, or show all money again.')); ?></p>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="<?php p($moneyUrl); ?>"><?php p($l->t('Show all money')); ?></a>
						<a class="crm-btn crm-btn--secondary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreements')); ?>"><?php p($l->t('Open agreements')); ?></a>
					</div>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Company')); ?></th>
						<th scope="col"><?php p($l->t('Agreement')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Reference')); ?></th>
						<th scope="col"><?php p($l->t('Amount')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($rows as $row): ?>
						<tr>
							<td data-label="<?php p($l->t('Company')); ?>"><?php
								$crmCompanyLinkId = (int)($row['companyId'] ?? 0);
								$crmCompanyLinkLabel = (string)($row['companyName'] ?? '');
								include __DIR__ . '/common/crm-company-link.php';
							?></td>
							<td data-label="<?php p($l->t('Agreement')); ?>"><?php p($row['label']); ?></td>
							<td data-label="<?php p($l->t('Status')); ?>"><span class="crm-badge crm-badge--<?php p($badge); ?>"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->invoiceStatus((string)$row['invoiceStatus'])
									: (string)$row['invoiceStatus']);
							?></span><?php
								$agingBucket = array_key_exists('agingBucket', $row) ? $row['agingBucket'] : null;
								$daysPastDue = array_key_exists('daysPastDue', $row) && $row['daysPastDue'] !== null
									? (int)$row['daysPastDue'] : null;
								include __DIR__ . '/common/aging-chip.php';
							?></td>
							<td data-label="<?php p($l->t('Reference')); ?>"><?php p($row['invoiceRef'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Amount')); ?>"><?php p(number_format(($row['amountCents'] ?? 0)/100, 2)); ?> €</td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php
									$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => (int)($row['companyId'] ?? 0)]);
									$crmRowOpenLabel = $l->t('Open');
									include __DIR__ . '/common/crm-row-open.php';
									?>
									<?php if ($key !== 'paid30d' && $canWrite && !empty($row['agreementId'])): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-payment-reminder="<?php p($row['agreementId']); ?>" data-company-id="<?php p($row['companyId']); ?>"><?php p($l->t('Payment reminder')); ?></button>
									<?php elseif ($key !== 'paid30d' && empty($row['agreementId'])): ?>
									<span class="crm-muted"><?php p($l->t('From InvoiceCheck')); ?></span>
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
	<?php endforeach; ?>
	<?php if ($items === [] && $filterState === ''): ?>
	<section class="crm-card">
		<div class="crm-card__body">
			<div class="crm-empty-state" role="status">
				<h3 class="crm-empty-state__title"><?php p($l->t('Nothing unpaid')); ?></h3>
				<p class="crm-empty-state__text"><?php p($l->t('When invoices are overdue or unpaid, they show up here. You can also open agreements or a company.')); ?></p>
				<div class="crm-empty-state__actions">
					<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreements')); ?>"><?php p($l->t('Open agreements')); ?></a>
					<a class="crm-btn crm-btn--secondary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companies')); ?>"><?php p($l->t('Open companies')); ?></a>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
