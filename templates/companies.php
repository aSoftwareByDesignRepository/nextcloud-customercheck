<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$filterQ = (string)($_['filterQ'] ?? '');
$filterStatus = (string)($_['filterStatus'] ?? '');
$filterArchived = !empty($_['filterArchived']);
$workspaceId = (int)($_['workspaceId'] ?? 0);
$statuses = ['lead', 'active', 'paused', 'churned'];
$labels = $_['labels'] ?? null;
$companiesUrl = $urlGenerator->linkToRoute('customercheck.page.companies');
$crmCompaniesHref = static function (array $extra = []) use ($companiesUrl, $workspaceId): string {
	$query = $extra;
	if ($workspaceId > 0) {
		$query['workspaceId'] = $workspaceId;
	}
	$query = array_filter(
		$query,
		static fn ($v) => $v !== null && $v !== '' && $v !== false,
	);
	return $query === [] ? $companiesUrl : ($companiesUrl . '?' . http_build_query($query));
};
$filterPanelOpen = ($filterStatus !== '' || $filterArchived);
$crmListFragment = !empty($_['crmListFragment']);
if (!$crmListFragment) {
	include __DIR__ . '/common/page-start.php';
}
?>
<?php if (!$crmListFragment): ?>
<div class="crm-page-actions-source header-actions" hidden>
	<?php if (!empty($_['canWrite'])): ?>
	<a class="crm-btn crm-btn--primary" href="<?php p($_['urls']['companyNew'] ?? '#'); ?>"><?php p($l->t('Add company')); ?></a>
	<?php endif; ?>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-companies-filter-toggle" aria-expanded="<?php p($filterPanelOpen ? 'true' : 'false'); ?>" aria-controls="crm-companies-filter"><?php p($l->t('More filters')); ?></button>
