<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
/** @var \OCP\IURLGenerator $urlGenerator */
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
$ticketsHasData = ($_['tickets'] ?? []) !== [];
$invoicesHasData = ($_['invoices'] ?? []) !== [];
$projectsHasData = ($_['projects'] ?? []) !== [];
// Timeline often has a create event immediately — still collapse empty sibling panels.
$linkedBundleCollapsed = !$ticketsHasData && !$invoicesHasData && !$projectsHasData;
?>
<div class="crm-page-actions-source header-actions" hidden>
	<?php if (!empty($_['canWrite'])): ?>
	<a class="crm-btn crm-btn--primary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyEdit', ['id' => $company->getId()])); ?>"><?php p($l->t('Edit')); ?></a>
	<?php endif; ?>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-gdpr-export" data-company-id="<?php p($company->getId()); ?>" data-slug="<?php p($company->getSlug()); ?>"><?php p($l->t('Export')); ?></button>
	<?php if (!empty($_['canWrite']) || !empty($_['canMove']) || !empty($_['canDelete'])): ?>
	<details class="crm-more-menu">
		<summary class="crm-btn crm-btn--secondary"><?php p($l->t('More')); ?></summary>
		<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('More actions')); ?>">
			<?php if (!empty($_['canWrite'])): ?>
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-merge" data-company-id="<?php p($company->getId()); ?>" data-updated-at="<?php p(\OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt()) ?? ''); ?>" <?php if ($company->isArchived()): ?>disabled<?php endif; ?>><?php p($l->t('Merge into…')); ?></button>
			<?php endif; ?>
			<?php if (!empty($_['canMove'])): ?>
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-move-workspace"
				data-company-id="<?php p($company->getId()); ?>"
				data-slug="<?php p($company->getSlug()); ?>"
				data-workspace-id="<?php p((int)($company->getWorkspaceId() ?? 0)); ?>"
				data-updated-at="<?php p(\OCA\CustomerCheck\Support\OptimisticLock::format($company->getUpdatedAt()) ?? ''); ?>"
				<?php if ($company->isArchived()): ?>disabled<?php endif; ?>><?php p($l->t('Move to workspace…')); ?></button>
			<?php endif; ?>
			<?php if (!empty($_['canDelete'])): ?>
			<button type="button" class="crm-btn crm-btn--danger" id="crm-company-gdpr-erase" data-company-id="<?php p($company->getId()); ?>" data-slug="<?php p($company->getSlug()); ?>"><?php p($l->t('Erase (GDPR)')); ?></button>
			<?php endif; ?>
		</div>
	</details>
	<?php endif; ?>
