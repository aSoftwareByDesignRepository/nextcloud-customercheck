<?php
include __DIR__ . '/common/page-start.php';
$companyCount = (int)($_['companyCount'] ?? 0);
$board = $_['commandBoard'] ?? null;
$queueItems = $_['queue']['items'] ?? [];
$sections = is_array($board) ? ($board['sections'] ?? []) : [];
$canWrite = !empty($_['canWrite']);
$roleSlug = (string)($_['roleSlug'] ?? 'member');
$roleLabel = (string)($_['roleLabel'] ?? '');
$urls = $_['urls'] ?? [];

$totalItems = 0;
foreach ($sections as $sec) {
	$totalItems += count($sec['items'] ?? []);
}
if ($sections === [] && $queueItems !== []) {
	$totalItems = count($queueItems);
}

$roleHelp = match ($roleSlug) {
	'viewer', 'crm-user' => $l->t('Read-only view. Open a company to inspect — editing is locked for viewers.'),
	'manager', 'app-admin' => $l->t('Your queues plus team stuck. Jump to a section, then take the one action in the row.'),
	default => $l->t('Your chase, touches, and tasks. Open a row or mark Done — nothing else required.'),
};

$ctaLabel = static function (string $cta) use ($l): string {
	return match ($cta) {
		'done' => $l->t('Done'),
		'claim' => $l->t('Claim'),
		'triage' => $l->t('Triage'),
		'coach' => $l->t('Coach'),
		default => $l->t('Open'),
	};
};

$resolveOpenHref = static function (array $item) use ($urlGenerator, $urls): string {
	$companyId = (int)($item['companyId'] ?? 0);
	if ($companyId > 0) {
		return $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $companyId]);
	}
	$cta = (string)($item['cta'] ?? 'open');
	if ($cta === 'triage') {
		return (string)($urls['mailTriage'] ?? '#');
	}
	if ($cta === 'coach') {
		return (string)($urls['team'] ?? '#');
	}
	$deep = trim((string)($item['deepLink'] ?? ''));
	return $deep !== '' ? $deep : '';
};

