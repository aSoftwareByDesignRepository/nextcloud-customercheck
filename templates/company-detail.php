<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */

use OCA\CustomerCheck\Service\IconCatalog;
use OCA\CustomerCheck\Support\CompanyNotesPresenter;

$company = $_['company'];
$mail = $_['mail'] ?? ['mode' => 'manual_only', 'note' => '', 'related' => [], 'deepLinks' => [], 'touches' => []];
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$labels = $_['labels'] ?? null;
include __DIR__ . '/common/page-start.php';
?>
<?php
$crmPersonsForLog = [];
foreach (($_['persons'] ?? []) as $pRow) {
	$email = trim((string)($pRow['email'] ?? ''));
	if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		continue;
	}
	$crmPersonsForLog[] = [
		'id' => (int)$pRow['id'],
		'name' => (string)$pRow['name'],
		'email' => $email,
	];
}
$crmPersonsJson = json_encode($crmPersonsForLog, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$forecastBucketOptions = $_['forecastBucketOptions'] ?? [];
$dealStageOptions = $_['dealStageOptions'] ?? [];
$leadSourceOptions = $_['leadSourceOptions'] ?? [];
$companyDisplayName = (string)($_['companyDisplayName'] ?? $company->getName());
$ticketsHasData = ($_['tickets'] ?? []) !== [];
$invoicesHasData = ($_['invoices'] ?? []) !== [];
$projectsHasData = ($_['projects'] ?? []) !== [];
// No sibling lists yet → collapse into one calm Linked apps surface (no triple empty walls).
$linkedBundleCollapsed = !$ticketsHasData && !$invoicesHasData && !$projectsHasData;
?>
<script type="application/json" id="crm-deal-form-forecast-options"><?php
	echo json_encode($forecastBucketOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<script type="application/json" id="crm-deal-form-stage-options"><?php
	echo json_encode($dealStageOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<script type="application/json" id="crm-deal-form-lead-source-options"><?php
	echo json_encode($leadSourceOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<script type="application/json" id="crm-deal-form-company-meta"><?php
	echo json_encode([
		'companyId' => (int)$company->getId(),
		'companyName' => $companyDisplayName,
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?></script>
<div class="crm-page-actions-source header-actions" hidden>
	<?php if (!empty($_['canWrite'])): ?>
	<a class="crm-btn crm-btn--primary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyEdit', ['id' => $company->getId()])); ?>"><?php p($l->t('Edit')); ?></a>
	<?php endif; ?>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-gdpr-export" data-company-id="<?php p($company->getId()); ?>" data-slug="<?php p($company->getSlug()); ?>"><?php p($l->t('Export')); ?></button>
	<?php if (!empty($_['canWrite']) || !empty($_['canDelete'])): ?>
	<details class="crm-more-menu">
		<summary class="crm-btn crm-btn--secondary"><?php p($l->t('More')); ?></summary>
		<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('More actions')); ?>">
			<?php if (!empty($_['canWrite'])): ?>
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-merge" data-company-id="<?php p($company->getId()); ?>" data-updated-at="<?php p(\OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt()) ?? ''); ?>" <?php if ($company->isArchived()): ?>disabled<?php endif; ?>><?php p($l->t('Merge into…')); ?></button>
			<?php endif; ?>
			<?php if (!empty($_['canDelete'])): ?>
			<?php if ($company->isArchived()): ?>
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-restore" data-company-id="<?php p($company->getId()); ?>" data-updated-at="<?php p(\OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt()) ?? ''); ?>"><?php p($l->t('Restore')); ?></button>
			<?php else: ?>
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-archive" data-company-id="<?php p($company->getId()); ?>" data-updated-at="<?php p(\OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt()) ?? ''); ?>"><?php p($l->t('Archive')); ?></button>
			<?php endif; ?>
			<button type="button" class="crm-btn crm-btn--danger" id="crm-company-gdpr-erase" data-company-id="<?php p($company->getId()); ?>" data-slug="<?php p($company->getSlug()); ?>"><?php p($l->t('Erase (GDPR)')); ?></button>
			<?php endif; ?>
		</div>
	</details>
	<?php endif; ?>
</div>
<div class="crm-page-stack" data-company-id="<?php p($company->getId()); ?>" data-workspace-id="<?php p((int)($_['workspaceId'] ?? $company->getWorkspaceId() ?? 0)); ?>" data-workspace-name="<?php p((string)($_['workspaceName'] ?? '')); ?>" data-updated-at="<?php p((string)($_['companyUpdatedAt'] ?? '')); ?>" data-crm-persons="<?php p($crmPersonsJson); ?>">
	<nav class="crm-back" aria-label="<?php p($l->t('Back')); ?>">
		<a class="crm-btn crm-btn--ghost crm-btn--sm" href="<?php p($_['urls']['companies'] ?? $urlGenerator->linkToRoute('customercheck.page.companies')); ?>">← <?php p($l->t('Companies')); ?></a>
	</nav>

	<?php
	$labels = $_['labels'] ?? null;
	$addrStreet = trim((string)($company->getAddrStreet() ?? ''));
	$addrZip = trim((string)($company->getAddrZip() ?? ''));
	$addrCity = trim((string)($company->getAddrCity() ?? ''));
	$addrCountry = strtoupper(trim((string)($company->getCountry() ?? '')));
	$addrLines = [];
	if ($addrStreet !== '') {
		$addrLines[] = $addrStreet;
	}
	$zipCity = trim($addrZip . ($addrZip !== '' && $addrCity !== '' ? ' ' : '') . $addrCity);
	if ($zipCity !== '') {
		$addrLines[] = $zipCity;
	}
	if ($addrCountry !== '' && $addrCountry !== 'DE') {
		$addrLines[] = $addrCountry;
	}
	$companyEditHref = $urlGenerator->linkToRoute('customercheck.page.companyEdit', ['id' => $company->getId()]);
	?>
	<?php
	$canWritePage = !empty($_['canWrite']);
	$personsEmpty = ($_['persons'] ?? []) === [];
	$dealsEmpty = ($_['deals'] ?? []) === [];
	$showWelcomeCoach = $canWritePage && ($personsEmpty || $dealsEmpty);
	$hasPersonWithEmail = false;
	foreach (($_['persons'] ?? []) as $_coachPerson) {
		if (trim((string)($_coachPerson['email'] ?? '')) !== '') {
			$hasPersonWithEmail = true;
			break;
		}
	}
	?>


	<?php
	$density = $_['density'] ?? [
		'openProjects' => 0,
		'unpaidInvoices' => 0,
		'openTickets' => 0,
		'waitingTickets' => 0,
		'projectcheck' => false,
		'invoicecheck' => false,
		'ticketcheck' => false,
		'projectsHref' => '#crm-projects',
		'invoicesHref' => '#crm-invoices',
		'ticketsHref' => '#crm-tickets',
	];
	$density = \OCA\CustomerCheck\Support\GlanceDensityPresenter::normalizeInvoiceHref($density);
	$glanceTiles = \OCA\CustomerCheck\Support\GlanceDensityPresenter::tiles($density, $l);
	$identity = $_['identityLinks'] ?? ['pc' => ['state' => 'not_linked', 'label' => ''], 'tc' => ['state' => 'not_linked', 'label' => '']];
	$densityNeedsAttention = \OCA\CustomerCheck\Support\GlanceDensityPresenter::needsAttention($glanceTiles);
	?>
	<section class="crm-card" id="crm-glance" aria-labelledby="crm-glance-title">
		<div class="crm-glance-pin">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h2 id="crm-glance-title" class="crm-card__title"><?php p($l->t('At a glance')); ?></h2>
					<p class="crm-card__lead"><?php p($l->t('Who to call, what to do next, money, renewal.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body" id="crm-glance-summary">
				<p class="crm-muted"><?php p($l->t('Loading overview…')); ?></p>
			</div>
		</div>
		<?php if ($densityNeedsAttention): ?>
		<div class="crm-card__body crm-glance-density crm-glance-density--attention">
			<div class="crm-glance-density__intro">
				<h3 id="crm-glance-density-title" class="crm-glance-density__title"><?php p($l->t('Also in linked apps')); ?></h3>
				<p class="crm-glance-density__lead"><?php p($l->t('Open a number to jump to projects, tickets, or invoices.')); ?></p>
			</div>
			<?php include __DIR__ . '/common/crm-glance-density-list.php'; ?>
		</div>
		<?php else: ?>
		<details class="crm-card__body crm-glance-density crm-glance-density--calm">
			<summary class="crm-glance-density__summary">
				<span class="crm-glance-density__summary-main">
					<span id="crm-glance-density-title" class="crm-glance-density__title"><?php p($l->t('Also in linked apps')); ?></span>
					<span class="crm-glance-density__summary-hint"><?php p($l->t('All clear — expand if you need the numbers.')); ?></span>
				</span>
				<span class="crm-glance-density__chevron" aria-hidden="true"></span>
			</summary>
			<p class="crm-glance-density__lead"><?php p($l->t('Projects, tickets, and invoices from your linked apps.')); ?></p>
			<?php include __DIR__ . '/common/crm-glance-density-list.php'; ?>
		</details>
		<?php endif; ?>
	</section>

	<section class="crm-card" id="crm-identity" aria-labelledby="crm-identity-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<div class="crm-identity-title-row">
					<h2 id="crm-identity-title" class="crm-card__title"><?php p($company->getName()); ?></h2>
					<span class="crm-badge crm-badge--<?php p($company->getStatus() === 'active' ? 'success' : 'neutral'); ?>"><?php
						p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
							? $labels->companyStatus((string)$company->getStatus())
							: (string)$company->getStatus());
					?></span>
				</div>
				<h3 class="crm-sr-only" id="crm-identity-address-title"><?php p($l->t('Postal address')); ?></h3>
				<?php if ($addrLines !== []): ?>
				<p class="crm-card__lead crm-identity-strip__address" id="crm-company-address-display"><?php print_unescaped(IconCatalog::render('map', 'crm-identity-strip__address-svg')); ?><span><?php p(implode(' · ', $addrLines)); ?></span></p>
				<?php else: ?>
				<p class="crm-card__lead crm-identity-strip__address crm-identity-strip__address--empty" id="crm-company-address-display"><?php print_unescaped(IconCatalog::render('map', 'crm-identity-strip__address-svg')); ?><span><?php p($l->t('No address yet.')); ?></span></p>
				<?php endif; ?>
				<?php
				$identityNotes = CompanyNotesPresenter::publicHeadline($company->getNotes());
				if ($identityNotes !== ''):
				?>
				<p class="crm-identity-strip__notes"><?php p($identityNotes); ?></p>
				<?php endif; ?>
			</div>
			<div class="crm-card__header-actions crm-identity-actions">
				<?php if (!empty($_['canWrite'])): ?>
				<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-change-company" aria-expanded="false" aria-controls="crm-company-name-form"><?php p($l->t('Change company')); ?></button>
				<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-edit-address" aria-expanded="false" aria-controls="crm-address-form"><?php p($addrLines !== [] ? $l->t('Change address') : $l->t('Add postal address')); ?></button>
				<?php endif; ?>
			</div>
		</header>
		<?php if (!empty($_['canWrite'])): ?>
		<div class="crm-card__body crm-identity-body">
			<form id="crm-company-name-form" class="crm-identity-form" hidden novalidate aria-label="<?php p($l->t('Change company')); ?>">
				<div class="form-group">
					<label class="form-label" for="crm-identity-name"><?php p($l->t('Name')); ?></label>
					<input class="crm-input form-input crm-input--lg" id="crm-identity-name" name="name" required maxlength="200" value="<?php p($company->getName()); ?>" autocomplete="organization" />
				</div>
				<p class="form-help"><?php p($l->t('Change the name here. Owner, status, and links stay under More company settings.')); ?></p>
				<div id="crm-company-name-form-error" class="crm-callout crm-callout--danger" role="alert" tabindex="-1" hidden></div>
				<div class="crm-form-actions crm-form-actions--start">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Save')); ?></button>
					<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-name-cancel"><?php p($l->t('Cancel')); ?></button>
					<a class="crm-btn crm-btn--ghost" id="crm-company-more-settings" href="<?php p($companyEditHref); ?>"><?php p($l->t('More company settings')); ?></a>
				</div>
			</form>
			<form id="crm-address-form" class="crm-identity-form crm-address-form" hidden novalidate aria-label="<?php p($l->t('Postal address')); ?>">
				<?php
				$crmAddrPrefix = 'crm-address';
				$crmAddrCompany = $company;
				include __DIR__ . '/common/crm-company-address-fields.php';
				?>
				<div id="crm-address-form-error" class="crm-callout crm-callout--danger" role="alert" tabindex="-1" hidden></div>
				<div class="crm-form-actions crm-form-actions--start">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Save')); ?></button>
					<button type="button" class="crm-btn crm-btn--secondary" id="crm-address-cancel"><?php p($l->t('Cancel')); ?></button>
				</div>
			</form>
		</div>
		<?php endif; ?>
	</section>

	<div class="crm-360-focus" data-crm-360-focus="1" data-company-id="<?php p((int)$company->getId()); ?>">
	<?php
	$focusTabs = [
		['id' => 'today', 'label' => $l->t('Today')],
		['id' => 'people', 'label' => $l->t('People')],
		['id' => 'deals', 'label' => $l->t('Deals')],
		['id' => 'money', 'label' => $l->t('Bills & files')],
		['id' => 'more', 'label' => $l->t('Plan & links')],
	];
	include __DIR__ . '/common/crm-360-focus-tabs.php';
	?>
	<noscript>
		<style>
			#app-content.crm-app--company-detail .crm-360-focus__panel[hidden] { display: flex !important; }
			#app-content.crm-app--company-detail .crm-360-focus__chrome { display: none; }
		</style>
	</noscript>

	<div id="crm-360-panel-today" class="crm-360-focus__panel" role="tabpanel" data-crm-focus-panel="today" aria-labelledby="crm-360-tab-today" tabindex="0">
	<?php if ($showWelcomeCoach): ?>
	<aside class="crm-callout crm-callout--info crm-welcome-coach" id="crm-welcome-coach" role="region" aria-labelledby="crm-welcome-coach-title">
		<header class="crm-welcome-coach__header">
			<?php print_unescaped(IconCatalog::renderCalloutWell('info', 'info')); ?>
			<div class="crm-welcome-coach__intro">
				<h2 id="crm-welcome-coach-title" class="crm-welcome-coach__title"><?php p($l->t('Next steps for this customer')); ?></h2>
				<p class="crm-welcome-coach__lead"><?php p($l->t('Three short steps to make this customer useful.')); ?></p>
			</div>
		</header>
		<ol class="crm-welcome-coach__steps">
			<li class="crm-welcome-coach__step<?php if (!$personsEmpty): ?> crm-welcome-coach__step--done<?php endif; ?>">
				<span class="crm-welcome-coach__step-num" aria-hidden="true">1</span>
				<div class="crm-welcome-coach__step-body">
					<strong class="crm-welcome-coach__step-title"><?php p($l->t('Add person')); ?></strong>
					<p class="crm-welcome-coach__step-text"><?php p($l->t('Name is enough; email helps for logging messages.')); ?></p>
					<?php if ($personsEmpty): ?>
					<div class="crm-welcome-coach__step-actions">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-person"><?php p($l->t('Add person')); ?></button>
					</div>
					<?php else: ?>
					<p class="crm-welcome-coach__step-status"><?php p($l->t('Done')); ?></p>
					<?php endif; ?>
				</div>
			</li>
			<li class="crm-welcome-coach__step<?php if (!$dealsEmpty): ?> crm-welcome-coach__step--done<?php endif; ?>">
				<span class="crm-welcome-coach__step-num" aria-hidden="true">2</span>
				<div class="crm-welcome-coach__step-body">
					<strong class="crm-welcome-coach__step-title"><?php p($l->t('Add deal')); ?></strong>
					<p class="crm-welcome-coach__step-text"><?php p($l->t('Only a title is required for a quote or opportunity.')); ?></p>
					<?php if ($dealsEmpty): ?>
					<div class="crm-welcome-coach__step-actions">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-deal"><?php p($l->t('Add deal')); ?></button>
					</div>
					<?php else: ?>
					<p class="crm-welcome-coach__step-status"><?php p($l->t('Done')); ?></p>
					<?php endif; ?>
				</div>
			</li>
			<li class="crm-welcome-coach__step">
				<span class="crm-welcome-coach__step-num" aria-hidden="true">3</span>
				<div class="crm-welcome-coach__step-body">
					<?php if ($hasPersonWithEmail): ?>
					<strong class="crm-welcome-coach__step-title"><?php p($l->t('Log talk')); ?></strong>
					<p class="crm-welcome-coach__step-text"><?php p($l->t('Use Log talk or Call tomorrow under Talk when you speak.')); ?></p>
					<div class="crm-welcome-coach__step-actions">
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" data-crm-trigger="crm-log-email"><?php p($l->t('Log talk')); ?></button>
					</div>
					<?php else: ?>
					<strong class="crm-welcome-coach__step-title"><?php p($l->t('Call tomorrow')); ?></strong>
					<p class="crm-welcome-coach__step-text"><?php p($l->t('One tap schedules a call for tomorrow — no email needed yet.')); ?></p>
					<div class="crm-welcome-coach__step-actions">
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" data-crm-trigger="crm-schedule-callback"><?php p($l->t('Call tomorrow')); ?></button>
					</div>
					<?php endif; ?>
				</div>
			</li>
		</ol>
	</aside>
	<?php endif; ?>

	<section class="crm-card" id="crm-communication" aria-labelledby="crm-communication-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-communication-title" class="crm-card__title"><?php p($l->t('Talk')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Click a row to read the message and answer shared mail.')); ?></p>
			</div>
			<div class="crm-inline-actions">
				<?php if (!empty($_['canWrite'])): ?>
				<button type="button" class="crm-btn crm-btn--primary" id="crm-log-email" data-crm-log-email><?php p($l->t('Log talk')); ?></button>
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-schedule-callback" title="<?php p($l->t('Schedules a call for tomorrow')); ?>"><?php p($l->t('Call tomorrow')); ?></button>
				<?php endif; ?>
			</div>
		</header>
		<div class="crm-card__body">
			<div id="crm-shared-mail-disclosure" class="crm-callout" role="status" hidden></div>
			<div id="crm-communication-feed" class="crm-feed" aria-live="polite">
				<p class="crm-muted"><?php p($l->t('Loading conversation…')); ?></p>
			</div>
		</div>
	</section>

	<section class="crm-card" id="crm-next-steps" aria-labelledby="crm-next-steps-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-next-steps-title" class="crm-card__title"><?php p($l->t('To-do')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('What still needs doing.')); ?></p>
			</div>
			<div class="crm-inline-actions">
				<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-add-activity"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add to-do')); ?></button>
			</div>
		</header>
		<div class="crm-card__body crm-next-steps__body">
			<div id="crm-activities" aria-labelledby="crm-activities-title">
				<h3 id="crm-activities-title" class="visually-hidden"><?php p($l->t('Open follow-ups')); ?></h3>
				<?php if (($_['activities'] ?? []) === []): ?>
					<div class="crm-empty-state" role="status">
						<h3 class="crm-empty-state__title"><?php p($l->t('Nothing open')); ?></h3>
						<p class="crm-empty-state__text"><?php p($l->t('Tap Add to-do. Pick a due date.')); ?></p>
					</div>
				<?php else: ?>
				<ul class="crm-todo-list" role="list">
					<?php foreach ($_['activities'] as $act): ?>
						<?php
							$dueRaw = (string)($act['dueDate'] ?? '');
							if ($dueRaw === '') {
								$dueLabel = '—';
							} elseif ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
								$dueLabel = $labels->formatDate($dueRaw);
							} else {
								$dueLabel = $dueRaw;
							}
							$typeLabel = $labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
								? $labels->activityType((string)$act['type'])
								: (string)$act['type'];
							$isAuto = (($act['origin'] ?? '') === 'system' || str_starts_with((string)($act['origin'] ?? ''), 'sequence_'));
						?>
						<li class="crm-todo-list__item">
							<div class="crm-todo-list__main">
								<p class="crm-todo-list__title"><?php p($act['title']); ?></p>
								<p class="crm-muted crm-todo-list__meta">
									<span><?php p($l->t('Due')); ?>: <?php p($dueLabel); ?></span>
									<span class="crm-badge"><?php p($typeLabel); ?></span>
									<?php
										$who = trim((string)($act['assigneeDisplayName'] ?? ''));
										if ($who === '') {
											$who = $l->t('Anyone');
										}
									?>
									<span><?php p($l->t('Who')); ?>: <?php p($who); ?></span>
									<?php if ($isAuto): ?>
										<span class="crm-badge crm-badge--info"><?php p($l->t('Auto')); ?></span>
									<?php endif; ?>
									<?php if (!empty($act['todoUri'])): ?>
										<span class="crm-badge crm-badge--success"><?php p($l->t('Sent to Tasks')); ?></span>
									<?php endif; ?>
								</p>
							</div>
							<?php if (!empty($_['canWrite'])): ?>
							<div class="crm-todo-list__actions">
								<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-complete-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Done')); ?></button>
							</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>

			<div class="crm-next-steps__extra" id="crm-promises-section">
				<div class="crm-next-steps__extra-head">
					<details class="crm-next-steps__extra-details" id="crm-promises">
						<summary>
							<span id="crm-promises-title"><?php p($l->t('Told the customer')); ?></span>
							<span class="crm-muted crm-next-steps__hint"> — <?php p($l->t('What you told the customer.')); ?></span>
						</summary>
						<div class="crm-next-steps__extra-body">
							<div id="crm-promises-body">
								<p class="crm-muted"><?php p($l->t('Loading promises…')); ?></p>
							</div>
						</div>
					</details>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-inline-actions crm-next-steps__extra-actions">
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-promise"><?php p($l->t('Told the customer')); ?></button>
					</div>
					<?php else: ?>
					<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-promise" hidden><?php p($l->t('Told the customer')); ?></button>
					<?php endif; ?>
				</div>
			</div>

			<div class="crm-next-steps__extra crm-next-steps__tasks" id="crm-open-tasks-section">
				<div class="crm-next-steps__extra-head">
					<details class="crm-next-steps__extra-details" id="crm-open-tasks">
						<summary>
							<span id="crm-open-tasks-title"><?php p($l->t('Also on your phone')); ?></span>
							<span class="crm-muted"> — <?php p($l->t('Done in CustomerCheck is what counts. Tasks app gets a copy you can open on your phone.')); ?></span>
						</summary>
						<div class="crm-next-steps__extra-body">
							<div id="crm-open-tasks-body">
								<p class="crm-muted"><?php p($l->t('Loading tasks…')); ?></p>
							</div>
						</div>
					</details>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-inline-actions crm-next-steps__extra-actions">
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-new-task"><?php p($l->t('New task')); ?></button>
					</div>
					<?php else: ?>
					<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-new-task" hidden><?php p($l->t('New task')); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</section>

	</div>

	<div id="crm-360-panel-people" class="crm-360-focus__panel" role="tabpanel" data-crm-focus-panel="people" aria-labelledby="crm-360-tab-people" aria-hidden="true" hidden>
	<?php /* People tab owns the roster; Add person opens a native dialog (teleported to body so inert/hidden tabs cannot trap it). */ ?>
	<section class="crm-card" id="crm-persons" aria-labelledby="crm-persons-title" data-contacts-available="<?php p(!empty($_['contactsAvailable']) ? '1' : '0'); ?>" data-contacts-home="<?php p((string)($_['contactsHomeUrl'] ?? '/apps/contacts/')); ?>">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-persons-title" class="crm-card__title"><?php p($l->t('People')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Contacts at this company.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-add-person" aria-haspopup="dialog" aria-expanded="false" aria-controls="crm-person-dialog"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add person')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (!empty($_['canWrite'])): ?>
			<dialog id="crm-person-dialog" class="crm-native-dialog modal modal--md crm-dialog crm-dialog--content crm-person-dialog" role="dialog" aria-modal="true" aria-labelledby="crm-person-form-title" aria-hidden="true" data-azc-modal-persist="true" data-azc-focus-trap-standalone="1">
				<div class="modal-header">
					<h2 class="modal-title" id="crm-person-form-title"><?php p($l->t('Add person')); ?></h2>
					<button type="button" class="modal-close crm-btn crm-btn--ghost" id="crm-person-dialog-close" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
				</div>
				<div class="modal-body">
					<form id="crm-person-form" class="crm-form crm-form--stack" data-mode="add" novalidate aria-labelledby="crm-person-form-title">
						<div class="form-group">
							<label class="form-label" for="crm-person-name"><?php p($l->t('Name')); ?></label>
							<input class="crm-input form-input crm-input--lg" id="crm-person-name" name="name" required maxlength="255" autocomplete="name" aria-describedby="crm-person-name-help" />
							<p class="form-help" id="crm-person-name-help"><?php p($l->t('Who do you talk to at this company?')); ?></p>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-person-email"><?php p($l->t('Email')); ?></label>
							<input class="crm-input form-input crm-input--lg" id="crm-person-email" name="email" type="email" maxlength="255" autocomplete="email" aria-describedby="crm-person-email-help" />
							<p class="form-help" id="crm-person-email-help"><?php p($l->t('Optional — needed to log emails with them.')); ?></p>
						</div>
						<details class="crm-advanced" id="crm-person-advanced">
							<summary class="crm-advanced__summary"><?php p($l->t('More options')); ?></summary>
							<div class="crm-advanced__body">
								<div class="form-group">
									<label class="form-label" for="crm-person-phone"><?php p($l->t('Phone')); ?></label>
									<input class="crm-input form-input crm-input--lg" id="crm-person-phone" name="phone" maxlength="64" autocomplete="tel" inputmode="tel" pattern="[0-9+()\/.\s-]{4,64}" />
								</div>
								<div class="form-group">
									<label class="form-label" for="crm-person-role"><?php p($l->t('Job title')); ?></label>
									<input class="crm-input form-input crm-input--lg" id="crm-person-role" name="role" maxlength="128" autocomplete="organization-title" />
								</div>
							</div>
						</details>
						<div id="crm-person-form-error" class="crm-callout crm-callout--danger" role="alert" tabindex="-1" hidden></div>
						<div class="crm-form-actions">
							<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg"><?php p($l->t('Save')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" id="crm-person-cancel"><?php p($l->t('Cancel')); ?></button>
						</div>
					</form>
				</div>
			</dialog>
			<?php endif; ?>
			<?php if ($personsEmpty): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No contacts yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add someone you talk to. A name is enough — an email lets you log messages with them.')); ?></p>
					<?php if (!empty($_['canWrite']) && !$showWelcomeCoach): ?>
					<div class="crm-empty-state__actions">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-person"><?php p($l->t('Add person')); ?></button>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-persons-table-wrap" role="region" tabindex="0" aria-labelledby="crm-persons-title">
				<table class="table table--hover" id="crm-persons-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Name')); ?></th>
						<th scope="col"><?php p($l->t('Email')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
					</tr></thead>
					<tbody>
					<?php
					$buyingRoleOptions = $_['buyingRoleOptions'] ?? [];
					foreach ($_['persons'] as $p):
						$linked = trim((string)($p['contactsUid'] ?? '')) !== '';
						$ltLabel = (string)($p['lastTouchLabel'] ?? $l->t('No touch yet'));
						$buyingRole = (string)($p['buyingRole'] ?? '');
						$hasTouch = !empty($p['lastTouch']);
						$roleLabel = $buyingRole;
						if ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService && $buyingRole !== '') {
							$roleLabel = $labels->buyingRole($buyingRole);
						}
						$pEmail = trim((string)($p['email'] ?? ''));
						$pPhone = trim((string)($p['phone'] ?? ''));
						$pJob = trim((string)($p['role'] ?? ''));
					?>
						<tr id="crm-person-<?php p((int)$p['id']); ?>" data-person-id="<?php p((int)$p['id']); ?>" data-person-name="<?php p($p['name']); ?>" data-person-email="<?php p((string)($p['email'] ?? '')); ?>" data-person-phone="<?php p((string)($p['phone'] ?? '')); ?>" data-person-role="<?php p((string)($p['role'] ?? '')); ?>" data-contacts-uid="<?php p((string)($p['contactsUid'] ?? '')); ?>" data-updated-at="<?php p((string)($p['updatedAt'] ?? '')); ?>" data-buying-role="<?php p($buyingRole); ?>">
							<td data-label="<?php p($l->t('Name')); ?>">
								<?php p($p['name']); ?><?php if (!empty($p['isPrimary'])): ?> <span class="crm-badge crm-badge--info"><?php p($l->t('Primary')); ?></span><?php endif; ?>
								<?php if ($pPhone !== '' || $pJob !== ''): ?>
									<p class="crm-muted crm-person-meta">
										<?php if ($pJob !== ''): ?><?php p($pJob); ?><?php endif; ?>
										<?php if ($pPhone !== ''): ?><?php if ($pJob !== ''): ?> · <?php endif; ?><a href="tel:<?php p($pPhone); ?>"><?php p($pPhone); ?></a><?php endif; ?>
									</p>
								<?php endif; ?>
								<details class="crm-person-more">
									<summary class="crm-person-more__summary"><?php p($l->t('More about this person')); ?></summary>
									<div class="crm-person-more__body">
										<p class="crm-person-more__row">
											<span class="crm-muted"><?php p($l->t('Last touch')); ?>:</span>
											<?php if ($hasTouch): ?>
												<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-person-last-touch="<?php p((int)$p['id']); ?>"><?php p($ltLabel); ?></button>
											<?php else: ?>
												<span class="crm-muted"><?php p($ltLabel); ?></span>
											<?php endif; ?>
										</p>
										<p class="crm-person-more__row">
											<span class="crm-muted"><?php p($l->t('Address book')); ?>:</span>
											<?php if ($linked && !empty($_['contactsAvailable'])): ?>
												<span class="crm-badge crm-badge--success"><?php p($l->t('Linked')); ?></span>
											<?php elseif ($linked): ?>
												<span class="crm-badge crm-badge--neutral" title="<?php p($l->t('Contacts app is not available — link kept.')); ?>"><?php p($l->t('Linked')); ?></span>
											<?php else: ?>
												<span class="crm-muted"><?php p($l->t('Not linked')); ?></span>
											<?php endif; ?>
										</p>
										<div class="crm-person-more__row">
											<?php if (!empty($_['canWrite'])): ?>
												<label class="form-label" for="crm-buying-role-<?php p((int)$p['id']); ?>"><?php p($l->t('Role in buying')); ?></label>
												<select id="crm-buying-role-<?php p((int)$p['id']); ?>" class="crm-input crm-input--sm crm-persons-role" data-crm-buying-role="<?php p((int)$p['id']); ?>" data-updated-at="<?php p((string)($p['updatedAt'] ?? '')); ?>">
													<?php foreach ($buyingRoleOptions as $opt): ?>
														<option value="<?php p($opt['value']); ?>" <?php if ((string)$opt['value'] === $buyingRole): ?>selected<?php endif; ?>><?php p($opt['label']); ?></option>
													<?php endforeach; ?>
												</select>
											<?php else: ?>
												<span class="crm-muted"><?php p($l->t('Role in buying')); ?>: <?php p($roleLabel !== '' ? $roleLabel : '—'); ?></span>
											<?php endif; ?>
										</div>
									</div>
								</details>
							</td>
							<td data-label="<?php p($l->t('Email')); ?>"><?php if ($pEmail !== ''): ?><a href="mailto:<?php p($pEmail); ?>"><?php p($pEmail); ?></a><?php else: ?>—<?php endif; ?></td>
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if (!empty($_['canWrite'])): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-edit-person="<?php p((int)$p['id']); ?>"><?php p($l->t('Change person')); ?></button>
									<?php endif; ?>
									<?php if ($linked && !empty($_['contactsAvailable'])): ?>
										<a class="crm-btn crm-btn--sm crm-btn--primary" href="<?php p((string)$_['contactsHomeUrl']); ?>" data-crm-open-contacts="<?php p((int)$p['id']); ?>" data-person-name="<?php p($p['name']); ?>"><?php p($l->t('Open in Contacts')); ?></a>
										<?php if (!empty($_['canWrite'])): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-unlink-contacts="<?php p((int)$p['id']); ?>"><?php p($l->t('Unlink')); ?></button>
										<?php endif; ?>
									<?php elseif ($hasTouch): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-person-last-touch="<?php p((int)$p['id']); ?>"><?php p($l->t('See talk')); ?></button>
									<?php elseif (!empty($_['canWrite'])): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-trigger="crm-log-email"><?php p($l->t('Log talk')); ?></button>
									<?php else: ?>
										<a class="crm-btn crm-btn--sm crm-btn--secondary" href="#crm-communication"><?php p($l->t('See talk')); ?></a>
									<?php endif; ?>
									<?php if (!empty($_['canWrite'])): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-remove-person="<?php p((int)$p['id']); ?>" title="<?php p($l->t('Remove from this company')); ?>"><?php p($l->t('Remove')); ?></button>
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

	<div id="crm-360-panel-deals" class="crm-360-focus__panel" role="tabpanel" data-crm-focus-panel="deals" aria-labelledby="crm-360-tab-deals" aria-hidden="true" hidden>
<section class="crm-card" id="crm-deals" aria-labelledby="crm-deals-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-deals-title" class="crm-card__title"><?php p($l->t('Deals')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Quotes and opportunities for this customer.')); ?></p>
			</div>
			<?php /* Deals panel is a separate focus tab — never hide Add deal for the coach (coach lives on Today). */ ?>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-deal"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add deal')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if ($dealsEmpty): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No deals yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('A deal is a quote or opportunity. Tap Add deal and enter a title — value is optional.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-deal"><?php p($l->t('Add deal')); ?></button>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive" role="region" tabindex="0" aria-labelledby="crm-deals-title">
				<table class="table table--hover" id="crm-deals-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Stage')); ?></th>
						<th scope="col"><?php p($l->t('Value')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['deals'] as $d):
						$dealNotes = trim((string)($d['notes'] ?? ''));
						$dealNotesPreview = $dealNotes === '' ? '' : (mb_strlen($dealNotes) > 120 ? mb_substr($dealNotes, 0, 117) . '…' : $dealNotes);
						$valueCents = (int)($d['valueCents'] ?? 0);
						?>
						<tr data-deal-id="<?php p($d['id']); ?>"
							data-title="<?php p($d['title']); ?>"
							data-stage="<?php p($d['stage']); ?>"
							data-notes="<?php p($dealNotes); ?>"
							data-value-cents="<?php p($valueCents); ?>"
							data-products="<?php p((string)($d['products'] ?? '')); ?>"
							data-expected-close="<?php p((string)($d['expectedClose'] ?? '')); ?>"
							data-updated-at="<?php p((string)($d['updatedAt'] ?? '')); ?>">
							<td data-label="<?php p($l->t('Title')); ?>">
								<?php p($d['title']); ?>
								<?php include __DIR__ . '/common/crm-deal-freshness.php'; ?>
								<?php if ($dealNotesPreview !== ''): ?>
									<p class="crm-muted crm-deal-notes-preview"><?php p($dealNotesPreview); ?></p>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Stage')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->dealStage((string)$d['stage'])
									: (string)$d['stage']);
							?></span></td>
							<td data-label="<?php p($l->t('Value')); ?>"><?php
								p($valueCents > 0 ? number_format($valueCents / 100, 2) . ' €' : '—');
							?></td>
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if (!empty($_['canWrite'])): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-edit-chase="<?php p($d['id']); ?>"><?php p($l->t('Edit deal')); ?></button>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-deal-checklist="<?php p($d['id']); ?>" aria-label="<?php p($l->t('Checklist for %s', [(string)$d['title']])); ?>"><?php p($l->t('Checklist')); ?></button>
									<details class="crm-more-menu">
										<summary class="crm-btn crm-btn--ghost crm-btn--sm"><?php p($l->t('More')); ?></summary>
										<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('More deal actions')); ?>">
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-dismiss-followups="<?php p($d['id']); ?>"><?php p($l->t('Stop reminders')); ?></button>
										</div>
									</details>
									<?php else: ?>
									<span class="crm-muted" aria-hidden="true">—</span>
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

<section class="crm-card" id="crm-contracts" aria-labelledby="crm-contracts-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-contracts-title" class="crm-card__title"><?php p($l->t('Licenses & renewals')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Licenses and Check Partner packages.')); ?></p>
			</div>
			<?php if (!empty($_['canWrite'])): ?>
			<a class="crm-btn crm-btn--secondary crm-btn--sm" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>?companyId=<?php p((int)$company->getId()); ?>"><?php p($l->t('New license')); ?></a>
			<?php endif; ?>
		</header>
		<div class="crm-card__body">
			<?php if (($_['agreements'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No licenses yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add a license or partner package when you have one — or import later.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>?companyId=<?php p((int)$company->getId()); ?>"><?php p($l->t('New license')); ?></a>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive" role="region" tabindex="0" aria-labelledby="crm-contracts-title">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Name')); ?></th>
						<th scope="col"><?php p($l->t('Until')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['agreements'] as $a):
						$agStatus = (string)($a['status'] ?? '');
						$canLifecycle = in_array($agStatus, ['draft', 'active', 'expiring', 'expired'], true);
						?>
						<tr data-agreement-id="<?php p($a['id']); ?>" data-kind="<?php p($a['kind']); ?>" data-updated-at="<?php p($a['updatedAt'] ?? ''); ?>" data-status="<?php p($agStatus); ?>" data-valid-until="<?php p($a['validUntil'] ?? ''); ?>" data-amount-cents="<?php p((int)($a['amountCents'] ?? 0)); ?>" data-label="<?php p($a['label']); ?>" data-product-code="<?php p($a['productCode'] ?? ''); ?>">
							<td data-label="<?php p($l->t('Name')); ?>">
								<?php if (!empty($_['canWrite'])): ?><a href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => $a['id']])); ?>"><?php p($a['label']); ?></a><?php else: ?><?php p($a['label']); ?><?php endif; ?>
								<?php if (($a['kind'] ?? '') === 'partner' && !empty($a['quota'])): ?>
									<p class="crm-muted"><?php p(number_format(($a['quota']['usedMinutes'] ?? 0) / 60, 1)); ?> / <?php p(number_format(($a['quota']['quotaMinutes'] ?? 0) / 60, 1)); ?> h</p>
								<?php elseif ((int)($a['amountCents'] ?? 0) > 0): ?>
									<p class="crm-muted"><?php p(number_format(($a['amountCents'] ?? 0) / 100, 2)); ?> €</p>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Until')); ?>"><span class="crm-badge crm-badge--<?php p($a['chip']['variant'] ?? 'neutral'); ?>"><?php p($a['chip']['label'] ?? ($a['validUntil'] ?? '—')); ?></span></td>
							<td data-label="<?php p($l->t('Status')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->agreementStatus((string)$a['status'])
									: (string)$a['status']);
							?></span></td>
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if (!empty($_['canWrite'])): ?>
										<?php
										$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => (int)$a['id']]);
										$crmRowOpenLabel = $l->t('Edit');
										include __DIR__ . '/common/crm-row-open.php';
										?>
										<?php if (($a['kind'] ?? '') === 'partner'): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-partner-session="<?php p($a['id']); ?>" title="<?php p($l->t('Open partner mail, then log hours')); ?>"><?php p($l->t('Email & log hours')); ?></button>
										<?php elseif ($canLifecycle): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-renew-agreement="<?php p($a['id']); ?>"><?php p($l->t('Renew')); ?></button>
										<?php endif; ?>
										<details class="crm-more-menu">
											<summary class="crm-btn crm-btn--ghost crm-btn--sm"><?php p($l->t('More')); ?></summary>
											<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('More agreement actions')); ?>">
											<?php if ($canLifecycle && ($a['kind'] ?? '') === 'partner'): ?>
												<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-renew-agreement="<?php p($a['id']); ?>"><?php p($l->t('Renew')); ?></button>
											<?php endif; ?>
											<?php if ($canLifecycle): ?>
												<button type="button" class="crm-btn crm-btn--sm crm-btn--danger" data-crm-cancel-agreement="<?php p($a['id']); ?>"><?php p($l->t('Cancel agreement')); ?></button>
											<?php endif; ?>
											<?php if (($a['kind'] ?? '') === 'license' && in_array($agStatus, ['expiring', 'expired', 'active'], true) && !empty($a['validUntil'])): ?>
												<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-renewal-compose="renewal_60" data-agreement-id="<?php p($a['id']); ?>"><?php p($l->t('Send renewal quote')); ?></button>
											<?php endif; ?>
											<?php if (($a['kind'] ?? '') === 'license' && (!empty($a['salesLogId']) || !empty($a['keyFingerprint']))): ?>
												<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-delivery-pack="<?php p($a['id']); ?>"><?php p($l->t('Delivery pack')); ?></button>
											<?php endif; ?>
											<?php if (($a['kind'] ?? '') === 'partner'): ?>
												<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-log-hours="<?php p($a['id']); ?>"><?php p($l->t('Hours only')); ?></button>
											<?php endif; ?>
											<?php if (!empty($a['productCode'])): ?>
												<p class="crm-muted crm-more-menu__meta"><code><?php p($a['productCode']); ?></code></p>
											<?php endif; ?>
											</div>
										</details>
									<?php else: ?>
										<span class="crm-muted" aria-hidden="true">—</span>
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

	<div id="crm-360-panel-money" class="crm-360-focus__panel" role="tabpanel" data-crm-focus-panel="money" aria-labelledby="crm-360-tab-money" aria-hidden="true" hidden>
