<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$filterQ = (string)($_['filterQ'] ?? '');
$filterStatus = (string)($_['filterStatus'] ?? '');
$filterArchived = !empty($_['filterArchived']);
$statuses = ['lead', 'active', 'paused', 'churned'];
$labels = $_['labels'] ?? null;
$companiesUrl = $urlGenerator->linkToRoute('customercheck.page.companies');
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-actions-source header-actions" hidden>
	<?php if (!empty($_['canWrite'])): ?>
	<a class="crm-btn crm-btn--primary" href="<?php p($_['urls']['companyNew'] ?? '#'); ?>"><?php p($l->t('Add company')); ?></a>
	<?php endif; ?>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-companies-filter-toggle" aria-expanded="<?php p(($filterQ !== '' || $filterStatus !== '' || $filterArchived) ? 'true' : 'false'); ?>" aria-controls="crm-companies-filter"><?php p($l->t('More filters')); ?></button>
</div>
<div class="crm-page-stack">
	<nav class="crm-quick-filters" aria-label="<?php p($l->t('Quick filters')); ?>">
		<a class="crm-quick-filter<?php if ($filterStatus === '' && !$filterArchived && $filterQ === ''): ?> is-active<?php endif; ?>" href="<?php p($companiesUrl); ?>"<?php if ($filterStatus === '' && !$filterArchived && $filterQ === ''): ?> aria-current="page"<?php endif; ?>><?php p($l->t('All')); ?></a>
		<a class="crm-quick-filter<?php if ($filterStatus === 'active' && !$filterArchived): ?> is-active<?php endif; ?>" href="<?php p($companiesUrl); ?>?status=active"<?php if ($filterStatus === 'active' && !$filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Active')); ?></a>
		<a class="crm-quick-filter<?php if ($filterStatus === 'lead' && !$filterArchived): ?> is-active<?php endif; ?>" href="<?php p($companiesUrl); ?>?status=lead"<?php if ($filterStatus === 'lead' && !$filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Leads')); ?></a>
		<a class="crm-quick-filter<?php if ($filterArchived): ?> is-active<?php endif; ?>" href="<?php p($companiesUrl); ?>?archived=1"<?php if ($filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Archived')); ?></a>
	</nav>
	<section id="crm-companies-filter" class="crm-card crm-filter-panel" <?php if ($filterQ === '' && $filterStatus === '' && !$filterArchived): ?>hidden<?php endif; ?> aria-labelledby="crm-companies-filter-title">
		<header class="crm-filter-panel__head">
			<h2 id="crm-companies-filter-title"><?php p($l->t('Filter companies')); ?></h2>
			<p class="crm-filter-panel__intro"><?php p($l->t('Search by name or pick a status. Changes apply as you type.')); ?></p>
		</header>
		<div class="crm-filter-panel__body">
			<form method="get" class="crm-filter-panel__form form" action="<?php p($companiesUrl); ?>" data-crm-live-filter novalidate>
				<div class="crm-filter-grid" role="group">
					<div class="crm-filter-field">
						<label for="crm-filter-q" class="form-label"><?php p($l->t('Search')); ?></label>
						<input id="crm-filter-q" name="q" type="search" class="form-input crm-input" value="<?php p($filterQ); ?>" placeholder="<?php p($l->t('Company name')); ?>" autocomplete="off" />
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-status" class="form-label"><?php p($l->t('Status')); ?></label>
						<select id="crm-filter-status" name="status" class="form-select crm-input">
							<option value=""><?php p($l->t('All statuses')); ?></option>
							<?php foreach ($statuses as $s): ?>
								<option value="<?php p($s); ?>" <?php if ($filterStatus === $s): ?>selected<?php endif; ?>><?php
									p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->companyStatus($s) : $s);
								?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="crm-filter-field">
						<label class="form-label" for="crm-filter-archived"><?php p($l->t('Archived')); ?></label>
						<select id="crm-filter-archived" name="archived" class="form-select crm-input">
							<option value="0" <?php if (!$filterArchived): ?>selected<?php endif; ?>><?php p($l->t('Hide archived')); ?></option>
							<option value="1" <?php if ($filterArchived): ?>selected<?php endif; ?>><?php p($l->t('Show archived only')); ?></option>
						</select>
					</div>
					<div class="crm-filter-field crm-filter-field--actions">
						<button type="submit" class="crm-btn crm-btn--secondary" id="btn-apply-filter"><?php p($l->t('Refresh')); ?></button>
						<a class="crm-btn crm-btn--ghost" id="btn-clear-filter" href="<?php p($companiesUrl); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
			</form>
		</div>
	</section>
	<section class="crm-card">
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($filterArchived ? $l->t('Archived companies') : $l->t('All companies')); ?></h2></header>
		<div class="crm-card__body">
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Name')); ?></th>
						<th scope="col"><?php p($l->t('Owner')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Last touch')); ?></th>
						<th scope="col"><?php p($l->t('Next expiry')); ?></th>
						<th scope="col"><?php p($l->t('Money')); ?></th>
						<th scope="col"><?php p($l->t('Callbacks')); ?></th>
					</tr></thead>
					<tbody>
					<?php
					$healthMap = $_['health'] ?? [];
					foreach (($_['companies'] ?? []) as $c):
						$hid = (int)$c->getId();
						$h = $healthMap[$hid] ?? ['lastTouch' => null, 'nextExpiry' => null, 'moneyState' => 'unknown', 'openCallbacks' => 0, 'inConversation' => false];
						$moneyState = (string)($h['moneyState'] ?? 'unknown');
						$moneyBadge = match ($moneyState) {
							'critical' => 'danger',
							'attention' => 'warning',
							'ok' => 'success',
							default => 'neutral',
						};
						$moneyLabel = $labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
							? $labels->billingState($moneyState)
							: $moneyState;
						$accountOwner = trim((string)($c->getAccountOwnerUserId() ?? ''));
						$ownerNames = is_array($_['ownerDisplayNames'] ?? null) ? $_['ownerDisplayNames'] : [];
						$ownerLabel = $accountOwner !== ''
							? (string)($ownerNames[$accountOwner] ?? $accountOwner)
							: '';
						?>
						<tr>
							<td data-label="<?php p($l->t('Name')); ?>"><?php
								$crmCompanyLinkId = (int)$c->getId();
								$crmCompanyLinkLabel = (string)$c->getName();
								include __DIR__ . '/common/crm-company-link.php';
								if (!empty($h['inConversation'])): ?> <span class="crm-badge crm-badge--info"><?php p($l->t('In conversation')); ?></span><?php endif;
							?></td>
							<td data-label="<?php p($l->t('Owner')); ?>">
								<?php if ($ownerLabel !== ''): ?>
									<span class="crm-owner" data-crm-account-owner="<?php p($accountOwner); ?>"><?php p($ownerLabel); ?></span>
								<?php else: ?>
									<span class="crm-muted"><?php p($l->t('Unassigned')); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Status')); ?>"><span class="crm-badge crm-badge--<?php p($c->getStatus() === 'active' ? 'success' : 'neutral'); ?>"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->companyStatus((string)$c->getStatus())
									: (string)$c->getStatus());
							?></span></td>
							<td data-label="<?php p($l->t('Last touch')); ?>"><?php p($h['lastTouch'] ?? $l->t('No touch yet')); ?></td>
							<td data-label="<?php p($l->t('Next expiry')); ?>"><?php p($h['nextExpiry'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Money')); ?>"><span class="crm-badge crm-badge--<?php p($moneyBadge); ?>"><?php p($moneyLabel); ?></span></td>
							<td data-label="<?php p($l->t('Callbacks')); ?>"><?php p((string)(int)($h['openCallbacks'] ?? 0)); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if (($_['companies'] ?? []) === []): ?>
						<tr><td class="crm-table-empty" colspan="7">
							<div class="crm-empty-state" role="status">
								<h3 class="crm-empty-state__title"><?php p($l->t('No companies yet')); ?></h3>
								<p class="crm-empty-state__text"><?php p($l->t('Add a company or import a list. ProjectCheck and InvoiceCheck are optional — CustomerCheck works alone.')); ?></p>
								<div class="crm-empty-state__actions">
									<?php if (!empty($_['canWrite'])): ?>
									<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($_['urls']['companyNew'] ?? '#'); ?>"><?php p($l->t('Add company')); ?></a>
									<a class="crm-btn crm-btn--secondary" href="<?php p($_['urls']['import'] ?? '#'); ?>"><?php p($l->t('Import')); ?></a>
									<?php endif; ?>
								</div>
							</div>
						</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