</div>
<div class="crm-page-stack">
<?php endif; ?>
	<nav class="crm-quick-filters" data-crm-filter-swap="nav" aria-label="<?php p($l->t('Quick filters')); ?>">
		<a class="crm-quick-filter<?php if ($filterStatus === '' && !$filterArchived): ?> is-active<?php endif; ?>" href="<?php p($crmCompaniesHref($filterQ !== '' ? ['q' => $filterQ] : [])); ?>"<?php if ($filterStatus === '' && !$filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('All')); ?></a>
		<a class="crm-quick-filter<?php if ($filterStatus === 'active' && !$filterArchived): ?> is-active<?php endif; ?>" href="<?php p($crmCompaniesHref(array_filter(['status' => 'active', 'q' => $filterQ !== '' ? $filterQ : null]))); ?>"<?php if ($filterStatus === 'active' && !$filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Active')); ?></a>
		<a class="crm-quick-filter<?php if ($filterStatus === 'lead' && !$filterArchived): ?> is-active<?php endif; ?>" href="<?php p($crmCompaniesHref(array_filter(['status' => 'lead', 'q' => $filterQ !== '' ? $filterQ : null]))); ?>"<?php if ($filterStatus === 'lead' && !$filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Leads')); ?></a>
		<a class="crm-quick-filter<?php if ($filterArchived): ?> is-active<?php endif; ?>" href="<?php p($crmCompaniesHref(array_filter(['archived' => '1', 'q' => $filterQ !== '' ? $filterQ : null]))); ?>"<?php if ($filterArchived): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Archived')); ?></a>
	</nav>
<?php if (!$crmListFragment): ?>

	<form method="get" class="crm-companies-search" action="<?php p($companiesUrl); ?>" data-crm-live-filter role="search" aria-labelledby="crm-companies-search-title" novalidate>
		<?php if ($workspaceId > 0): ?>
		<input type="hidden" name="workspaceId" value="<?php p((string)$workspaceId); ?>" />
		<?php endif; ?>
		<?php if ($filterStatus !== ''): ?>
		<input type="hidden" name="status" value="<?php p($filterStatus); ?>" />
		<?php endif; ?>
		<?php if ($filterArchived): ?>
		<input type="hidden" name="archived" value="1" />
		<?php endif; ?>
		<h2 id="crm-companies-search-title" class="visually-hidden"><?php p($l->t('Search companies')); ?></h2>
		<label for="crm-companies-q" class="form-label"><?php p($l->t('Search')); ?></label>
		<div class="crm-companies-search__row">
			<input
				id="crm-companies-q"
				name="q"
				type="search"
				class="form-input crm-input crm-companies-search__input"
				value="<?php p($filterQ); ?>"
				placeholder="<?php p($l->t('Name, contact, owner, status, callback…')); ?>"
				autocomplete="off"
				maxlength="100"
				enterkeyhint="search"
				aria-describedby="crm-companies-q-hint"
			/>
			<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Search')); ?></button>
			<a class="crm-btn crm-btn--ghost" id="crm-companies-search-clear" href="<?php p($crmCompaniesHref()); ?>"<?php if ($filterQ === '' && $filterStatus === '' && !$filterArchived): ?> hidden<?php endif; ?>><?php p($l->t('Clear')); ?></a>
		</div>
		<p class="crm-companies-search__hint" id="crm-companies-q-hint"><?php p($l->t('Finds company name, people, owner, status, open callbacks, and recent talk.')); ?> <?php p($l->t('Results update as you type.')); ?></p>
	</form>

	<section id="crm-companies-filter" class="crm-card crm-filter-panel" <?php if (!$filterPanelOpen): ?>hidden<?php endif; ?> aria-labelledby="crm-companies-filter-title">
		<header class="crm-filter-panel__head">
			<h2 id="crm-companies-filter-title"><?php p($l->t('Filter companies')); ?></h2>
			<p class="crm-filter-panel__intro"><?php p($l->t('Narrow by status or archived. Changes apply as you pick.')); ?></p>
		</header>
		<div class="crm-filter-panel__body">
			<form method="get" class="crm-filter-panel__form form" action="<?php p($companiesUrl); ?>" data-crm-live-filter novalidate>
				<?php if ($workspaceId > 0): ?>
				<input type="hidden" name="workspaceId" value="<?php p((string)$workspaceId); ?>" />
				<?php endif; ?>
				<input type="hidden" name="q" value="<?php p($filterQ); ?>" />
				<div class="crm-filter-grid" role="group">
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
						<a class="crm-btn crm-btn--ghost" id="btn-clear-filter" href="<?php p($crmCompaniesHref()); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
			</form>
		</div>
	</section>
<?php endif; ?>

	<div data-crm-filter-swap="main" id="crm-companies-results" data-crm-filter-empty="<?php p($l->t('No companies match')); ?>" data-crm-filter-done="<?php p($l->t('Search results')); ?>" data-crm-filter-failed="<?php p($l->t('Could not update the list. Press Search.')); ?>">
	<?php
	$duplicateNameKeys = is_array($_['duplicateNameKeys'] ?? null) ? $_['duplicateNameKeys'] : [];
	$duplicateNameCount = count($duplicateNameKeys);
	if (!$filterArchived && $duplicateNameCount > 0):
	?>
	<section class="crm-callout crm-callout--warning crm-semantic-panel crm-semantic-panel--warning" role="status" aria-labelledby="crm-companies-dup-title">
		<div class="crm-callout__body">
			<p class="crm-callout__title" id="crm-companies-dup-title"><?php
				p($l->n(
					'%n company name appears more than once',
					'%n company names appear more than once',
					$duplicateNameCount
				));
			?></p>
			<p class="crm-callout__text"><?php
				if (!empty($_['canDelete'])) {
					p($l->t('Open a twin, then choose Merge into… to keep one company and archive the other. People, deals, and history move to the company you keep.'));
				} else {
					p($l->t('Ask someone with delete permission to open a twin and use Merge into… so one company remains.'));
				}
			?></p>
		</div>
	</section>
	<?php endif; ?>

	<section class="crm-card">
		<header class="crm-card__header"><h2 class="crm-card__title"><?php
			if ($filterQ !== '') {
				p($l->t('Search results'));
			} else {
				p($filterArchived ? $l->t('Archived companies') : $l->t('All companies'));
			}
		?></h2></header>
		<div class="crm-card__body">
			<div class="table-container crm-table--responsive">
				<table class="table table--hover" id="crm-companies-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Name')); ?></th>
						<th scope="col"><?php p($l->t('Owner')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Last touch')); ?></th>
						<th scope="col"><?php p($l->t('Next expiry')); ?></th>
						<th scope="col"><?php p($l->t('Money')); ?></th>
						<th scope="col"><?php p($l->t('Callbacks')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
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
								$nameKey = \OCA\CustomerCheck\Support\CompanyDuplicateLogic::nameKey((string)$c->getName());
								if ($nameKey !== '' && isset($duplicateNameKeys[$nameKey])): ?>
									<span class="crm-badge crm-badge--warning" title="<?php p($l->t('Another active company uses this name — open Merge into… on the company page.')); ?>"><?php p($l->t('Same name')); ?></span>
								<?php endif;
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
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php
									$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => (int)$c->getId()]);
									$crmRowOpenLabel = $l->t('Open');
									include __DIR__ . '/common/crm-row-open.php';
									?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (($_['companies'] ?? []) === []): ?>
						<tr><td class="crm-table-empty" colspan="8">
							<div class="crm-empty-state" role="status">
								<?php if ($filterQ !== '' || $filterStatus !== '' || $filterArchived): ?>
								<h3 class="crm-empty-state__title"><?php p($l->t('No companies match')); ?></h3>
								<p class="crm-empty-state__text"><?php p($l->t('Try another word, or clear the search.')); ?></p>
								<div class="crm-empty-state__actions">
									<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($crmCompaniesHref()); ?>"><?php p($l->t('Clear')); ?></a>
								</div>
								<?php else: ?>
								<h3 class="crm-empty-state__title"><?php p($l->t('No companies yet')); ?></h3>
								<p class="crm-empty-state__text"><?php p($l->t('Add a company or import a list. ProjectCheck and InvoiceCheck are optional — CustomerCheck works alone.')); ?></p>
								<div class="crm-empty-state__actions">
									<?php if (!empty($_['canWrite'])): ?>
									<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($_['urls']['companyNew'] ?? '#'); ?>"><?php p($l->t('Add company')); ?></a>
									<a class="crm-btn crm-btn--secondary" href="<?php p($_['urls']['import'] ?? '#'); ?>"><?php p($l->t('Import')); ?></a>
									<?php endif; ?>
								</div>
								<?php endif; ?>
							</div>
						</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
	</div>
<?php if (!$crmListFragment): ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
<?php endif; ?>
