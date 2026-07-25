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
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-actions-source header-actions" hidden>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-activities-filter-toggle" aria-expanded="<?php p($filterOpen ? 'true' : 'false'); ?>" aria-controls="crm-activities-filter"><?php p($l->t('More filters')); ?></button>
</div>
<div class="crm-page-stack">
	<nav class="crm-quick-filters" aria-label="<?php p($l->t('Quick filters')); ?>">
		<a class="crm-quick-filter<?php if ($filterScope === 'open' && $filterType === ''): ?> is-active<?php endif; ?>" href="<?php p($activitiesUrl); ?>"<?php if ($filterScope === 'open' && $filterType === ''): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Open')); ?></a>
		<a class="crm-quick-filter<?php if ($filterScope === 'done'): ?> is-active<?php endif; ?>" href="<?php p($activitiesUrl); ?>?scope=done"<?php if ($filterScope === 'done'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('Done')); ?></a>
		<a class="crm-quick-filter<?php if ($filterScope === 'all'): ?> is-active<?php endif; ?>" href="<?php p($activitiesUrl); ?>?scope=all"<?php if ($filterScope === 'all'): ?> aria-current="page"<?php endif; ?>><?php p($l->t('All')); ?></a>
	</nav>
	<section id="crm-activities-filter" class="crm-card crm-filter-panel" <?php if (!$filterOpen): ?>hidden<?php endif; ?> aria-labelledby="crm-activities-filter-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-activities-filter-title" class="crm-card__title"><?php p($l->t('Filter activities')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Filter by open/done status and activity type — applies instantly.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
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
						<th><?php p($l->t('Title')); ?></th>
						<th><?php p($l->t('Company')); ?></th>
						<th><?php p($l->t('Type')); ?></th>
						<th><?php p($l->t('Due')); ?></th>
						<th><?php p($l->t('Origin')); ?></th>
						<th class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($rows as $act): ?>
						<tr>
							<td data-label="<?php p($l->t('Title')); ?>"><?php p($act['title']); ?></td>
							<td data-label="<?php p($l->t('Company')); ?>">
								<a href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $act['companyId']])); ?>"><?php p($act['companyName'] ?? $l->t('Unknown company')); ?></a>
							</td>
							<td data-label="<?php p($l->t('Type')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->activityType((string)$act['type'])
									: (string)$act['type']);
							?></span></td>
							<td data-label="<?php p($l->t('Due')); ?>"><?php p($act['dueDate'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Origin')); ?>"><?php p(($act['origin'] === 'system' || str_starts_with((string)$act['origin'], 'sequence_')) ? $l->t('Auto') : $l->t('User')); ?></td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if (empty($act['doneAt'])): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-complete-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Done')); ?></button>
									<?php else: ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-undo-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Undo')); ?></button>
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
				<h3 class="crm-empty-state__title"><?php p($l->t('Inbox is clear')); ?></h3>
				<p class="crm-empty-state__text"><?php p($l->t('No activities match this filter.')); ?></p>
			</div>
		</div>
	</section>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
