<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
$pipelineView = $_['pipelineView'] ?? 'table';
$stageFilter = (string)($_['stageFilter'] ?? '');
$stages = ['lead', 'contacted', 'quoted', 'negotiation', 'won', 'lost'];
$stageLabels = [
	'lead' => $l->t('Lead'),
	'contacted' => $l->t('Contacted'),
	'quoted' => $l->t('Quoted'),
	'negotiation' => $l->t('Negotiation'),
	'won' => $l->t('Won'),
	'lost' => $l->t('Lost'),
];
$companyOptions = $_['companyOptions'] ?? [];
include __DIR__ . '/common/page-start.php';
?>
<script type="application/json" id="crm-pipeline-companies"><?php
	echo json_encode($companyOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<div class="crm-page-actions-source header-actions" hidden>
	<div class="crm-btn-group" role="group" aria-label="<?php p($l->t('Pipeline view')); ?>">
		<button type="button" class="crm-btn crm-btn--sm <?php p($pipelineView === 'table' ? 'crm-btn--primary' : 'crm-btn--secondary'); ?>" data-crm-pipeline-view="table"><?php p($l->t('Table')); ?></button>
		<button type="button" class="crm-btn crm-btn--sm <?php p($pipelineView === 'board' ? 'crm-btn--primary' : 'crm-btn--secondary'); ?>" data-crm-pipeline-view="board"><?php p($l->t('Board')); ?></button>
	</div>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-pipeline-filter-toggle" aria-expanded="false" aria-controls="crm-pipeline-filter"><?php p($l->t('Filter')); ?></button>
	<button type="button" class="crm-btn crm-btn--primary" id="crm-pipeline-new-deal"><?php p($l->t('New deal')); ?></button>
</div>
<div class="crm-page-stack">
	<section id="crm-pipeline-filter" class="crm-card crm-filter-panel" <?php if ($stageFilter === ''): ?>hidden<?php endif; ?>>
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($l->t('Filter deals')); ?></h2></header>
		<div class="crm-card__body">
			<form method="get" class="crm-form" action="<?php p($urlGenerator->linkToRoute('customercheck.page.pipeline')); ?>">
				<label for="crm-filter-stage" class="form-label"><?php p($l->t('Stage')); ?></label>
				<select id="crm-filter-stage" name="stage" class="form-select crm-input">
					<option value=""><?php p($l->t('All stages')); ?></option>
					<?php foreach ($stages as $s): ?>
						<option value="<?php p($s); ?>" <?php if ($stageFilter === $s): ?>selected<?php endif; ?>><?php p($stageLabels[$s]); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Apply')); ?></button>
					<a class="crm-btn crm-btn--secondary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.pipeline')); ?>"><?php p($l->t('Clear')); ?></a>
				</div>
			</form>
		</div>
	</section>

	<section class="crm-card" id="crm-pipeline-table" <?php if ($pipelineView === 'board'): ?>hidden<?php endif; ?>>
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($l->t('Deals')); ?></h2></header>
		<div class="crm-card__body">
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead>
						<tr>
							<th><?php p($l->t('Title')); ?></th>
							<th><?php p($l->t('Company')); ?></th>
							<th><?php p($l->t('Stage')); ?></th>
							<th><?php p($l->t('Value')); ?></th>
							<th><?php p($l->t('Move')); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach (($_['deals'] ?? []) as $d): ?>
						<tr data-deal-id="<?php p($d['id']); ?>" data-company-id="<?php p($d['companyId']); ?>" data-value-cents="<?php p($d['valueCents'] ?? 0); ?>" data-products="<?php p($d['products'] ?? ''); ?>">
							<td data-label="<?php p($l->t('Title')); ?>">
								<?php p($d['title']); ?>
								<?php if (!empty($d['stale'])): ?> <span class="crm-badge crm-badge--warning"><?php p($l->t('Stale')); ?></span><?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Company')); ?>">
								<a href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $d['companyId']])); ?>"><?php p($d['companyName'] ?? ''); ?></a>
							</td>
							<td data-label="<?php p($l->t('Stage')); ?>"><span class="crm-badge"><?php p($stageLabels[$d['stage']] ?? $d['stage']); ?></span></td>
							<td data-label="<?php p($l->t('Value')); ?>"><?php
								$cents = (int)($d['valueCents'] ?? 0);
								p($cents > 0 ? number_format($cents / 100, 2) . ' €' : '—');
							?></td>
							<td data-label="<?php p($l->t('Move')); ?>">
								<label class="crm-sr-only" for="deal-stage-<?php p($d['id']); ?>"><?php p($l->t('Move to stage')); ?></label>
								<select id="deal-stage-<?php p($d['id']); ?>" class="crm-input" data-crm-deal-stage="<?php p($d['id']); ?>" data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>" <?php if (in_array($d['stage'], ['won', 'lost'], true)): ?>disabled<?php endif; ?>>
									<?php foreach ($stages as $s): ?>
										<option value="<?php p($s); ?>" <?php if ($s === $d['stage']): ?>selected<?php endif; ?>><?php p($stageLabels[$s]); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (($_['deals'] ?? []) === []): ?>
						<tr><td colspan="5"><div class="crm-callout crm-callout--info" role="status"><p><?php p($l->t('No deals yet. Create one from a company page or the website lead webhook.')); ?></p></div></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="crm-card" id="crm-pipeline-board" <?php if ($pipelineView !== 'board'): ?>hidden<?php endif; ?>>
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($l->t('Board')); ?></h2></header>
		<div class="crm-card__body">
			<div class="crm-kanban" role="list">
			<?php foreach ($stages as $stage): ?>
				<section class="crm-kanban__column" role="group" aria-label="<?php p($stageLabels[$stage]); ?>" data-stage="<?php p($stage); ?>">
					<h3><?php p($stageLabels[$stage]); ?></h3>
					<ul class="crm-kanban__list">
					<?php foreach (($_['deals'] ?? []) as $d): if ($d['stage'] !== $stage) continue; ?>
						<li class="crm-kanban__card crm-card"
							draggable="<?php p(in_array($stage, ['won', 'lost'], true) ? 'false' : 'true'); ?>"
							data-deal-id="<?php p($d['id']); ?>"
							data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>"
							data-company-id="<?php p($d['companyId']); ?>"
							data-value-cents="<?php p($d['valueCents'] ?? 0); ?>"
							data-products="<?php p($d['products'] ?? ''); ?>"
							data-title="<?php p($d['title']); ?>">
							<strong><?php p($d['title']); ?></strong>
							<span class="crm-muted"><?php p($d['companyName'] ?? ''); ?></span>
							<span><?php
								$cents = (int)($d['valueCents'] ?? 0);
								p($cents > 0 ? number_format($cents / 100, 2) . ' €' : '—');
							?></span>
							<?php if (!empty($d['stale'])): ?>
								<span class="crm-badge crm-badge--warning" title="<?php p($l->t('No stage change for a while')); ?>"><?php p($l->t('Stale')); ?></span>
							<?php endif; ?>
							<?php if (!in_array($stage, ['won', 'lost'], true)): ?>
							<label class="crm-sr-only" for="board-stage-<?php p($d['id']); ?>"><?php p($l->t('Move to stage')); ?></label>
							<select id="board-stage-<?php p($d['id']); ?>" class="crm-input crm-input--sm" data-crm-deal-stage="<?php p($d['id']); ?>" data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>">
								<?php foreach ($stages as $s): ?>
									<option value="<?php p($s); ?>" <?php if ($s === $d['stage']): ?>selected<?php endif; ?>><?php p($stageLabels[$s]); ?></option>
								<?php endforeach; ?>
							</select>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
			</div>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