</div>
<div id="crm-live-region" class="crm-sr-only" aria-live="polite"></div>
<div class="crm-page-stack" data-company-id="<?php p($company->getId()); ?>" data-workspace-id="<?php p((int)($_['workspaceId'] ?? $company->getWorkspaceId() ?? 0)); ?>" data-workspace-name="<?php p((string)($_['workspaceName'] ?? '')); ?>" data-crm-persons="<?php p($crmPersonsJson); ?>">
	<nav class="crm-back" aria-label="<?php p($l->t('Back')); ?>">
		<a class="crm-btn crm-btn--ghost crm-btn--sm" href="<?php p($_['urls']['companies'] ?? $urlGenerator->linkToRoute('customercheck.page.companies')); ?>">← <?php p($l->t('Companies')); ?></a>
	</nav>

	<section class="crm-card" id="crm-identity" aria-labelledby="crm-identity-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-identity-title" class="crm-card__title"><?php p($company->getName()); ?></h2>
				<p class="crm-card__lead">
					<span class="crm-badge crm-badge--<?php p($company->getStatus() === 'active' ? 'success' : 'neutral'); ?>"><?php
						$labels = $_['labels'] ?? null;
						p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
							? $labels->companyStatus((string)$company->getStatus())
							: (string)$company->getStatus());
					?></span>
					· <?php p(strtoupper((string)$company->getLang())); ?>
				</p>
			</div>
		</header>
		<?php if ($company->getNotes()): ?>
		<div class="crm-card__body"><p><?php p($company->getNotes()); ?></p></div>
		<?php endif; ?>
	</section>

	<?php
	$roleLabel = (string)($_['roleLabel'] ?? '');
	$canWritePage = !empty($_['canWrite']);
	$roleHelp = $canWritePage
		? $l->t('You can edit. Work top to bottom: talk → people → deals → money.')
		: $l->t('Look-only role. You can open every section — buttons to add or change are hidden.');
	?>
	<section class="crm-card crm-360-role" aria-labelledby="crm-360-role-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-360-role-title" class="crm-card__title"><?php p($l->t('How to use this page')); ?></h2>
				<p class="crm-card__lead"><?php p($roleHelp); ?></p>
			</div>
			<?php if ($roleLabel !== ''): ?>
				<span class="crm-badge crm-badge--info" aria-label="<?php p($l->t('Role')); ?>"><?php p($roleLabel); ?></span>
			<?php endif; ?>
		</header>
	</section>



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
	?>
	<section class="crm-card" id="crm-glance" aria-labelledby="crm-glance-title">
		<div class="crm-glance-pin">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h2 id="crm-glance-title" class="crm-card__title"><?php p($l->t('At a glance')); ?></h2>
					<p class="crm-card__lead"><?php p($l->t('Who, last talk, next step, money and renewals — at a glance.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body" id="crm-glance-summary">
				<p class="crm-muted"><?php p($l->t('Loading overview…')); ?></p>
			</div>
		</div>
		<div class="crm-card__body crm-glance-density">
			<div class="crm-glance-density__intro">
				<h3 id="crm-glance-density-title" class="crm-glance-density__title"><?php p($l->t('Also in linked apps')); ?></h3>
				<p class="crm-glance-density__lead"><?php p($l->t('Big number first — tap Open to jump there.')); ?></p>
			</div>
			<ul class="crm-glance-density__list" aria-labelledby="crm-glance-density-title">
				<?php foreach ($glanceTiles as $tile):
					$tone = (string)($tile['tone'] ?? 'calm');
					$tileHref = (string)($tile['href'] ?? '#crm-glance');
					?>
					<li class="crm-glance-density__item">
						<a class="crm-glance-kpi crm-glance-kpi--<?php p($tone); ?>"
							href="<?php p($tileHref); ?>"
							aria-label="<?php p((string)($tile['ariaLabel'] ?? $tile['label'] ?? '')); ?>">
							<span class="crm-glance-kpi__value" aria-hidden="true"><?php p((string)(int)($tile['value'] ?? 0)); ?></span>
							<span class="crm-glance-kpi__text">
								<span class="crm-glance-kpi__label"><?php p((string)($tile['label'] ?? '')); ?></span>
								<span class="crm-glance-kpi__hint"><?php p((string)($tile['hint'] ?? '')); ?></span>
							</span>
							<span class="crm-glance-kpi__cta" aria-hidden="true"><?php p((string)($tile['cta'] ?? $l->t('Open'))); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>

	<?php
	$jumpNavAriaLabel = $l->t('Jump to section');
	$jumpNavLayout = 'bar';
	$jumpNavPrimaryMax = 5;
	$jumpNavItems = [
		['href' => '#crm-glance', 'label' => $l->t('At a glance'), 'primary' => true],
		['href' => '#crm-communication', 'label' => $l->t('Talk'), 'primary' => true],
		['href' => '#crm-persons', 'label' => $l->t('People'), 'primary' => true],
		['href' => '#crm-deals', 'label' => $l->t('Deals'), 'primary' => true],
		['href' => '#crm-billing', 'label' => $l->t('Money'), 'primary' => true],
		['href' => '#crm-contracts', 'label' => $l->t('Agreements')],
		['href' => '#crm-promises', 'label' => $l->t('Promises')],
		['href' => '#crm-open-tasks', 'label' => $l->t('Tasks')],
		['href' => '#crm-activities', 'label' => $l->t('Reminders')],
		['href' => '#crm-documents', 'label' => $l->t('Files')],
		['href' => '#crm-invoices', 'label' => $l->t('Invoices')],
		['href' => '#crm-identity-links', 'label' => $l->t('Link apps')],
		['href' => '#crm-account-plan', 'label' => $l->t('Account plan')],
		['href' => '#crm-projects', 'label' => $l->t('Projects')],
		['href' => '#crm-tickets', 'label' => $l->t('Tickets')],
		['href' => '#crm-prepare-meeting', 'label' => $l->t('Prepare meeting')],
		['href' => '#crm-timeline', 'label' => $l->t('Timeline')],
	];
	include __DIR__ . '/common/crm-jump-nav.php';
	?>

	
	<div class="crm-360-zone" data-crm-zone="now" aria-labelledby="crm-zone-now">
		<p class="crm-360-zone__label" id="crm-zone-now"><?php p($l->t('1 · Talk & people')); ?></p>
<?php
	$personsEmpty = ($_['persons'] ?? []) === [];
	$dealsEmpty = ($_['deals'] ?? []) === [];
	$showWelcomeCoach = !empty($_['canWrite']) && ($personsEmpty || $dealsEmpty);
	?>
	<?php if ($showWelcomeCoach): ?>
	<aside class="crm-callout crm-callout--info crm-welcome-coach" id="crm-welcome-coach" role="region" aria-labelledby="crm-welcome-coach-title">
		<span class="crm-callout__icon" aria-hidden="true"></span>
		<div class="crm-callout__text">
			<h2 id="crm-welcome-coach-title" class="crm-welcome-coach__title"><?php p($l->t('Next steps for this customer')); ?></h2>
			<ol class="crm-welcome-coach__steps">
				<li><?php p($l->t('Add a person you talk to (name is enough; email helps for logging messages).')); ?></li>
				<li><?php p($l->t('Add a deal when there is a quote or opportunity — only a title is required.')); ?></li>
				<li><?php p($l->t('Use Log email or Call tomorrow under Communication when you talk.')); ?></li>
			</ol>
			<div class="crm-welcome-coach__actions crm-empty-state__actions">
				<?php if ($personsEmpty): ?>
				<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-person"><?php p($l->t('Add person')); ?></button>
				<?php endif; ?>
				<?php if ($dealsEmpty): ?>
				<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" data-crm-trigger="crm-add-deal"><?php p($l->t('Add deal')); ?></button>
				<?php endif; ?>
			</div>
		</div>
	</aside>
	<?php endif; ?>

	<section class="crm-card" id="crm-communication" aria-labelledby="crm-communication-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-communication-title" class="crm-card__title"><?php p($l->t('Talk')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('What you last discussed — emails, calls and notes in one list.')); ?></p>
			</div>
			<div class="crm-inline-actions">
				<?php if (!empty($_['canWrite'])): ?>
				<button type="button" class="crm-btn crm-btn--primary crm-btn--sm" id="crm-log-email" data-crm-log-email><?php p($l->t('Log email')); ?></button>
				<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-schedule-callback" title="<?php p($l->t('Schedules a call for tomorrow')); ?>"><?php p($l->t('Call tomorrow')); ?></button>
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

<section class="crm-card" id="crm-persons" aria-labelledby="crm-persons-title" data-contacts-available="<?php p(!empty($_['contactsAvailable']) ? '1' : '0'); ?>" data-contacts-home="<?php p((string)($_['contactsHomeUrl'] ?? '/apps/contacts/')); ?>">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-persons-title" class="crm-card__title"><?php p($l->t('People')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Contacts at this company.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-person"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add person')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (($_['persons'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No contacts yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add someone you talk to. A name is enough — an email lets you log messages with them.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-person"><?php p($l->t('Add person')); ?></button>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive crm-persons-table-wrap">
				<table class="table table--hover" id="crm-persons-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Name')); ?></th>
						<th scope="col"><?php p($l->t('Email')); ?></th>
						<th scope="col"><?php p($l->t('Last touch')); ?></th>
						<th scope="col"><?php p($l->t('Address book')); ?></th>
						<th scope="col"><?php p($l->t('Role in buying')); ?></th>
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
					?>
						<tr data-person-id="<?php p((int)$p['id']); ?>" data-contacts-uid="<?php p((string)($p['contactsUid'] ?? '')); ?>" data-updated-at="<?php p((string)($p['updatedAt'] ?? '')); ?>" data-buying-role="<?php p($buyingRole); ?>">
							<td data-label="<?php p($l->t('Name')); ?>">
								<?php p($p['name']); ?><?php if (!empty($p['isPrimary'])): ?> <span class="crm-badge crm-badge--info"><?php p($l->t('Primary')); ?></span><?php endif; ?>
								<?php if (!empty($p['phone']) || !empty($p['role'])): ?>
									<p class="crm-muted crm-person-meta">
										<?php if (!empty($p['role'])): ?><?php p($p['role']); ?><?php endif; ?>
										<?php if (!empty($p['phone'])): ?><?php if (!empty($p['role'])): ?> · <?php endif; ?><?php p($p['phone']); ?><?php endif; ?>
									</p>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Email')); ?>"><?php p($p['email'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Last touch')); ?>">
								<?php if ($hasTouch): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-person-last-touch="<?php p((int)$p['id']); ?>"><?php p($ltLabel); ?></button>
								<?php else: ?>
									<span class="crm-muted"><?php p($ltLabel); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Address book')); ?>">
								<?php if ($linked && !empty($_['contactsAvailable'])): ?>
									<span class="crm-badge crm-badge--success"><?php p($l->t('Linked')); ?></span>
								<?php elseif ($linked): ?>
									<span class="crm-badge crm-badge--neutral" title="<?php p($l->t('Contacts app is not available — link kept.')); ?>"><?php p($l->t('Linked')); ?></span>
								<?php else: ?>
									<span class="crm-muted"><?php p($l->t('Not linked')); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Role in buying')); ?>">
								<?php if (!empty($_['canWrite'])): ?>
									<label class="crm-sr-only" for="crm-buying-role-<?php p((int)$p['id']); ?>"><?php p($l->t('Role in buying')); ?></label>
									<select id="crm-buying-role-<?php p((int)$p['id']); ?>" class="crm-input crm-input--sm crm-persons-role" data-crm-buying-role="<?php p((int)$p['id']); ?>" data-updated-at="<?php p((string)($p['updatedAt'] ?? '')); ?>">
										<?php foreach ($buyingRoleOptions as $opt): ?>
											<option value="<?php p($opt['value']); ?>" <?php if ((string)$opt['value'] === $buyingRole): ?>selected<?php endif; ?>><?php p($opt['label']); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else: ?>
									<?php
									$roleLabel = $buyingRole;
									if ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService && $buyingRole !== '') {
										$roleLabel = $labels->buyingRole($buyingRole);
									}
									?>
									<span class="crm-muted"><?php p($roleLabel !== '' ? $roleLabel : '—'); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if ($linked && !empty($_['contactsAvailable'])): ?>
										<a class="crm-btn crm-btn--sm crm-btn--primary" href="<?php p((string)$_['contactsHomeUrl']); ?>" data-crm-open-contacts="<?php p((int)$p['id']); ?>" data-person-name="<?php p($p['name']); ?>"><?php p($l->t('Open in Contacts')); ?></a>
										<?php if (!empty($_['canWrite'])): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-unlink-contacts="<?php p((int)$p['id']); ?>"><?php p($l->t('Unlink')); ?></button>
										<?php endif; ?>
									<?php elseif ($hasTouch): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-person-last-touch="<?php p((int)$p['id']); ?>"><?php p($l->t('See talk')); ?></button>
									<?php else: ?>
										<a class="crm-btn crm-btn--sm crm-btn--primary" href="#crm-communication"><?php p($l->t('Log talk')); ?></a>
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

	<div class="crm-360-zone" data-crm-zone="sell" aria-labelledby="crm-zone-sell">
		<p class="crm-360-zone__label" id="crm-zone-sell"><?php p($l->t('2 · Sell & keep')); ?></p>
