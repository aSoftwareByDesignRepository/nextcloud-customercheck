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
	<a class="crm-btn crm-btn--primary" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyEdit', ['id' => $company->getId()])); ?>"><?php p($l->t('Edit')); ?></a>
	<button type="button" class="crm-btn crm-btn--secondary" id="crm-company-gdpr-export" data-company-id="<?php p($company->getId()); ?>" data-slug="<?php p($company->getSlug()); ?>"><?php p($l->t('Export')); ?></button>
	<details class="crm-more-menu">
		<summary class="crm-btn crm-btn--secondary"><?php p($l->t('More')); ?></summary>
		<div class="crm-more-menu__panel" role="group" aria-label="<?php p($l->t('More actions')); ?>">
			<button type="button" class="crm-btn crm-btn--danger crm-btn--sm" id="crm-company-gdpr-erase" data-company-id="<?php p($company->getId()); ?>" data-slug="<?php p($company->getSlug()); ?>"><?php p($l->t('Erase (GDPR)')); ?></button>
		</div>
	</details>
</div>
<div class="crm-page-stack" data-company-id="<?php p($company->getId()); ?>" data-crm-persons="<?php p($crmPersonsJson); ?>">
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
	$jumpNavAriaLabel = $l->t('Jump to section');
	$jumpNavLayout = 'bar';
	$jumpNavPrimaryMax = 5;
	$jumpNavItems = [
		['href' => '#crm-agreements', 'label' => $l->t('Agreements'), 'primary' => true],
		['href' => '#crm-persons', 'label' => $l->t('People'), 'primary' => true],
		['href' => '#crm-emails', 'label' => $l->t('Who wrote what'), 'primary' => true],
		['href' => '#crm-activities', 'label' => $l->t('Activities'), 'primary' => true],
		['href' => '#crm-tickets', 'label' => $l->t('Tickets'), 'primary' => true],
		['href' => '#crm-deals', 'label' => $l->t('Deals')],
		['href' => '#crm-invoices', 'label' => $l->t('Invoices')],
		['href' => '#crm-projects', 'label' => $l->t('Projects')],
		['href' => '#crm-timeline', 'label' => $l->t('Timeline')],
	];
	include __DIR__ . '/common/crm-jump-nav.php';
	?>

	<section class="crm-card" id="crm-agreements" aria-labelledby="crm-agreements-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-agreements-title" class="crm-card__title"><?php p($l->t('Agreements')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Licenses and Check Partner packages.')); ?></p>
			</div>
			<a class="crm-btn crm-btn--secondary crm-btn--sm" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>?companyId=<?php p((int)$company->getId()); ?>"><?php p($l->t('New agreement')); ?></a>
		</header>
		<div class="crm-card__body">
			<?php if (($_['agreements'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No agreements yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add a license or partner package, or import from the sales log.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th><?php p($l->t('Label')); ?></th>
						<th><?php p($l->t('Product')); ?></th>
						<th><?php p($l->t('Status')); ?></th>
						<th><?php p($l->t('Expiry')); ?></th>
						<th><?php p($l->t('Invoice')); ?></th>
						<th><?php p($l->t('Amount')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['agreements'] as $a): ?>
						<tr data-agreement-id="<?php p($a['id']); ?>" data-kind="<?php p($a['kind']); ?>">
							<td data-label="<?php p($l->t('Label')); ?>"><a href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementEdit', ['id' => $a['id']])); ?>"><?php p($a['label']); ?></a></td>
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
								<div class="crm-inline-actions">
								<?php if (($a['kind'] ?? '') === 'license' && in_array(($a['status'] ?? ''), ['expiring', 'expired', 'active'], true) && !empty($a['validUntil'])): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-renewal-compose="renewal_60" data-agreement-id="<?php p($a['id']); ?>"><?php p($l->t('Send renewal quote')); ?></button>
								<?php endif; ?>
								<?php if (($a['kind'] ?? '') === 'license' && (!empty($a['salesLogId']) || !empty($a['keyFingerprint']))): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-delivery-pack="<?php p($a['id']); ?>"><?php p($l->t('Delivery pack')); ?></button>
								<?php endif; ?>
								<?php if (($a['kind'] ?? '') === 'partner'): ?>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-partner-session="<?php p($a['id']); ?>" title="<?php p($l->t('Open partner mail, then log hours')); ?>"><?php p($l->t('Mail & log hours')); ?></button>
									<button type="button" class="crm-btn crm-btn--sm crm-btn--ghost" data-crm-log-hours="<?php p($a['id']); ?>"><?php p($l->t('Hours only')); ?></button>
									<?php if (!empty($a['quota'])): ?>
										<span class="crm-badge <?php echo !empty($a['quota']['alert']) ? 'crm-badge--warning' : 'crm-badge--neutral'; ?>" title="<?php p($l->t('Partner quota')); ?>">
											<?php p(number_format(($a['quota']['usedMinutes'] ?? 0) / 60, 2)); ?> / <?php p(number_format(($a['quota']['quotaMinutes'] ?? 0) / 60, 2)); ?> h
										</span>
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

	<section class="crm-card" id="crm-deals" aria-labelledby="crm-deals-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-deals-title" class="crm-card__title"><?php p($l->t('Deals')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Pipeline opportunities for this company.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-deal"><?php p($l->t('Add deal')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (($_['deals'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No deals yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Track a quote or renewal opportunity here.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr><th><?php p($l->t('Title')); ?></th><th><?php p($l->t('Stage')); ?></th><th><?php p($l->t('Value')); ?></th></tr></thead>
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
							?>
								<button type="button" class="crm-btn crm-btn--ghost crm-btn--sm" data-crm-dismiss-followups="<?php p($d['id']); ?>"><?php p($l->t('Dismiss follow-ups')); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="crm-card" id="crm-persons" aria-labelledby="crm-persons-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-persons-title" class="crm-card__title"><?php p($l->t('People')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Contacts at this company.')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-person"><?php p($l->t('Add person')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (($_['persons'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('No contacts yet')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add a name and email so Related messages can find Mail threads.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr><th><?php p($l->t('Name')); ?></th><th><?php p($l->t('Email')); ?></th><th><?php p($l->t('Phone')); ?></th><th><?php p($l->t('Role')); ?></th></tr></thead>
					<tbody>
					<?php foreach ($_['persons'] as $p): ?>
						<tr>
							<td data-label="<?php p($l->t('Name')); ?>"><?php p($p['name']); ?><?php if (!empty($p['isPrimary'])): ?> <span class="crm-badge crm-badge--info"><?php p($l->t('Primary')); ?></span><?php endif; ?></td>
							<td data-label="<?php p($l->t('Email')); ?>"><?php p($p['email'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Phone')); ?>"><?php p($p['phone'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Role')); ?>"><?php p($p['role'] ?? '—'); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<?php include __DIR__ . '/common/crm-emails-section.php'; ?>

	<section class="crm-card" id="crm-activities" aria-labelledby="crm-activities-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-activities-title" class="crm-card__title"><?php p($l->t('Open activities')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Follow-ups you still owe (calls, reminders, to-dos).')); ?></p>
			</div>
			<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" id="crm-add-activity"><?php p($l->t('Add activity')); ?></button>
		</header>
		<div class="crm-card__body">
			<?php if (($_['activities'] ?? []) === []): ?>
				<div class="crm-empty-state" role="status">
					<h3 class="crm-empty-state__title"><?php p($l->t('Nothing open')); ?></h3>
					<p class="crm-empty-state__text"><?php p($l->t('Add a call, follow-up email reminder, or to-do with a due date.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th><?php p($l->t('Title')); ?></th>
						<th><?php p($l->t('Type')); ?></th>
						<th><?php p($l->t('Due')); ?></th>
						<th><?php p($l->t('Origin')); ?></th>
						<th class="crm-table-actions-col"><?php p($l->t('Actions')); ?></th>
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
							<td data-label="<?php p($l->t('Due')); ?>"><?php p($act['dueDate'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Origin')); ?>">
								<?php if (($act['origin'] ?? '') === 'system' || str_starts_with((string)($act['origin'] ?? ''), 'sequence_')): ?>
									<span class="crm-badge crm-badge--info"><?php p($l->t('Auto')); ?></span>
								<?php else: ?>
									<?php p($l->t('User')); ?>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Actions')); ?>" class="actions-cell crm-table-actions-col">
								<div class="crm-table-actions">
									<button type="button" class="crm-btn crm-btn--sm crm-btn--primary" data-crm-complete-activity="<?php p($act['id']); ?>" data-updated-at="<?php p($act['updatedAt'] ?? ''); ?>"><?php p($l->t('Done')); ?></button>
									<?php if (empty($act['todoUri'])): ?>
										<button type="button" class="crm-btn crm-btn--sm crm-btn--secondary" data-crm-push-todo="<?php p($act['id']); ?>"><?php p($l->t('Send to Tasks')); ?></button>
									<?php else: ?>
										<span class="crm-badge crm-badge--success"><?php p($l->t('Sent to Tasks')); ?></span>
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
	<details class="crm-card crm-linked-bundle" id="crm-linked-bundle">
		<summary class="crm-linked-bundle__summary">
			<span class="crm-linked-bundle__title"><?php p($l->t('Linked apps')); ?></span>
			<span class="crm-linked-bundle__hint"><?php p($l->t('Tickets, invoices and projects — open when you need them')); ?></span>
		</summary>
		<div class="crm-linked-bundle__body">
	<?php endif; ?>

	<section class="crm-card<?php if ($linkedBundleCollapsed): ?> crm-card--nested<?php endif; ?>" id="crm-tickets" aria-labelledby="crm-tickets-title" aria-busy="false">
		<header class="crm-card__header"><h2 id="crm-tickets-title" class="crm-card__title"><?php p($l->t('Tickets')); ?></h2></header>
		<div class="crm-card__body">
			<?php if (empty($_['capabilities']['ticketcheck'])): ?>
				<p class="crm-muted"><?php p($l->t('TicketCheck is not available on this instance.')); ?></p>
			<?php elseif (empty($_['tcCustomerId'])): ?>
				<p class="crm-muted"><?php p($l->t('Link a TicketCheck customer on the company form to load tickets.')); ?></p>
			<?php elseif (!empty($_['ticketError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<span class="crm-callout__icon" aria-hidden="true"></span>
					<p class="crm-callout__text"><?php p($l->t('Could not load tickets. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['tickets'] ?? []) === []): ?>
				<p class="crm-muted"><?php p($l->t('No open tickets for this company.')); ?></p>
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
				<p class="crm-card__lead"><?php p($l->t('Read-only from InvoicingCheck when linked.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (empty($_['capabilities']['invoicecheck'])): ?>
				<p class="crm-muted"><?php p($l->t('InvoicingCheck is not available on this instance.')); ?></p>
			<?php elseif (empty($_['pcCustomerId'])): ?>
				<p class="crm-muted"><?php p($l->t('Link a ProjectCheck customer on the company form to load invoices.')); ?></p>
			<?php elseif (!empty($_['invoiceError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<span class="crm-callout__icon" aria-hidden="true"></span>
					<p class="crm-callout__text"><?php p($l->t('Could not load invoices. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['invoices'] ?? []) === []): ?>
				<p class="crm-muted"><?php p($l->t('No invoices found for the linked customer.')); ?></p>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover">
					<thead><tr>
						<th><?php p($l->t('Number')); ?></th>
						<th><?php p($l->t('Status')); ?></th>
						<th><?php p($l->t('Issue')); ?></th>
						<th><?php p($l->t('Gross')); ?></th>
						<th><?php p($l->t('Paid')); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($_['invoices'] as $inv): ?>
						<tr>
							<td data-label="<?php p($l->t('Number')); ?>"><?php p($inv['invoiceRef'] ?? $l->t('Unlabelled invoice')); ?></td>
							<td data-label="<?php p($l->t('Status')); ?>">
								<span class="crm-badge <?php echo !empty($inv['overdue']) ? 'crm-badge--danger' : 'crm-badge--neutral'; ?>"><?php p($inv['status'] ?? ''); ?></span>
							</td>
							<td data-label="<?php p($l->t('Issue')); ?>"><?php p($inv['issueDate'] ?? '—'); ?></td>
							<td data-label="<?php p($l->t('Gross')); ?>"><?php p(number_format(((int)($inv['grossTotalCents'] ?? 0)) / 100, 2)); ?> €</td>
							<td data-label="<?php p($l->t('Paid')); ?>"><?php p(number_format(((int)($inv['paidTotalCents'] ?? 0)) / 100, 2)); ?> €</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="crm-card<?php if ($linkedBundleCollapsed): ?> crm-card--nested<?php endif; ?>" id="crm-projects" aria-labelledby="crm-projects-title" aria-busy="false">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-projects-title" class="crm-card__title"><?php p($l->t('Projects')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Read-only from ProjectCheck when linked.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (empty($_['capabilities']['projectcheck'])): ?>
				<p class="crm-muted"><?php p($l->t('ProjectCheck is not available on this instance.')); ?></p>
			<?php elseif (empty($_['pcCustomerId'])): ?>
				<p class="crm-muted"><?php p($l->t('Link a ProjectCheck customer on the company form to load projects.')); ?></p>
			<?php elseif (!empty($_['projectError'])): ?>
				<div class="crm-callout crm-callout--danger" role="alert">
					<span class="crm-callout__icon" aria-hidden="true"></span>
					<p class="crm-callout__text"><?php p($l->t('Could not load projects. The rest of this page is still available.')); ?></p>
				</div>
			<?php elseif (($_['projects'] ?? []) === []): ?>
				<p class="crm-muted"><?php p($l->t('No projects found for the linked customer.')); ?></p>
			<?php else: ?>
				<ul class="crm-simple-list">
				<?php foreach ($_['projects'] as $proj): ?>
					<li>
						<strong><?php p($proj['name'] ?? $l->t('Unlabelled project')); ?></strong>
						· <span class="crm-badge"><?php p($proj['status'] ?? 'active'); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</section>

	<?php if ($linkedBundleCollapsed): ?>
		</div>
	</details>
	<?php endif; ?>

	<section class="crm-card" id="crm-timeline" aria-labelledby="crm-timeline-title">
		<header class="crm-card__header"><h2 id="crm-timeline-title" class="crm-card__title"><?php p($l->t('Timeline')); ?></h2></header>
		<div class="crm-card__body">
			<?php if (($_['timeline'] ?? []) === []): ?>
				<p class="crm-muted"><?php p($l->t('No events yet.')); ?></p>
			<?php else: ?>
			<ol class="crm-timeline">
			<?php foreach ($_['timeline'] as $ev): ?>
				<li>
					<time datetime="<?php p($ev['createdAt'] ?? ''); ?>"><?php p($ev['createdAt'] ?? ''); ?></time>
					· <strong><?php p($ev['eventType']); ?></strong>
					· <?php p($ev['actor']); ?>
				</li>
			<?php endforeach; ?>
			</ol>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
