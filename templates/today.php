<?php
include __DIR__ . '/common/page-start.php';
$kindLabels = [
	'agreement_expiry' => $l->t('Renewal'),
	'ticket_waiting' => $l->t('Ticket'),
	'activity_overdue' => $l->t('Overdue'),
	'activity_due' => $l->t('Due'),
];
$companyCount = (int)($_['companyCount'] ?? 0);
$queueItems = $_['queue']['items'] ?? [];
?>
<div class="crm-page-stack">
	<section class="crm-card" aria-labelledby="crm-today-title">
		<header class="crm-card__header">
			<h2 id="crm-today-title" class="crm-card__title"><?php p($l->t('Needs attention')); ?></h2>
			<p class="crm-card__lead crm-muted"><?php p($l->t('Renewals, waiting tickets and activities due today — most urgent first. Mark activities done without leaving this list.')); ?></p>
		</header>
		<div class="crm-card__body">
			<?php if ($queueItems === [] && $companyCount === 0): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Start with your first customer')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Import a list or create one company — Today will then show renewals and follow-ups here.')); ?></p>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($_['urls']['companyNew'] ?? '#'); ?>"><?php p($l->t('New company')); ?></a>
						<a class="crm-btn crm-btn--secondary" href="<?php p($_['urls']['import'] ?? '#'); ?>"><?php p($l->t('Import')); ?></a>
					</div>
				</div>
			<?php elseif ($queueItems === []): ?>
				<div class="crm-callout crm-callout--success" role="status">
					<p><?php p($l->t('Nothing urgent today. Enjoy the calm.')); ?></p>
				</div>
			<?php else: ?>
				<ul class="crm-list crm-list--today" role="list">
				<?php foreach ($queueItems as $item):
					$kind = (string)($item['kind'] ?? '');
					$chip = $kindLabels[$kind] ?? $kind;
					$companyId = (int)($item['companyId'] ?? 0);
					$activityId = (int)($item['activityId'] ?? 0);
					$isActivity = $activityId > 0 && ($kind === 'activity_overdue' || $kind === 'activity_due');
					$deepLink = (string)($item['deepLink'] ?? '');
					$openHref = $companyId > 0
						? $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $companyId])
						: ($deepLink !== '' ? $deepLink : '');
					?>
					<li class="crm-list__item crm-list__item--today<?php if ($openHref !== '') { ?> crm-list__item--link<?php } ?>">
						<div class="crm-list__today-main">
						<?php if ($openHref !== ''): ?>
							<a class="crm-list__row-link" href="<?php p($openHref); ?>">
								<span class="crm-badge" title="<?php p($kind); ?>"><?php p($chip); ?></span>
								<span class="crm-list__main">
									<strong><?php p($item['label'] ?? ''); ?></strong>
									<?php if (!empty($item['companyName'])): ?>
										<span class="crm-muted"> · <?php p($item['companyName']); ?></span>
									<?php endif; ?>
									<?php if (!empty($item['dueDate'])): ?>
										<span class="crm-muted"> · <?php p($item['dueDate']); ?></span>
									<?php endif; ?>
								</span>
							</a>
						<?php else: ?>
							<span class="crm-badge" title="<?php p($kind); ?>"><?php p($chip); ?></span>
							<span class="crm-list__main">
								<strong><?php p($item['label'] ?? ''); ?></strong>
								<?php if (!empty($item['companyName'])): ?>
									<span class="crm-muted"> · <?php p($item['companyName']); ?></span>
								<?php endif; ?>
							</span>
						<?php endif; ?>
						</div>
						<?php if ($isActivity): ?>
							<div class="crm-list__today-actions">
								<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-complete-activity="<?php p($activityId); ?>" aria-label="<?php p($l->t('Mark done: %s', [$item['label'] ?? ''])); ?>"><?php p($l->t('Done')); ?></button>
							</div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
				</ul>
				<?php if (!empty($_['queue']['meta']['truncated'])): ?>
					<p class="crm-muted" role="note"><?php p($l->t('Showing the top 100 items. Clear older activities to see more.')); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