<section class="crm-card" id="crm-deals" aria-labelledby="crm-deals-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-deals-title" class="crm-card__title"><?php p($l->t('Deals')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Quotes and opportunities for this customer.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-deal"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add deal')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (($_['deals'] ?? []) === []): ?>
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
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Stage')); ?></th>
						<th scope="col"><?php p($l->t('Value')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Action')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['deals'] as $d): ?>
						<tr data-deal-id="<?php p($d['id']); ?>">
							<td data-label="<?php p($l->t('Title')); ?>"><?php p($d['title']); ?><?php if (!empty($d['stale'])): ?> <span class="crm-badge crm-badge--warning"><?php p($l->t('Stale')); ?></span><?php endif; ?></td>
							<td data-label="<?php p($l->t('Stage')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->dealStage((string)$d['stage'])
									: (string)$d['stage']);
							?></span></td>
							<td data-label="<?php p($l->t('Value')); ?>"><?php
								$cents = (int)($d['valueCents'] ?? 0);
								p($cents > 0 ? number_format($cents / 100, 2) . ' €' : '—');
							?></td>
							<td data-label="<?php p($l->t('Action')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if (!empty($_['canWrite'])): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-edit-chase="<?php p($d['id']); ?>"><?php p($l->t('Edit deal')); ?></button>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-dismiss-followups="<?php p($d['id']); ?>"><?php p($l->t('Stop reminders')); ?></button>
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




	<section class="crm-card" id="crm-promises" aria-labelledby="crm-promises-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-promises-title" class="crm-card__title"><?php p($l->t('Customer promises')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('What you told the customer.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-promise"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add promise')); ?></button>
		</header>
		<div class="crm-card__body" id="crm-promises-body">
			<p class="crm-muted"><?php p($l->t('Loading promises…')); ?></p>
		</div>
	</section>

<section class="crm-card" id="crm-contracts" aria-labelledby="crm-contracts-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-contracts-title" class="crm-card__title"><?php p($l->t('Agreements')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Licenses and Check Partner packages.')); ?></p>
			</div>
			<?php if (!empty($_['canWrite'])): ?>
			<a class="crm-btn crm-btn--secondary crm-btn--sm" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>?companyId=<?php p((int)$company->getId()); ?>"><?php p($l->t('New agreement')); ?></a>
			<?php endif; ?>
		</header>
		<div class="crm-card__body">
			<?php if (($_['agreements'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No agreements yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add a license or partner package when you have one — or import later.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>?companyId=<?php p((int)$company->getId()); ?>"><?php p($l->t('New agreement')); ?></a>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Label')); ?></th>
						<th scope="col"><?php p($l->t('Product')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Expiry')); ?></th>
						<th scope="col"><?php p($l->t('Invoice')); ?></th>
						<th scope="col"><?php p($l->t('Amount')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['agreements'] as $a):
						$agStatus = (string)($a['status'] ?? '');
						$canLifecycle = in_array($agStatus, ['draft', 'active', 'expiring', 'expired'], true);
						?>
						<tr data-agreement-id="<?php p($a['id']); ?>" data-kind="<?php p($a['kind']); ?>" data-updated-at="<?php p($a['updatedAt'] ?? ''); ?>" data-status="<?php p($agStatus); ?>" data-valid-until="<?php p($a['validUntil'] ?? ''); ?>" data-amount-cents="<?php p((int)($a['amountCents'] ?? 0)); ?>" data-label="<?php p($a['label']); ?>">
							<td data-label="<?php p($l->t('Label')); ?>"><?php if (!empty($_['canWrite'])): ?><a href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => $a['id']])); ?>"><?php p($a['label']); ?></a><?php else: ?><?php p($a['label']); ?><?php endif; ?></td>
							<td data-label="<?php p($l->t('Product')); ?>"><code><?php p($a['productCode']); ?></code></td>
							<td data-label="<?php p($l->t('Status')); ?>"><span class="crm-badge"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->agreementStatus((string)$a['status'])
									: (string)$a['status']);
							?></span></td>
							<td data-label="<?php p($l->t('Expiry')); ?>"><span class="crm-badge crm-badge--<?php p($a['chip']['variant'] ?? 'neutral'); ?>"><?php p($a['chip']['label'] ?? ($a['validUntil'] ?? '—')); ?></span></td>
							<td data-label="<?php p($l->t('Invoice')); ?>"><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService
									? $labels->invoiceStatus((string)$a['invoiceStatus'])
									: (string)$a['invoiceStatus']);
							?><?php if (!empty($a['invoiceRef'])): ?> · <?php p($a['invoiceRef']); ?><?php endif; ?></td>
							<td data-label="<?php p($l->t('Amount')); ?>">
								<?php p(number_format(($a['amountCents'] ?? 0) / 100, 2)); ?> €
							</td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<?php if (!empty($_['canWrite'])): ?>
										<?php
										$crmRowOpenHref = $urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => (int)$a['id']]);
										$crmRowOpenLabel = $l->t('Edit');
										include __DIR__ . '/common/crm-row-open.php';
										?>
										<?php if (($a['kind'] ?? '') === 'partner'): ?>
											<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-partner-session="<?php p($a['id']); ?>" title="<?php p($l->t('Open partner mail, then log hours')); ?>"><?php p($l->t('Email & log hours')); ?></button>
											<?php if (!empty($a['quota'])): ?>
												<span class="crm-badge <?php echo !empty($a['quota']['alert']) ? 'crm-badge--warning' : 'crm-badge--neutral'; ?>" title="<?php p($l->t('Partner quota')); ?>">
													<?php p(number_format(($a['quota']['usedMinutes'] ?? 0) / 60, 2)); ?> / <?php p(number_format(($a['quota']['quotaMinutes'] ?? 0) / 60, 2)); ?> h
												</span>
											<?php endif; ?>
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
											</div>
										</details>
									<?php else: ?>
										<?php if (!empty($a['quota']) && ($a['kind'] ?? '') === 'partner'): ?>
											<span class="crm-badge <?php echo !empty($a['quota']['alert']) ? 'crm-badge--warning' : 'crm-badge--neutral'; ?>" title="<?php p($l->t('Partner quota')); ?>">
												<?php p(number_format(($a['quota']['usedMinutes'] ?? 0) / 60, 2)); ?> / <?php p(number_format(($a['quota']['quotaMinutes'] ?? 0) / 60, 2)); ?> h
											</span>
										<?php else: ?>
											<span class="crm-muted" aria-hidden="true">—</span>
										<?php endif; ?>
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

