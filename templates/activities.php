<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$filterType = (string)($_['filterType'] ?? '');
$filterScope = (string)($_['filterScope'] ?? 'open');
$groups = $_['groups'] ?? ['overdue' => [], 'today' => [], 'later' => [], 'done' => []];
$filterOpen = ($filterType !== '' || $filterScope !== 'open');
$labels = $_['labels'] ?? null;
$activitiesUrl = $urlGenerator->linkToRoute('customercheck.page.activities');
$canWrite = !empty($_['canWrite']);
$crmListFragment = !empty($_['crmListFragment']);
if (!$crmListFragment) {
	include __DIR__ . '/common/page-start.php';
}
?>
<?php if (!$crmListFragment): ?>
<div class="crm-page-actions-source header-actions" hidden>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-activities-filter-toggle" aria-expanded="<?php p($filterOpen ? 'true' : 'false'); ?>" aria-controls="crm-activities-filter"><?php p($l->t('More filters')); ?></button>
</div>
<div class="crm-page-stack">
<?php endif; ?>
	<nav class="crm-quick-filters" data-crm-filter-swap="nav" aria-label="<?php p($l->t('Quick filters')); ?>">
		<a class="crm-quick-filter<?php if ($filterScope === 'open' && $filterType === ''): ?> is-active<?php endif; ?>" href="<?php p($activitiesUrl); ?>"<?php if ($filterScope === 'open' && $filterType === ''): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Open')); ?></a>
		<a class="crm-quick-filter<?php if ($filterScope === 'done'): ?> is-active<?php endif; ?>" href="<?php p($activitiesUrl); ?>?scope=done"<?php if ($filterScope === 'done'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Done')); ?></a>
		<a class="crm-quick-filter<?php if ($filterScope === 'all'): ?> is-active<?php endif; ?>" href="<?php p($activitiesUrl); ?>?scope=all"<?php if ($filterScope === 'all'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('All')); ?></a>
	</nav>
<?php if (!$crmListFragment): ?>
	<section id="crm-activities-filter" class="crm-card crm-filter-panel" <?php if (!$filterOpen): ?>hidden<?php endif; ?> aria-labelledby="crm-activities-filter-title">
		<header class="crm-filter-panel__head">
			<h2 id="crm-activities-filter-title"><?php p($l->t('Filter activities')); ?></h2>
			<p class="crm-filter-panel__intro"><?php p($l->t('Filter by open/done status and activity type — applies instantly.')); ?></p>
		</header>
		<div class="crm-filter-panel__body">
			<form method="get" class="crm-filter-panel__form form" action="<?php p($activitiesUrl); ?>" data-crm-live-filter novalidate>
				<div class="crm-filter-grid" role="group">
					<div class="crm-filter-field">
						<label for="crm-filter-scope" class="form-label"><?php p($l->t('Status')); ?></label>
						<select id="crm-filter-scope" name="scope" class="form-select crm-input">
							<option value="open" <?php if ($filterScope === 'open'): ?>selected<?php endif; ?>><?php p($l->t('Open')); ?></option>
							<option value="done" <?php if ($filterScope === 'done'): ?>selected<?php endif; ?>><?php p($l->t('Done')); ?></option>
							<option value="all" <?php if ($filterScope === 'all'): ?>selected<?php endif; ?>><?php p($l->t('All')); ?></option>
						</select>
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-type" class="form-label"><?php p($l->t('Type')); ?></label>
						<select id="crm-filter-type" name="type" class="form-select crm-input">
							<option value=""><?php p($l->t('All types')); ?></option>
							<?php foreach (['call', 'email', 'meeting', 'todo'] as $t): ?>
								<option value="<?php p($t); ?>" <?php if ($filterType === $t): ?>selected<?php endif; ?>><?php
									p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->activityType($t) : $t);
								?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="crm-filter-field crm-filter-field--actions">
						<button type="submit" class="crm-btn crm-btn--primary" id="btn-apply-filter"><?php p($l->t('Apply')); ?></button>
						<a class="crm-btn crm-btn--secondary" id="btn-clear-filter" href="<?php p($activitiesUrl); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
			</form>
		</div>
	</section>
<?php endif; ?>
	<div data-crm-filter-swap="main" id="crm-activities-results" data-crm-filter-empty="<?php p($l->t('Nothing open right now')); ?>" data-crm-filter-done="<?php p($l->t('List updated.')); ?>" data-crm-filter-failed="<?php p($l->t('Could not update the list. Try again.')); ?>">
	<?php
	$sections = [
		'overdue' => $l->t('Overdue'),
		'today' => $l->t('Today'),
		'later' => $l->t('Later'),
		'done' => $l->t('Done'),
	];
	$any = false;
	foreach ($sections as $key => $title):
		$rows = $groups[$key] ?? [];
		if ($rows === []) {
			continue;
		}
		$any = true;
	?>
	<section class="crm-card">
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($title); ?></h2></header>
		<div class="crm-card__body">
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Company')); ?></th>
						<th scope="col"><?php p($l->t('Type')); ?></th>
						<th scope="col"><?php p($l->t('Due')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($rows as $act): ?>
						<tr>
							<td data-label="<?php p($l->t('Title')); ?>"><?php p($act['title']); ?></td>
							<td data-label="<?php p($l->t('Company')); ?>">
								<?php
								$crmCompanyLinkId = (int)($act['companyId'] ?? 0);
								$crmCompanyLinkLabel = (string)($act['companyName'] ?? '');
								include __DIR__ . '/common/crm-company-link.php';
								?>
							</td>
							<td data-label="<?php p($l->t('Type')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->activityType((string)$act['type'])
									: (string)$act['type']);
							?></span></td>
							<td data-label="<?php p($l->t('Due')); ?>"><?php
								$dueRaw = (string)($act['dueDate'] ?? '');
								if ($dueRaw === '') {
									p('—');
								} elseif ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
									p($labels->formatDate($dueRaw));
								} else {
									p($dueRaw);
								}
							?></td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php
									$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => (int)($act['companyId'] ?? 0)]);
									$crmRowOpenLabel = $l->t('Open');
									include __DIR__ . '/common/crm-row-open.php';
									?>
									<?php if ($canWrite): ?>
										<?php if (empty($act['doneAt'])): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-complete-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Done')); ?></button>
										<?php else: ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-undo-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Undo')); ?></button>
										<?php endif; ?>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
	<?php endforeach; ?>
	<?php if (!$any): ?>
	<section class="crm-card">
		<div class="crm-card__body">
			<div class="crm-empty-state" role="status">
				<h3 class="crm-empty-state__title"><?php p($l->t('Nothing open right now')); ?></h3>
				<p class="crm-empty-state__text"><?php p($l->t('Open a company to add a call, reminder, or to-do — then it shows up here and on Today.')); ?></p>
				<div class="crm-empty-state__actions">
					<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companies')); ?>"><?php p($l->t('Open companies')); ?></a>
					<a class="crm-btn crm-btn--secondary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.today')); ?>"><?php p($l->t('Open Today')); ?></a>
				</div>
			</div>
		</div>
	</section>
	<?php endif; ?>
	</div>
<?php if (!$crmListFragment): ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
<?php endif; ?>