<section class="crm-card" id="crm-billing" aria-labelledby="crm-billing-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-billing-title" class="crm-card__title"><?php p($l->t('Money & invoicing')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('What still needs paying — licenses here, InvoiceCheck when linked.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body" id="crm-billing-body">
			<p class="crm-muted"><?php p($l->t('Loading money status…')); ?></p>
		</div>
	</section>

<section class="crm-card" id="crm-documents" aria-labelledby="crm-documents-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-documents-title" class="crm-card__title"><?php p($l->t('Documents')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Customer files live in Nextcloud Files — browse and choose one folder.')); ?></p>
			</div>
			<div class="crm-inline-actions">
				<?php if (!empty($_['canWrite'])): ?>
				<button type="button" class="crm-btn crm-btn--primary crm-btn--sm" id="crm-link-folder"><?php p($l->t('Browse folders')); ?></button>
				<details class="crm-more-menu">
					<summary class="crm-btn crm-btn--ghost crm-btn--sm"><?php p($l->t('More')); ?></summary>
					<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('More document actions')); ?>">
						<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" id="crm-create-folder"><?php p($l->t('Create folder')); ?></button>
						<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" id="crm-unlink-folder" hidden><?php p($l->t('Unlink folder')); ?></button>
					</div>
				</details>
				<?php endif; ?>
			</div>
		</header>
		<div class="crm-card__body" id="crm-documents-body">
			<div class="crm-empty-state" role="status">
				<h3 class="crm-empty-state__title"><?php p($l->t('No folder linked')); ?></h3>
				<p class="crm-empty-state__text"><?php p($l->t('No document folder linked yet. Browse your Nextcloud folders and choose one (contracts, offers, notes) so everyone finds customer files in one click.')); ?></p>
				<?php if (!empty($_['canWrite'])): ?>
				<div class="crm-empty-state__actions">
					<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-link-folder"><?php p($l->t('Browse folders')); ?></button>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php
	$pcCap = !empty($_['capabilities']['projectcheck']);
	$tcCap = !empty($_['capabilities']['ticketcheck']);
	$icCap = !empty($_['capabilities']['invoicecheck']);
	$pcStateBundle = (string)($identity['pc']['state'] ?? 'not_linked');
	$tcStateBundle = (string)($identity['tc']['state'] ?? 'not_linked');
	$pcLinkedBundle = $pcCap && !empty($_['pcCustomerId']) && $pcStateBundle !== 'broken';
	$tcLinkedBundle = $tcCap && !empty($_['tcCustomerId']) && $tcStateBundle !== 'broken';
	$pcBrokenBundle = $pcCap && $pcStateBundle === 'broken';
	$tcBrokenBundle = $tcCap && $tcStateBundle === 'broken';
	$icLinkedBundle = $icCap && $pcLinkedBundle;
	$linkedNeedsSetup = !$pcLinkedBundle && !$tcLinkedBundle && !$pcBrokenBundle && !$tcBrokenBundle;
	$pcState = $pcStateBundle;
	$tcState = $tcStateBundle;
	$pcWrite = !empty($_['pcWriteAvailable']);
	$tcWrite = !empty($_['tcWriteAvailable']);
	$mnAvail = !empty($_['mnIdentityAvailable']);
	$canWriteIdentity = !empty($_['canWrite']);
	$showPcCta = $canWriteIdentity && $pcWrite && in_array($pcState, ['not_linked', 'broken'], true);
	$showTcCta = $canWriteIdentity && $tcWrite && in_array($tcState, ['not_linked', 'broken'], true);
	$identityForceOpen = ($pcState === 'broken' || $tcState === 'broken');
	$identityMergedIntoBundle = $linkedBundleCollapsed && $linkedNeedsSetup;
	?>
	<?php if ($linkedBundleCollapsed): ?>
	<details class="crm-card crm-linked-bundle<?php if ($identityMergedIntoBundle): ?> crm-linked-bundle--setup<?php endif; ?>" id="crm-linked-bundle"<?php if ($identityMergedIntoBundle && $identityForceOpen): ?> open<?php endif; ?>>
		<summary class="crm-card__header crm-linked-bundle__summary">
			<div class="crm-card__header-text">
				<span class="crm-card__title crm-linked-bundle__title"><?php p($identityMergedIntoBundle ? $l->t('Link apps') : $l->t('Linked apps')); ?></span>
				<span class="crm-muted crm-linked-bundle__hint"><?php p($identityMergedIntoBundle
					? $l->t('One ProjectCheck or TicketCheck link unlocks projects, tickets, and invoices.')
					: $l->t('Projects, tickets, and invoices from your linked apps.')); ?></span>
			</div>
			<?php include __DIR__ . '/common/crm-collapsible-disclosure.php'; ?>
		</summary>
		<div class="crm-card__body crm-linked-bundle__body">
			<?php if ($identityMergedIntoBundle): ?>
			<div id="crm-identity-links"
				data-company-id="<?php p((int)$company->getId()); ?>"
				data-updated-at="<?php p((string)($_['companyUpdatedAt'] ?? '')); ?>"
				data-pc-state="<?php p($pcState); ?>"
				data-tc-state="<?php p($tcState); ?>">
				<?php
				$crmIdentityShowMoreWrap = true;
				include __DIR__ . '/common/crm-identity-rows.php';
				?>
			</div>
			<?php endif; ?>
			<ul class="crm-linked-status" aria-label="<?php p($l->t('Projects, tickets and invoices')); ?>">
				<li class="crm-linked-status__row" id="crm-projects" aria-labelledby="crm-projects-title">
					<div class="crm-linked-status__main">
						<h3 id="crm-projects-title" class="crm-linked-status__title"><?php p($l->t('Projects')); ?></h3>
						<p class="crm-muted crm-linked-status__hint"><?php p($l->t('From ProjectCheck')); ?></p>
					</div>
					<?php if (!$pcCap): ?>
						<span class="crm-badge crm-badge--neutral"><?php p($l->t('Not installed')); ?></span>
					<?php elseif ($pcBrokenBundle): ?>
						<span class="crm-badge crm-badge--danger"><?php p($l->t('Needs fix')); ?></span>
					<?php elseif ($pcLinkedBundle): ?>
						<span class="crm-badge crm-badge--success"><?php p($l->t('Linked · none open')); ?></span>
					<?php else: ?>
						<span class="crm-badge"><?php p($l->t('Not linked')); ?></span>
					<?php endif; ?>
				</li>
				<li class="crm-linked-status__row" id="crm-tickets" aria-labelledby="crm-tickets-title">
					<div class="crm-linked-status__main">
						<h3 id="crm-tickets-title" class="crm-linked-status__title"><?php p($l->t('Tickets')); ?></h3>
						<p class="crm-muted crm-linked-status__hint"><?php p($l->t('From TicketCheck')); ?></p>
					</div>
					<?php if (!$tcCap): ?>
						<span class="crm-badge crm-badge--neutral"><?php p($l->t('Not installed')); ?></span>
					<?php elseif ($tcBrokenBundle): ?>
						<span class="crm-badge crm-badge--danger"><?php p($l->t('Needs fix')); ?></span>
					<?php elseif ($tcLinkedBundle): ?>
						<span class="crm-badge crm-badge--success"><?php p($l->t('Linked · none open')); ?></span>
					<?php else: ?>
						<span class="crm-badge"><?php p($l->t('Not linked')); ?></span>
					<?php endif; ?>
				</li>
				<li class="crm-linked-status__row" id="crm-invoices" aria-labelledby="crm-invoices-title">
					<div class="crm-linked-status__main">
						<h3 id="crm-invoices-title" class="crm-linked-status__title"><?php p($l->t('Invoices')); ?></h3>
						<p class="crm-muted crm-linked-status__hint"><?php p($l->t('From InvoiceCheck (via ProjectCheck link)')); ?></p>
					</div>
					<?php if (!$icCap): ?>
						<span class="crm-badge crm-badge--neutral"><?php p($l->t('Not installed')); ?></span>
					<?php elseif ($pcBrokenBundle): ?>
						<span class="crm-badge crm-badge--danger"><?php p($l->t('Needs fix')); ?></span>
					<?php elseif ($icLinkedBundle): ?>
						<span class="crm-badge crm-badge--success"><?php p($l->t('Linked · none open')); ?></span>
					<?php else: ?>
						<span class="crm-badge"><?php p($l->t('Not linked')); ?></span>
					<?php endif; ?>
				</li>
			</ul>
			<?php if (!$identityMergedIntoBundle && !empty($_['canWrite']) && (!$pcLinkedBundle || !$tcLinkedBundle)): ?>
			<div class="crm-inline-actions crm-linked-bundle__actions">
				<a class="crm-btn crm-btn--secondary crm-btn--sm" href="#crm-identity-links"><?php p($l->t('Manage links')); ?></a>
			</div>
			<?php endif; ?>
		</div>
	</details>
	<?php else: ?>

<section class="crm-card" id="crm-projects" aria-labelledby="crm-projects-title" aria-busy="false">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-projects-title" class="crm-card__title"><?php p($l->t('Projects')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Read-only from ProjectCheck when linked.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (empty($_['capabilities']['projectcheck'])): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('ProjectCheck missing')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('ProjectCheck is not available on this instance.')); ?></p>
				</div>
			<?php elseif (empty($_['pcCustomerId']) || (($identity['pc']['state'] ?? '') === 'broken')): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Not linked yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Link a ProjectCheck customer above to load projects.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="#crm-identity-links"><?php p($l->t('Link apps')); ?></a>
					</div>
					<?php endif; ?>
				</div>
			<?php elseif (!empty($_['projectError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<?php print_unescaped(IconCatalog::renderCalloutWell('circle-alert', 'danger')); ?>
					<p class="crm-callout__text"><?php p($l->t('Could not load projects. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['projects'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No projects')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('No projects found for the linked customer.')); ?></p>
				</div>
			<?php else: ?>
				<ul class="crm-simple-list">
				<?php foreach ($_['projects'] as $proj): ?>
					<li>
						<?php if (!empty($proj['deepLink'])): ?>
							<a href="<?php p($proj['deepLink']); ?>"><strong><?php p($proj['name'] ?? $l->t('Unlabelled project')); ?></strong></a>
						<?php else: ?>
							<strong><?php p($proj['name'] ?? $l->t('Unlabelled project')); ?></strong>
						<?php endif; ?>
						· <span class="crm-badge"><?php p($proj['status'] ?? 'active'); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>

<section class="crm-card" id="crm-tickets" aria-labelledby="crm-tickets-title" aria-busy="false">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-tickets-title" class="crm-card__title"><?php p($l->t('Tickets')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Open support tickets from TicketCheck when linked.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (empty($_['capabilities']['ticketcheck'])): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('TicketCheck missing')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('TicketCheck is not available on this instance.')); ?></p>
				</div>
			<?php elseif (empty($_['tcCustomerId']) || (($identity['tc']['state'] ?? '') === 'broken')): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Not linked yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Link a TicketCheck customer above to load tickets.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="#crm-identity-links"><?php p($l->t('Link apps')); ?></a>
					</div>
					<?php endif; ?>
				</div>
			<?php elseif (!empty($_['ticketError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<?php print_unescaped(IconCatalog::renderCalloutWell('circle-alert', 'danger')); ?>
					<p class="crm-callout__text"><?php p($l->t('Could not load tickets. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['tickets'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No tickets')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('No open tickets for this company.')); ?></p>
				</div>
			<?php else: ?>
				<ul class="crm-simple-list">
				<?php foreach ($_['tickets'] as $t): ?>
					<li>
						<?php if (!empty($t['deepLink'])): ?>
							<a href="<?php p($t['deepLink']); ?>"><?php p($t['subject'] ?? $l->t('Unlabelled ticket')); ?></a>
						<?php else: ?>
							<?php p($t['subject'] ?? $l->t('Unlabelled ticket')); ?>
						<?php endif; ?>
						<?php if (!empty($t['status'])): ?>
							· <span class="crm-badge"><?php p($t['status']); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>

<section class="crm-card" id="crm-invoices" aria-labelledby="crm-invoices-title" aria-busy="false">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-invoices-title" class="crm-card__title"><?php p($l->t('Invoices')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Read-only from InvoiceCheck when linked.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (empty($_['capabilities']['invoicecheck'])): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('InvoiceCheck missing')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('InvoiceCheck is not available on this instance.')); ?></p>
				</div>
			<?php elseif (empty($_['pcCustomerId'])): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Not linked yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Link a ProjectCheck customer on the company form to load invoices.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="#crm-identity-links"><?php p($l->t('Link apps')); ?></a>
					</div>
					<?php endif; ?>
				</div>
			<?php elseif (!empty($_['invoiceError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<?php print_unescaped(IconCatalog::renderCalloutWell('circle-alert', 'danger')); ?>
					<p class="crm-callout__text"><?php p($l->t('Could not load invoices. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['invoices'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No invoices')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('No invoices found for the linked customer.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive" role="region" tabindex="0" aria-labelledby="crm-invoices-title">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Number')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Issue')); ?></th>
						<th scope="col"><?php p($l->t('Gross')); ?></th>
						<th scope="col"><?php p($l->t('Paid')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['invoices'] as $inv): ?>
						<?php
						$icInvoiceId = (int)($inv['icInvoiceId'] ?? 0);
						$icDetailHref = ($icInvoiceId > 0 && !empty($_['capabilities']['invoicecheck']))
							? $urlGenerator->linkToRoute('invoicecheck.page.show', ['id' => $icInvoiceId])
							: '';
						$invoiceLabel = (string)($inv['invoiceRef'] ?? $l->t('Unlabelled invoice'));
						?>
						<tr>
							<td data-label="<?php p($l->t('Number')); ?>">
								<?php p($invoiceLabel); ?>
							</td>
							<td data-label="<?php p($l->t('Status')); ?>">
								<span class="crm-badge <?php echo !empty($inv['overdue']) ? 'crm-badge--danger' : 'crm-badge--neutral'; ?>"><?php p($inv['status'] ?? ''); ?></span><?php
									$agingBucket = array_key_exists('agingBucket', $inv) ? $inv['agingBucket'] : null;
									$daysPastDue = array_key_exists('daysPastDue', $inv) && $inv['daysPastDue'] !== null
										? (int)$inv['daysPastDue'] : null;
									include __DIR__ . '/common/aging-chip.php';
								?>
							</td>
							<td data-label="<?php p($l->t('Issue')); ?>"><?php
								$issueRaw = (string)($inv['issueDate'] ?? '');
								if ($issueRaw === '') {
									p('—');
								} elseif ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
									p($labels->formatDate($issueRaw));
								} else {
									p($issueRaw);
								}
							?></td>
							<td data-label="<?php p($l->t('Gross')); ?>"><?php p(number_format(((int)($inv['grossTotalCents'] ?? 0)) / 100, 2)); ?> €</td>
							<td data-label="<?php p($l->t('Paid')); ?>"><?php p(number_format(((int)($inv['paidTotalCents'] ?? 0)) / 100, 2)); ?> €</td>
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if ($icDetailHref !== ''): ?>
										<?php
										$crmRowOpenHref = $icDetailHref;
										$crmRowOpenLabel = $l->t('Open');
										include __DIR__ . '/common/crm-row-open.php';
										?>
									<?php else: ?>
										<span class="crm-muted" aria-hidden="true">—</span>
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
	<?php endif; ?>
	</div>

	<div id="crm-360-panel-more" class="crm-360-focus__panel" role="tabpanel" data-crm-focus-panel="more" aria-labelledby="crm-360-tab-more" aria-hidden="true" hidden>
	<section class="crm-card crm-card--account-plan" id="crm-account-plan" aria-labelledby="crm-account-plan-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-account-plan-title" class="crm-card__title"><?php p($l->t('Account plan')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Where this customer should go — write the goal, then the next moves.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body" id="crm-account-plan-body">
			<p class="crm-muted"><?php p($l->t('Loading account plan…')); ?></p>
		</div>
	</section>

<?php if (empty($identityMergedIntoBundle)): ?>
	<details class="crm-card crm-card--collapsible" id="crm-identity-links" aria-labelledby="crm-identity-links-title"<?php if (!empty($identityForceOpen)): ?> open<?php endif; ?>
		data-company-id="<?php p((int)$company->getId()); ?>"
		data-updated-at="<?php p((string)($_['companyUpdatedAt'] ?? '')); ?>"
		data-pc-state="<?php p($pcState); ?>"
		data-tc-state="<?php p($tcState); ?>">
		<summary class="crm-card__header crm-card__header--summary">
			<div class="crm-card__header-text">
				<h2 id="crm-identity-links-title" class="crm-card__title"><?php p($l->t('App links')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Change or fix ProjectCheck and TicketCheck links.')); ?></p>
			</div>
			<?php include __DIR__ . '/common/crm-collapsible-disclosure.php'; ?>
		</summary>
		<div class="crm-card__body">
			<?php
			$crmIdentityShowMoreWrap = true;
			include __DIR__ . '/common/crm-identity-rows.php';
			?>
		</div>
	</details>
<?php endif; ?>

		<?php include __DIR__ . '/common/crm-emails-section.php'; ?>


	<nav class="crm-platform-links" id="crm-platform-links" hidden aria-label="<?php p($l->t('Open related Nextcloud apps')); ?>" data-company-id="<?php p((int)$company->getId()); ?>"></nav>

	<details class="crm-card crm-card--collapsible" id="crm-timeline" aria-labelledby="crm-timeline-title">
		<summary class="crm-card__header crm-card__header--summary">
			<div class="crm-card__header-text">
				<h2 id="crm-timeline-title" class="crm-card__title"><?php p($l->t('Timeline')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('What happened on this account, newest first.')); ?></p>
			</div>
			<?php include __DIR__ . '/common/crm-collapsible-disclosure.php'; ?>
		</summary>
		<div class="crm-card__body">
			<?php if (($_['timeline'] ?? []) === []): ?>
				<p class="crm-muted" role="status"><?php p($l->t('No events yet.')); ?></p>
			<?php else: ?>
			<ol class="crm-timeline">
			<?php foreach ($_['timeline'] as $ev):
				$whenIso = (string)($ev['createdAt'] ?? '');
				$whenLabel = (string)($ev['createdAtLabel'] ?? $whenIso);
				$what = (string)($ev['eventTypeLabel'] ?? $ev['eventType'] ?? '');
				$who = (string)($ev['actorLabel'] ?? $ev['actor'] ?? '');
				?>
				<li class="crm-timeline__item">
					<article class="crm-timeline__card" aria-label="<?php p(trim($what . ' · ' . $whenLabel)); ?>">
						<p class="crm-timeline__what"><?php p($what); ?></p>
						<p class="crm-timeline__meta">
							<time class="crm-timeline__when" datetime="<?php p($whenIso); ?>"><?php p($whenLabel); ?></time>
							<?php if ($who !== ''): ?>
								<span class="crm-timeline__sep" aria-hidden="true">·</span>
								<span class="crm-timeline__who"><?php p($l->t('by %s', [$who])); ?></span>
							<?php endif; ?>
						</p>
					</article>
				</li>
			<?php endforeach; ?>
			</ol>
			<?php endif; ?>
		</div>
	</details>

</div>
	</div>
</div>

<?php

include __DIR__ . '/common/page-end.php'; ?>