<section class="crm-card" id="crm-open-tasks" aria-labelledby="crm-open-tasks-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-open-tasks-title" class="crm-card__title"><?php p($l->t('Open tasks')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Done in CustomerCheck is what counts. Tasks app gets a copy you can open on your phone.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--primary crm-btn--sm" id="crm-new-task"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('New task')); ?></button>
		</header>
		<div class="crm-card__body" id="crm-open-tasks-body">
			<p class="crm-muted"><?php p($l->t('Loading tasks…')); ?></p>
		</div>
	</section>

<section class="crm-card" id="crm-activities" aria-labelledby="crm-activities-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-activities-title" class="crm-card__title"><?php p($l->t('Reminders')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Follow-ups you still owe (calls, reminders, to-dos).')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-activity"<?php if (empty($_['canWrite'])): ?> hidden<?php endif; ?>><?php p($l->t('Add activity')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (($_['activities'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Nothing open')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Schedule a call, reminder, or to-do. The due date uses your calendar picker.')); ?></p>
					<?php if (!empty($_['canWrite'])): ?>
					<div class="crm-empty-state__actions">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" data-crm-trigger="crm-add-activity"><?php p($l->t('Add activity')); ?></button>
					</div>
					<?php endif; ?>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th scope="col"><?php p($l->t('Title')); ?></th>
						<th scope="col"><?php p($l->t('Type')); ?></th>
						<th scope="col"><?php p($l->t('Due')); ?></th>
						<th scope="col"><?php p($l->t('Origin')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['activities'] as $act): ?>
						<tr>
							<td data-label="<?php p($l->t('Title')); ?>"><strong><?php p($act['title']); ?></strong></td>
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
							<td data-label="<?php p($l->t('Origin')); ?>">
								<?php if (($act['origin'] ?? '') === 'system' || str_starts_with((string)($act['origin'] ?? ''), 'sequence_')): ?>
									<span class="crm-badge crm-badge--info"><?php p($l->t('Auto')); ?></span>
								<?php else: ?>
									<?php p($l->t('User')); ?>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<?php if (!empty($_['canWrite'])): ?>
								<div class="crm-table-actions">
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-complete-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Done')); ?></button>
									<?php if (empty($act['todoUri'])): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-push-todo="<?php p($act['id']); ?>"><?php p($l->t('Send to Tasks')); ?></button>
									<?php else: ?>
										<span class="crm-badge crm-badge--success"><?php p($l->t('Sent to Tasks')); ?></span>
									<?php endif; ?>
								</div>
								<?php else: ?>
									<span class="crm-muted" aria-hidden="true">—</span>
								<?php endif; ?>
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

	<div class="crm-360-zone" data-crm-zone="money" aria-labelledby="crm-zone-money">
		<p class="crm-360-zone__label" id="crm-zone-money"><?php p($l->t('3 · Money & files')); ?></p>
<section class="crm-card" id="crm-billing" aria-labelledby="crm-billing-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-billing-title" class="crm-card__title"><?php p($l->t('Money & invoicing')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Overview from ProjectCheck & InvoiceCheck — not a tax audit.')); ?></p>
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
				<button type="button" class="crm-btn crm-btn--ghost crm-btn--sm" id="crm-create-folder"><?php p($l->t('Create folder')); ?></button>
				<button type="button" class="crm-btn crm-btn--ghost crm-btn--sm" id="crm-unlink-folder" hidden><?php p($l->t('Unlink folder')); ?></button>
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

	<?php if ($linkedBundleCollapsed): ?>
	<details class="crm-card crm-linked-bundle" id="crm-linked-bundle">
		<summary class="crm-card__header crm-linked-bundle__summary">
			<span class="crm-card__title crm-linked-bundle__title"><?php p($l->t('Linked apps')); ?></span>
			<span class="crm-muted crm-linked-bundle__hint"><?php p($l->t('Projects, tickets and invoices — expand when you need them.')); ?></span>
		</summary>
		<div class="crm-card__body crm-linked-bundle__body">
	<?php endif; ?>

<section class="crm-card<?php if ($linkedBundleCollapsed): ?> crm-card--nested<?php endif; ?>" id="crm-projects" aria-labelledby="crm-projects-title" aria-busy="false">
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
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="#crm-identity-links"><?php p($l->t('Link apps')); ?></a>
					</div>
				</div>
			<?php elseif (!empty($_['projectError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<span class="crm-callout__icon" aria-hidden="true"></span>
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

<section class="crm-card<?php if ($linkedBundleCollapsed): ?> crm-card--nested<?php endif; ?>" id="crm-tickets" aria-labelledby="crm-tickets-title" aria-busy="false">
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
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="#crm-identity-links"><?php p($l->t('Link apps')); ?></a>
					</div>
				</div>
			<?php elseif (!empty($_['ticketError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<span class="crm-callout__icon" aria-hidden="true"></span>
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







<section class="crm-card<?php if ($linkedBundleCollapsed): ?> crm-card--nested<?php endif; ?>" id="crm-invoices" aria-labelledby="crm-invoices-title" aria-busy="false">
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
					<div class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--primary" href="#crm-identity-links"><?php p($l->t('Link apps')); ?></a>
					</div>
				</div>
			<?php elseif (!empty($_['invoiceError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<span class="crm-callout__icon" aria-hidden="true"></span>
					<p class="crm-callout__text"><?php p($l->t('Could not load invoices. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['invoices'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No invoices')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('No invoices found for the linked customer.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
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
	<?php if ($linkedBundleCollapsed): ?>
		</div>
	</details>
	<?php endif; ?>
	</div>

	<div class="crm-360-zone" data-crm-zone="later" aria-labelledby="crm-zone-later">
		<p class="crm-360-zone__label" id="crm-zone-later"><?php p($l->t('4 · Setup & history (rarely needed)')); ?></p>
	<details class="crm-card crm-card--collapsible" id="crm-account-plan" aria-labelledby="crm-account-plan-title">
		<summary class="crm-card__header crm-card__header--summary">
			<div class="crm-card__header-text">
				<h2 id="crm-account-plan-title" class="crm-card__title"><?php p($l->t('Account plan')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Goal, priority and next steps for this account.')); ?></p>
			</div>
		</summary>
		<div class="crm-card__body" id="crm-account-plan-body">
			<p class="crm-muted"><?php p($l->t('Loading account plan…')); ?></p>
		</div>
	</details>

<?php
	$pcState = (string)($identity['pc']['state'] ?? 'not_linked');
	$tcState = (string)($identity['tc']['state'] ?? 'not_linked');
	$pcWrite = !empty($_['pcWriteAvailable']);
	$tcWrite = !empty($_['tcWriteAvailable']);
	$mnAvail = !empty($_['mnIdentityAvailable']);
	$canWriteIdentity = !empty($_['canWrite']);
	$showPcCta = $canWriteIdentity && $pcWrite && in_array($pcState, ['not_linked', 'broken'], true);
	$showTcCta = $canWriteIdentity && $tcWrite && in_array($tcState, ['not_linked', 'broken'], true);
	$identityForceOpen = ($pcState === 'broken' || $tcState === 'broken');
	?>
	<details class="crm-card crm-card--collapsible" id="crm-identity-links" aria-labelledby="crm-identity-links-title"<?php if (!empty($identityForceOpen)): ?> open<?php endif; ?>
		data-company-id="<?php p((int)$company->getId()); ?>"
		data-updated-at="<?php p((string)($_['companyUpdatedAt'] ?? '')); ?>"
		data-pc-state="<?php p($pcState); ?>"
		data-tc-state="<?php p($tcState); ?>">
		<summary class="crm-card__header crm-card__header--summary">
			<div class="crm-card__header-text">
				<h2 id="crm-identity-links-title" class="crm-card__title"><?php p($l->t('Link apps (setup)')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Only when invoices, projects or tickets need a link — most days you can ignore this.')); ?></p>
			</div>
		</summary>
		<div class="crm-card__body">
			<div id="crm-identity-alert" class="crm-callout crm-callout--danger" role="alert" hidden></div>

			<div class="crm-identity-row" data-sibling="pc">
				<p class="crm-identity-row__status">
					<span class="crm-badge crm-badge--<?php p($pcState === 'linked' ? 'success' : ($pcState === 'broken' ? 'danger' : 'neutral')); ?>"><?php p($pcState === 'linked' ? $l->t('Linked') : ($pcState === 'broken' ? $l->t('Broken link') : ($pcState === 'sibling_unavailable' ? $l->t('Unavailable') : $l->t('Not linked')))); ?></span>
					<span><?php p((string)($identity['pc']['label'] ?? '')); ?></span>
				</p>
				<?php if ($pcState === 'broken' && $canWriteIdentity): ?>
					<div class="crm-callout crm-callout--danger" role="alert">
						<p class="crm-callout__text"><?php p((string)($identity['pc']['label'] ?? $l->t('Broken ProjectCheck link'))); ?></p>
						<div class="crm-inline-actions crm-inline-actions--roomy">
							<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="pc"><?php p($l->t('Unlink')); ?></button>
							<?php if ($pcWrite): ?>
							<button type="button" class="crm-btn crm-btn--primary" data-crm-identity-create="pc"><?php p($l->t('Create in ProjectCheck')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" data-crm-identity-link="pc"><?php p($l->t('Link existing')); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($showPcCta): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<button type="button" class="crm-btn crm-btn--primary"
							aria-label="<?php p($l->t('Create ProjectCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-create="pc"><?php p($l->t('Create in ProjectCheck')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary"
							aria-label="<?php p($l->t('Link existing ProjectCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-link="pc"><?php p($l->t('Link existing')); ?></button>
					</div>
				<?php elseif ($pcState === 'linked' && !empty($identity['pc']['deepLink'])): ?>
					<p><a class="crm-btn crm-btn--ghost" href="<?php p((string)$identity['pc']['deepLink']); ?>"><?php p($l->t('Open in ProjectCheck')); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="crm-identity-row crm-identity-row--spaced" data-sibling="tc">
				<p class="crm-identity-row__status">
					<span class="crm-badge crm-badge--<?php p($tcState === 'linked' ? 'success' : ($tcState === 'broken' ? 'danger' : 'neutral')); ?>"><?php p($tcState === 'linked' ? $l->t('Linked') : ($tcState === 'broken' ? $l->t('Broken link') : ($tcState === 'sibling_unavailable' ? $l->t('Unavailable') : $l->t('Not linked')))); ?></span>
					<span><?php p((string)($identity['tc']['label'] ?? '')); ?></span>
				</p>
				<?php if ($tcState === 'broken' && $canWriteIdentity): ?>
					<div class="crm-callout crm-callout--danger" role="alert">
						<p class="crm-callout__text"><?php p((string)($identity['tc']['label'] ?? $l->t('Broken TicketCheck link'))); ?></p>
						<div class="crm-inline-actions crm-inline-actions--roomy">
							<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="tc"><?php p($l->t('Unlink')); ?></button>
							<?php if ($tcWrite): ?>
							<button type="button" class="crm-btn crm-btn--primary" data-crm-identity-create="tc"><?php p($l->t('Create in TicketCheck')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" data-crm-identity-link="tc"><?php p($l->t('Link existing')); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($showTcCta): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<button type="button" class="crm-btn crm-btn--primary"
							aria-label="<?php p($l->t('Create TicketCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-create="tc"><?php p($l->t('Create in TicketCheck')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary" aria-label="<?php p($l->t('Link existing TicketCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-link="tc"><?php p($l->t('Link existing')); ?></button>
					</div>
				<?php elseif ($tcState === 'linked' && !empty($identity['tc']['deepLink'])): ?>
					<p><a class="crm-btn crm-btn--ghost" href="<?php p((string)$identity['tc']['deepLink']); ?>"><?php p($l->t('Open in TicketCheck')); ?></a></p>
				<?php endif; ?>
			</div>

			<?php if ($mnAvail && $canWriteIdentity): ?>
			<div class="crm-identity-row crm-identity-row--spaced" data-sibling="mn">
				<p class="crm-muted"><?php p($l->t('Field register (MaintenanceCheck)')); ?></p>
				<button type="button" class="crm-btn crm-btn--secondary" data-crm-identity-create-field>
					<?php p($l->t('Create field customer')); ?>
				</button>
			</div>
			<?php endif; ?>
		</div>
	</details>

		<?php include __DIR__ . '/common/crm-emails-section.php'; ?>


	<nav class="crm-platform-links" id="crm-platform-links" aria-label="<?php p($l->t('Open related Nextcloud apps')); ?>" data-company-id="<?php p((int)$company->getId()); ?>">
		<p class="crm-muted crm-platform-links__hint"><?php p($l->t('Related apps load here when available.')); ?></p>
	</nav>

	<section class="crm-card" id="crm-prepare-meeting" aria-labelledby="crm-prepare-meeting-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-prepare-meeting-title" class="crm-card__title"><?php p($l->t('Prepare meeting')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('One stack for the next call or quarterly review.')); ?></p>
			</div>
			<a class="crm-btn crm-btn--primary crm-btn--sm" href="<?php p((string)($_['prepareMeetingUrl'] ?? '#')); ?>"><?php p($l->t('Open prepare meeting')); ?></a>
		</header>
		<div class="crm-card__body">
			<p class="crm-muted"><?php p($l->t('Coverage, people, recent talk, open promises, next steps and money in one pack.')); ?></p>
		</div>
	</section>

<section class="crm-card" id="crm-timeline" aria-labelledby="crm-timeline-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-timeline-title" class="crm-card__title"><?php p($l->t('Timeline')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('What happened on this account, newest first.')); ?></p>
			</div>
		</header>
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
	</section>

</div>
	</div>

<?php

include __DIR__ . '/common/page-end.php'; ?>
