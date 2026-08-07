<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
$pipelineView = $_['pipelineView'] ?? 'table';
$stageFilter = (string)($_['stageFilter'] ?? '');
$periodFilter = (string)($_['periodFilter'] ?? '');
$periodFrom = (string)($_['periodFrom'] ?? '');
$periodTo = (string)($_['periodTo'] ?? '');
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
$forecastDisclaimer = (string)($_['forecastDisclaimer'] ?? $l->t('Planning labels — not expected cash.'));
$forecastBucketOptions = $_['forecastBucketOptions'] ?? [];
$lossReasonCodeOptions = $_['lossReasonCodeOptions'] ?? [];
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$labels = $_['labels'] ?? null;
$canWrite = !empty($_['canWrite']);
include __DIR__ . '/common/page-start.php';
?>
<script type="application/json" id="crm-pipeline-companies"><?php
	echo json_encode($companyOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<script type="application/json" id="crm-pipeline-forecast-options"><?php
	echo json_encode($forecastBucketOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<script type="application/json" id="crm-pipeline-loss-options"><?php
	echo json_encode($lossReasonCodeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<div class="crm-page-actions-source header-actions" hidden>
	<div class="crm-btn-group" role="group" aria-label="<?php p($l->t('Pipeline view')); ?>">
		<button type="button" class="crm-btn crm-btn--sm <?php p($pipelineView === 'table' ? 'crm-btn--primary' : 'crm-btn--secondary'); ?>" data-crm-pipeline-view="table"><?php p($l->t('Table')); ?></button>
		<button type="button" class="crm-btn crm-btn--sm <?php p($pipelineView === 'board' ? 'crm-btn--primary' : 'crm-btn--secondary'); ?>" data-crm-pipeline-view="board"><?php p($l->t('Board')); ?></button>
	</div>
	<div class="crm-btn-group" role="group" aria-label="<?php p($l->t('Period')); ?>">
		<?php
		$periodChips = [
			'' => $l->t('All'),
			'month' => $l->t('This month'),
			'quarter' => $l->t('This quarter'),
		];
		foreach ($periodChips as $pval => $plabel):
			$active = $periodFilter === $pval;
			$href = $urlGenerator->linkToRoute('customercheck.page.pipeline', array_filter([
				'view' => $pipelineView !== 'table' ? $pipelineView : null,
				'stage' => $stageFilter !== '' ? $stageFilter : null,
				'period' => $pval !== '' ? $pval : null,
				'workspaceId' => $_['workspaceId'] ?? null,
			], static fn ($v) => $v !== null && $v !== ''));
			?>
		<a class="crm-btn crm-btn--sm <?php p($active ? 'crm-btn--primary' : 'crm-btn--secondary'); ?>"
			href="<?php p($href); ?>"
			aria-current="<?php p($active ? 'true' : 'false'); ?>"
			style="min-height:44px"><?php p($plabel); ?></a>
		<?php endforeach; ?>
		<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" id="crm-pipeline-period-custom"
			aria-expanded="<?php p($periodFilter === 'custom' ? 'true' : 'false'); ?>"
			aria-controls="crm-pipeline-filter"
			style="min-height:44px"><?php p($l->t('Custom')); ?></button>
	</div>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-pipeline-filter-toggle" aria-expanded="false" aria-controls="crm-pipeline-filter"><?php p($l->t('Filter')); ?></button>
	<?php if (!empty($_['canWrite'])): ?>
	<button type="button" class="crm-btn crm-btn--primary" id="crm-pipeline-new-deal"><?php p($l->t('New deal')); ?></button>
	<?php endif; ?>
</div>
<div class="crm-page-stack">
	<p class="crm-muted" role="status" id="crm-forecast-disclaimer"><?php p($forecastDisclaimer); ?></p>

	<section id="crm-pipeline-filter" class="crm-card crm-filter-panel" <?php if ($stageFilter === '' && $periodFilter === ''): ?>hidden<?php endif; ?> aria-labelledby="crm-pipeline-filter-title">
		<header class="crm-filter-panel__head">
			<h2 id="crm-pipeline-filter-title"><?php p($l->t('Filter deals')); ?></h2>
			<p class="crm-filter-panel__intro"><?php p($l->t('Narrow by stage or period, then Apply.')); ?></p>
		</header>
		<div class="crm-filter-panel__body">
			<form method="get" class="crm-filter-panel__form form" action="<?php p($urlGenerator->linkToRoute('customercheck.page.pipeline')); ?>" novalidate>
				<div class="crm-filter-grid" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
					<div class="crm-filter-field">
						<label for="crm-filter-stage" class="form-label"><?php p($l->t('Stage')); ?></label>
						<select id="crm-filter-stage" name="stage" class="form-select crm-input">
							<option value=""><?php p($l->t('All stages')); ?></option>
							<?php foreach ($stages as $s): ?>
								<option value="<?php p($s); ?>" <?php if ($stageFilter === $s): ?>selected<?php endif; ?>><?php p($stageLabels[$s]); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-period" class="form-label"><?php p($l->t('Period')); ?></label>
						<select id="crm-filter-period" name="period" class="form-select crm-input">
							<option value=""><?php p($l->t('Any time')); ?></option>
							<option value="month" <?php if ($periodFilter === 'month'): ?>selected<?php endif; ?>><?php p($l->t('This month')); ?></option>
							<option value="quarter" <?php if ($periodFilter === 'quarter'): ?>selected<?php endif; ?>><?php p($l->t('This quarter')); ?></option>
							<option value="year" <?php if ($periodFilter === 'year'): ?>selected<?php endif; ?>><?php p($l->t('This year')); ?></option>
							<option value="custom" <?php if ($periodFilter === 'custom'): ?>selected<?php endif; ?>><?php p($l->t('Custom range')); ?></option>
						</select>
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-from" class="form-label"><?php p($l->t('From')); ?></label>
						<input type="date" id="crm-filter-from" name="from" class="form-input crm-input" value="<?php p($periodFrom); ?>">
					</div>
					<div class="crm-filter-field">
						<label for="crm-filter-to" class="form-label"><?php p($l->t('To')); ?></label>
						<input type="date" id="crm-filter-to" name="to" class="form-input crm-input" value="<?php p($periodTo); ?>">
					</div>
					<div class="crm-filter-field crm-filter-field--actions">
						<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Apply')); ?></button>
						<a class="crm-btn crm-btn--secondary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.pipeline')); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
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
							<th scope="col"><?php p($l->t('Title')); ?></th>
							<th scope="col"><?php p($l->t('Company')); ?></th>
							<th scope="col"><?php p($l->t('Value')); ?></th>
							<th scope="col"><?php p($l->t('Stage')); ?></th>
							<th scope="col" class="crm-table-actions-col"><?php p($l->t('More')); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach (($_['deals'] ?? []) as $d):
						$quoteAge = $d['quoteAgeDays'] ?? null;
						$quoteSent = (string)($d['quoteSentOn'] ?? '');
						$forecast = (string)($d['forecastBucket'] ?? '');
						$owner = (string)($d['ownerUserId'] ?? '');
						$ownerLabel = (string)($d['ownerDisplayName'] ?? $owner);
						?>
						<tr data-deal-id="<?php p($d['id']); ?>"
							data-company-id="<?php p($d['companyId']); ?>"
							data-value-cents="<?php p($d['valueCents'] ?? 0); ?>"
							data-products="<?php p($d['products'] ?? ''); ?>"
							data-owner-user-id="<?php p($owner); ?>"
							data-owner-display-name="<?php p($ownerLabel); ?>"
							data-quote-sent-on="<?php p($quoteSent); ?>"
							data-quote-file-id="<?php p($d['quoteFileId'] ?? ''); ?>"
							data-forecast-bucket="<?php p($forecast); ?>"
							data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>"
							data-title="<?php p($d['title']); ?>">
							<td data-label="<?php p($l->t('Title')); ?>">
								<?php p($d['title']); ?>
								<?php if (!empty($d['stale'])): ?> <span class="crm-badge crm-badge--warning"><?php p($l->t('Stale')); ?></span><?php endif; ?>
								<details class="crm-more-menu crm-pipeline-deal-meta">
									<summary class="crm-btn crm-btn--ghost crm-btn--sm"><?php p($l->t('Details')); ?></summary>
									<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('Deal details')); ?>">
										<p class="crm-muted"><strong><?php p($l->t('Owner')); ?>:</strong> <?php p($ownerLabel !== '' ? $ownerLabel : '—'); ?></p>
										<p class="crm-muted"><strong><?php p($l->t('Quote age')); ?>:</strong>
											<?php if ($quoteAge !== null): ?>
												<?php p($l->t('%s days', [(string)(int)$quoteAge])); ?>
											<?php else: ?>
												—
											<?php endif; ?>
										</p>
										<p class="crm-muted"><strong><?php p($l->t('Forecast')); ?>:</strong>
											<?php
											if ($forecast !== '' && $labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
												p($labels->forecastBucket($forecast));
											} elseif ($forecast !== '') {
												p($forecast);
											} else {
												p('—');
											}
											?>
										</p>
									</div>
								</details>
							</td>
							<td data-label="<?php p($l->t('Company')); ?>">
								<?php
								$crmCompanyLinkId = (int)($d['companyId'] ?? 0);
								$crmCompanyLinkLabel = (string)($d['companyName'] ?? '');
								include __DIR__ . '/common/crm-company-link.php';
								?>
							</td>
							<td data-label="<?php p($l->t('Value')); ?>"><?php
								$cents = (int)($d['valueCents'] ?? 0);
								p($cents > 0 ? number_format($cents / 100, 2) . ' €' : '—');
							?></td>
							<td data-label="<?php p($l->t('Stage')); ?>">
								<?php if ($canWrite): ?>
								<label class="crm-sr-only" for="deal-stage-<?php p($d['id']); ?>"><?php p($l->t('Move to stage')); ?></label>
								<select id="deal-stage-<?php p($d['id']); ?>" class="crm-input" data-crm-deal-stage="<?php p($d['id']); ?>" data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>" <?php if (in_array($d['stage'], ['won', 'lost'], true)): ?>disabled<?php endif; ?>>
									<?php foreach ($stages as $s): ?>
										<option value="<?php p($s); ?>" <?php if ($s === $d['stage']): ?>selected<?php endif; ?>><?php p($stageLabels[$s]); ?></option>
									<?php endforeach; ?>
								</select>
								<?php else: ?>
									<span class="crm-badge"><?php p($stageLabels[$d['stage']] ?? (string)$d['stage']); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('More')); ?>" class="actions-cell crm-table-actions-col">
								<?php if ($canWrite): ?>
								<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-edit-chase="<?php p($d['id']); ?>" <?php if (in_array($d['stage'], ['won', 'lost'], true)): ?>disabled<?php endif; ?>><?php p($l->t('Edit deal')); ?></button>
								<?php else: ?>
									<span class="crm-muted" aria-hidden="true">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (($_['deals'] ?? []) === []): ?>
						<tr><td colspan="5"><div class="crm-callout crm-callout--info" role="status"><p><?php p($l->t('No deals yet. Tap New deal to add one.')); ?></p></div></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="crm-card" id="crm-pipeline-board" <?php if ($pipelineView !== 'board'): ?>hidden<?php endif; ?>>
		<header class="crm-card__header"><h2 class="crm-card__title"><?php p($l->t('Board')); ?></h2></header>
		<div class="crm-card__body">
			<div class="crm-kanban" role="region" aria-label="<?php p($l->t('Deal stages board')); ?>">
			<?php foreach ($stages as $stage):
				$stageDeals = [];
				$stageCents = 0;
				foreach (($_['deals'] ?? []) as $d) {
					if (($d['stage'] ?? '') !== $stage) {
						continue;
					}
					$stageDeals[] = $d;
					$stageCents += (int)($d['valueCents'] ?? 0);
				}
				$stageCount = count($stageDeals);
				$stageSumLabel = $stageCents > 0
					? number_format($stageCents / 100, 2) . ' €'
					: '—';
				$stageAria = $l->t('%1$s, %2$s deals, %3$s', [
					$stageLabels[$stage],
					(string)$stageCount,
					$stageSumLabel,
				]);
				?>
				<section class="crm-kanban__column" role="group" aria-label="<?php p($stageAria); ?>" data-stage="<?php p($stage); ?>" data-crm-stage-count="<?php p((string)$stageCount); ?>" data-crm-stage-cents="<?php p((string)$stageCents); ?>">
					<header class="crm-kanban__column-head">
						<h3 class="crm-kanban__column-title"><?php p($stageLabels[$stage]); ?></h3>
						<p class="crm-kanban__column-meta">
							<span class="crm-kanban__column-count"><?php p((string)$stageCount); ?></span>
							<span class="crm-kanban__column-sum" aria-hidden="true"><?php p($stageSumLabel); ?></span>
						</p>
					</header>
					<ul class="crm-kanban__list">
					<?php foreach ($stageDeals as $d):
						$quoteAge = $d['quoteAgeDays'] ?? null;
						$quoteSent = (string)($d['quoteSentOn'] ?? '');
						$forecast = (string)($d['forecastBucket'] ?? '');
						$owner = (string)($d['ownerUserId'] ?? '');
						$ownerLabel = (string)($d['ownerDisplayName'] ?? $owner);
						?>
						<li class="crm-kanban__card crm-card"
							draggable="<?php p(($canWrite && !in_array($stage, ['won', 'lost'], true)) ? 'true' : 'false'); ?>"
							tabindex="<?php p(($canWrite && !in_array($stage, ['won', 'lost'], true)) ? '0' : '-1'); ?>"
							data-deal-id="<?php p($d['id']); ?>"
							data-crm-stage="<?php p($stage); ?>"
							data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>"
							data-company-id="<?php p($d['companyId']); ?>"
							data-value-cents="<?php p($d['valueCents'] ?? 0); ?>"
							data-products="<?php p($d['products'] ?? ''); ?>"
							data-owner-user-id="<?php p($owner); ?>"
							data-owner-display-name="<?php p($ownerLabel); ?>"
							data-quote-sent-on="<?php p($quoteSent); ?>"
							data-quote-file-id="<?php p($d['quoteFileId'] ?? ''); ?>"
							data-forecast-bucket="<?php p($forecast); ?>"
							data-title="<?php p($d['title']); ?>">
							<strong><?php p($d['title']); ?></strong>
							<?php
							$companyId = (int)($d['companyId'] ?? 0);
							$companyName = (string)($d['companyName'] ?? '');
							if ($companyId > 0 && $companyName !== ''): ?>
								<a class="crm-kanban__card-company" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $companyId])); ?>"><?php p($companyName); ?></a>
							<?php else: ?>
								<span class="crm-muted"><?php p($companyName); ?></span>
							<?php endif; ?>
							<span class="crm-kanban__card-value"><?php
								$cents = (int)($d['valueCents'] ?? 0);
								p($cents > 0 ? number_format($cents / 100, 2) . ' €' : '—');
							?></span>
							<span class="crm-kanban__card-owner<?php p($owner === '' ? ' crm-muted' : ''); ?>">
								<?php
								$initials = '';
								if ($ownerLabel !== '') {
									$parts = preg_split('/\s+/u', trim($ownerLabel)) ?: [];
									foreach (array_slice($parts, 0, 2) as $part) {
										$initials .= mb_strtoupper(mb_substr($part, 0, 1));
									}
								}
								?>
								<span class="crm-avatar" aria-hidden="true"><?php p($initials !== '' ? $initials : '?'); ?></span>
								<span class="crm-sr-only"><?php p($l->t('Owner')); ?>:</span>
								<?php p($ownerLabel !== '' ? $ownerLabel : $l->t('Unassigned')); ?>
							</span>
							<?php
							$dueRaw = (string)($d['expectedClose'] ?? $d['nextActionOn'] ?? '');
							if ($dueRaw !== ''):
								$dueDay = substr($dueRaw, 0, 10);
								$dueDt = \DateTimeImmutable::createFromFormat('Y-m-d', $dueDay) ?: null;
								$today = new \DateTimeImmutable('today');
								$dueLabel = $l->t('Due %s', [
									$labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
										? $labels->formatDate($dueDay)
										: $dueDay,
								]);
								if ($dueDt instanceof \DateTimeImmutable) {
									$days = (int)$today->diff($dueDt)->format('%r%a');
									if ($days === 0) {
										$dueLabel = $l->t('Due today');
									} elseif ($days === 1) {
										$dueLabel = $l->t('Due tomorrow');
									} elseif ($days > 1 && $days < 60) {
										$dueLabel = $l->n('in %n day', 'in %n days', $days);
									} elseif ($days < 0 && $days > -60) {
										$dueLabel = $l->n('%n day past due', '%n days past due', abs($days));
									}
								}
								?>
								<span class="crm-kanban__card-due crm-muted"><?php p($dueLabel); ?></span>
							<?php endif; ?>
							<?php if ($quoteAge !== null): ?>
								<span class="crm-badge <?php echo ((int)$quoteAge >= 14) ? 'crm-badge--warning' : 'crm-badge--neutral'; ?>" title="<?php p($quoteSent); ?>">
									<?php p($l->t('Quote %s days old', [(string)(int)$quoteAge])); ?>
								</span>
							<?php endif; ?>
							<?php if ($forecast !== ''): ?>
								<span class="crm-badge crm-badge--info"><?php
									p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
										? $labels->forecastBucket($forecast)
										: $forecast);
								?></span>
							<?php endif; ?>
							<?php if (!empty($d['stale'])): ?>
								<span class="crm-badge crm-badge--warning" title="<?php p($l->t('No stage change for a while')); ?>"><?php p($l->t('Stale')); ?></span>
							<?php endif; ?>
							<?php
							$filesFolderId = (int)($d['companyFilesFolderId'] ?? $d['filesFolderId'] ?? 0);
							$quoteFileId = (int)($d['quoteFileId'] ?? 0);
							$fileLinkId = $quoteFileId > 0 ? $quoteFileId : $filesFolderId;
							if ($fileLinkId > 0):
								$filesUrl = $urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileLinkId]);
								?>
								<a class="crm-kanban__card-files" href="<?php p($filesUrl); ?>"><?php p($quoteFileId > 0 ? $l->t('Quote file') : $l->t('Files')); ?></a>
							<?php endif; ?>
							<?php if ($canWrite && !in_array($stage, ['won', 'lost'], true)): ?>
							<div class="crm-kanban__stage-control">
								<label class="crm-kanban__stage-label" for="board-stage-<?php p($d['id']); ?>"><?php p($l->t('Stage')); ?></label>
								<select id="board-stage-<?php p($d['id']); ?>"
									class="crm-input crm-kanban__stage-select"
									data-crm-deal-stage="<?php p($d['id']); ?>"
									data-updated-at="<?php p($d['updatedAt'] ?? ''); ?>"
									aria-describedby="board-stage-hint-<?php p($d['id']); ?>">
									<?php foreach ($stages as $s): ?>
										<option value="<?php p($s); ?>" <?php if ($s === $d['stage']): ?>selected<?php endif; ?>><?php p($stageLabels[$s]); ?></option>
									<?php endforeach; ?>
								</select>
								<span id="board-stage-hint-<?php p($d['id']); ?>" class="crm-sr-only"><?php p($l->t('Change stage with this list, or use Left and Right arrow keys when the card is focused.')); ?></span>
							</div>
							<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-edit-chase="<?php p($d['id']); ?>"><?php p($l->t('Edit deal')); ?></button>
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