$renderTodayTable = static function (array $items, bool $allowDone) use ($l, $urlGenerator, $ctaLabel, $resolveOpenHref): void {
	?>
	<div class="table-container crm-table--responsive" role="region" aria-label="<?php p($l->t('Attention queue')); ?>">
		<table class="table table--hover">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('What')); ?></th>
					<th scope="col"><?php p($l->t('Company')); ?></th>
					<th scope="col"><?php p($l->t('Due')); ?></th>
					<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($items as $item):
				$activityId = (int)($item['activityId'] ?? 0);
				$cta = (string)($item['cta'] ?? ($activityId > 0 ? 'done' : 'open'));
				$why = trim((string)($item['why'] ?? $item['label'] ?? ''));
				$companyName = trim((string)($item['companyName'] ?? ''));
				$companyId = (int)($item['companyId'] ?? 0);
				$due = trim((string)($item['dueDate'] ?? ''));
				$openHref = $resolveOpenHref($item);
				$kind = trim((string)($item['kind'] ?? ''));
				$kindLabel = trim((string)($item['kindLabel'] ?? ''));
				?>
				<tr class="crm-today-row">
					<td data-label="<?php p($l->t('What')); ?>">
						<?php if ($kindLabel !== ''): ?>
							<span class="crm-badge crm-badge--neutral"><?php p($kindLabel); ?></span>
						<?php endif; ?>
						<span class="crm-today-what"><?php p($why !== '' ? $why : $l->t('Open item')); ?></span>
						<?php
						$reasonLabel = trim((string)($item['reasonLabel'] ?? ''));
						if ($reasonLabel !== ''): ?>
							<span class="crm-badge crm-badge--warning"><?php p($reasonLabel); ?></span>
						<?php endif; ?>
						<?php if (!empty($item['covering'])): ?>
							<span class="crm-badge crm-badge--info" role="status"><?php
								p($item['coveringBadge'] ?? $l->t('Covering'));
								if (!empty($item['coveringPrimary'])) {
									print_unescaped(' ');
									p((string)$item['coveringPrimary']);
								}
							?></span>
						<?php endif; ?>
					</td>
					<td data-label="<?php p($l->t('Company')); ?>"><?php
						$crmCompanyLinkId = $companyId;
						$crmCompanyLinkLabel = $companyName !== '' ? $companyName : ($companyId > 0 ? $l->t('Company') : '—');
						include __DIR__ . '/common/crm-company-link.php';
					?></td>
					<td data-label="<?php p($l->t('Due')); ?>" class="crm-today-due"><?php p($due !== '' ? $due : '—'); ?></td>
					<td data-label="<?php p($l->t('Action')); ?>" class="crm-table-actions-col">
						<div class="crm-table-actions">
							<?php if ($activityId > 0 && $allowDone): ?>
								<button type="button"
									class="crm-btn crm-btn--sm crm-btn--primary"
									data-crm-complete-activity="<?php p($activityId); ?>"
									data-updated-at="<?php p($item['updatedAt'] ?? ''); ?>">
									<?php p($l->t('Done')); ?>
								</button>
							<?php elseif ($cta === 'claim' && $allowDone && (int)($item['dealId'] ?? 0) > 0): ?>
								<button type="button"
									class="crm-btn crm-btn--sm crm-btn--primary"
									data-crm-claim-deal="<?php p((int)$item['dealId']); ?>"
									data-updated-at="<?php p($item['updatedAt'] ?? ''); ?>"
									<?php if ($openHref !== ''): ?>data-open-href="<?php p($openHref); ?>"<?php endif; ?>>
									<?php p($l->t('Claim')); ?>
								</button>
							<?php elseif ($openHref !== ''): ?>
								<a class="crm-btn crm-btn--sm crm-btn--primary" href="<?php p($openHref); ?>">
									<?php p($ctaLabel($cta)); ?>
								</a>
							<?php else: ?>
								<span class="crm-muted">—</span>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
};
?>
<div id="crm-live-region" class="crm-sr-only" aria-live="polite"></div>
<div class="crm-page-stack">
	<?php if ($sections !== [] || $queueItems !== []): ?>
		<section class="crm-card crm-today-role" aria-labelledby="crm-today-role-title">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h2 id="crm-today-role-title" class="crm-card__title"><?php p($l->t('Your focus')); ?></h2>
					<p class="crm-card__lead"><?php p($roleHelp); ?></p>
				</div>
				<?php if ($roleLabel !== ''): ?>
					<span class="crm-badge crm-badge--info" aria-label="<?php p($l->t('Role')); ?>"><?php p($roleLabel); ?></span>
				<?php endif; ?>
			</header>
			<div class="crm-card__body">
				<div class="crm-today-role__stats" role="group" aria-label="<?php p($l->t('Attention summary')); ?>">
					<div class="crm-today-stat">
						<span class="crm-today-stat__value"><?php p((string)$totalItems); ?></span>
						<span class="crm-today-stat__label"><?php p($l->t('Needs you')); ?></span>
					</div>
					<div class="crm-today-stat">
						<span class="crm-today-stat__value"><?php p((string)count($sections !== [] ? $sections : ($queueItems !== [] ? [1] : []))); ?></span>
						<span class="crm-today-stat__label"><?php p($l->t('Queues')); ?></span>
					</div>
				</div>
			</div>
		</section>

		<?php if ($sections !== []): ?>
			<nav class="crm-quick-filters" aria-label="<?php p($l->t('Jump to queue')); ?>">
				<?php foreach ($sections as $section):
					$sid = (string)($section['id'] ?? '');
					$count = count($section['items'] ?? []);
					if ($sid === '' || $count === 0) {
						continue;
					}
					?>
					<a class="crm-quick-filter" href="#crm-cmd-<?php p($sid); ?>">
						<?php p($section['title'] ?? $sid); ?>
						<span class="crm-quick-filter__count" aria-hidden="true"><?php p((string)$count); ?></span>
						<span class="crm-sr-only"><?php p($l->n('%n item', '%n items', $count)); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ($sections === [] && $queueItems === [] && $companyCount === 0): ?>
		<section class="crm-card" aria-labelledby="crm-today-empty-title">
			<div class="crm-card__body">
				<div class="crm-empty-state" role="status">
					<h2 id="crm-today-empty-title" class="crm-empty-state__title"><?php p($l->t('Start with your first customer')); ?></h2>
					<p class="crm-empty-state__text"><?php p($l->t('Import a list or add a company. Today then shows renewals and follow-ups.')); ?></p>
					<div class="crm-empty-state__actions">
						<?php if ($canWrite): ?>
						<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urls['companyNew'] ?? '#'); ?>"><?php p($l->t('Add company')); ?></a>
						<a class="crm-btn crm-btn--secondary" href="<?php p($urls['import'] ?? '#'); ?>"><?php p($l->t('Import')); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</section>
	<?php elseif ($sections === [] && $queueItems === []): ?>
		<section class="crm-card" aria-labelledby="crm-today-clear-title">
			<div class="crm-card__body">
				<div class="crm-callout crm-callout--success" role="status">
					<h2 id="crm-today-clear-title" class="crm-sr-only"><?php p($l->t('All clear')); ?></h2>
					<p><?php p($l->t('You’re clear — nice.')); ?></p>
					<p><a class="crm-btn crm-btn--secondary crm-btn--sm" href="<?php p($urls['companies'] ?? '#'); ?>"><?php p($l->t('Browse companies')); ?></a></p>
				</div>
			</div>
		</section>
	<?php elseif ($sections !== []): ?>
		<?php foreach ($sections as $section):
			$sid = (string)($section['id'] ?? '');
			$items = $section['items'] ?? [];
			if ($sid === '' || $items === []) {
				continue;
			}
			?>
			<section class="crm-card crm-command-section" id="crm-cmd-<?php p($sid); ?>" aria-labelledby="crm-cmd-title-<?php p($sid); ?>">
				<header class="crm-card__header">
					<h2 id="crm-cmd-title-<?php p($sid); ?>" class="crm-card__title">
						<?php p($section['title'] ?? ''); ?>
						<span class="crm-badge crm-badge--neutral"><?php p((string)count($items)); ?></span>
					</h2>
				</header>
				<div class="crm-card__body">
					<?php $renderTodayTable($items, $canWrite); ?>
				</div>
			</section>
		<?php endforeach; ?>
	<?php else: ?>
		<section class="crm-card" aria-labelledby="crm-today-queue-title">
			<header class="crm-card__header">
				<h2 id="crm-today-queue-title" class="crm-card__title"><?php p($l->t('Due soon')); ?></h2>
			</header>
			<div class="crm-card__body">
				<?php $renderTodayTable($queueItems, $canWrite); ?>
			</div>
		</section>
	<?php endif; ?>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
