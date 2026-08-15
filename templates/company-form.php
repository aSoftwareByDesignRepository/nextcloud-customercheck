<?php
$mode = $_['mode'] ?? 'create';
$company = $_['company'] ?? null;
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$labels = $_['labels'] ?? null;
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack">
	<section class="crm-card" aria-labelledby="crm-company-form-title">
		<header class="crm-card__header">
			<h2 id="crm-company-form-title" class="crm-card__title"><?php p($mode === 'edit' ? $l->t('Edit company') : $l->t('New company')); ?></h2>
			<p class="crm-card__lead"><?php p($mode === 'edit' ? $l->t('Change the name if you need to. Owner, status, and links stay under Advanced.') : $l->t('Just enter a name — you become the account owner; everything else is optional.')); ?></p>
		</header>
		<div class="crm-card__body">
			<form id="crm-company-form" class="crm-form" data-mode="<?php p($mode); ?>" data-id="<?php p($company ? $company->getId() : ''); ?>" data-updated-at="<?php p($company && $company->getUpdatedAt() ? $company->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>" novalidate>
				<div class="form-group crm-company-essentials" role="group" aria-labelledby="crm-company-name-label">
					<label class="form-label" id="crm-company-name-label" for="crm-company-name"><?php p($l->t('Name')); ?></label>
					<input class="crm-input form-input crm-input--lg" id="crm-company-name" name="name" required maxlength="200" value="<?php p($company ? $company->getName() : ''); ?>" autocomplete="organization" autofocus aria-describedby="crm-company-name-help" />
					<p class="form-help" id="crm-company-name-help"><?php p($mode === 'edit' ? $l->t('That is enough — press Save. Optional details stay under Advanced.') : $l->t('That is enough — press Save. Optional details are under Advanced.')); ?></p>
				</div>
				<?php /* Bachus: never force-open Advanced on edit — parity with agreement essentials-first. */ ?>
				<details class="crm-advanced" id="crm-company-advanced">
					<summary class="crm-advanced__summary"><?php p($l->t('Advanced')); ?></summary>
					<div class="crm-advanced__body">
						<div class="form-group">
							<label class="form-label" for="crm-company-status"><?php p($l->t('Status')); ?></label>
							<select class="crm-input form-select" id="crm-company-status" name="status">
								<?php foreach (['lead','active','paused','churned'] as $st): ?>
									<option value="<?php p($st); ?>" <?php if ($company && $company->getStatus() === $st): ?>selected<?php elseif (!$company && $st === 'lead'): ?>selected<?php endif; ?>><?php
										p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->companyStatus($st) : $st);
									?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-company-owner"><?php p($l->t('Account owner')); ?></label>
							<?php
							$ownerOptions = is_array($_['workspaceMemberOptions'] ?? null) ? $_['workspaceMemberOptions'] : [];
							$currentOwner = $company ? trim((string)($company->getAccountOwnerUserId() ?? '')) : '';
							$defaultOwner = $currentOwner !== '' ? $currentOwner : trim((string)($_['currentUserId'] ?? ''));
							?>
							<select class="crm-input form-select" id="crm-company-owner" name="accountOwnerUserId" aria-describedby="crm-company-owner-help">
								<option value=""><?php p($l->t('Unassigned')); ?></option>
								<?php foreach ($ownerOptions as $opt):
									$oid = (string)($opt['id'] ?? '');
									if ($oid === '') {
										continue;
									}
									?>
									<option value="<?php p($oid); ?>" <?php if ($defaultOwner === $oid): ?>selected<?php endif; ?>><?php p((string)($opt['label'] ?? $oid)); ?></option>
								<?php endforeach; ?>
								<?php if ($defaultOwner !== '' && !in_array($defaultOwner, array_column($ownerOptions, 'id'), true)): ?>
									<option value="<?php p($defaultOwner); ?>" selected><?php p($defaultOwner); ?></option>
								<?php endif; ?>
							</select>
							<p id="crm-company-owner-help" class="form-help"><?php p($l->t('Who is responsible for this customer? New companies default to you.')); ?></p>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-company-lang"><?php p($l->t('Language')); ?></label>
							<select class="crm-input form-select" id="crm-company-lang" name="lang">
								<option value="de" <?php if (!$company || $company->getLang() === 'de'): ?>selected<?php endif; ?>>Deutsch</option>
								<option value="en" <?php if ($company && $company->getLang() === 'en'): ?>selected<?php endif; ?>>English</option>
							</select>
						</div>
						<fieldset class="crm-company-address" id="crm-company-address">
							<legend class="crm-company-address__legend"><?php p($l->t('Postal address')); ?></legend>
							<p class="form-help" id="crm-company-address-help"><?php p($l->t('Street, postcode, city and country for invoices and letters.')); ?></p>
							<div class="form-group">
								<label class="form-label" for="crm-company-street"><?php p($l->t('Street and number')); ?></label>
								<input class="crm-input form-input" id="crm-company-street" name="addrStreet" maxlength="255" value="<?php p($company ? (string)$company->getAddrStreet() : ''); ?>" autocomplete="street-address" aria-describedby="crm-company-address-help" />
							</div>
							<div class="crm-form-row-2">
								<div class="form-group">
									<label class="form-label" for="crm-company-zip"><?php p($l->t('Postcode')); ?></label>
									<input class="crm-input form-input" id="crm-company-zip" name="addrZip" maxlength="16" value="<?php p($company ? (string)$company->getAddrZip() : ''); ?>" autocomplete="postal-code" inputmode="numeric" />
								</div>
								<div class="form-group">
									<label class="form-label" for="crm-company-city"><?php p($l->t('City')); ?></label>
									<input class="crm-input form-input" id="crm-company-city" name="addrCity" maxlength="128" value="<?php p($company ? (string)$company->getAddrCity() : ''); ?>" autocomplete="address-level2" />
								</div>
							</div>
							<div class="form-group">
								<label class="form-label" for="crm-company-country"><?php p($l->t('Country')); ?></label>
								<?php
								$countryCode = strtoupper(trim((string)($company ? $company->getCountry() : 'DE')));
								if ($countryCode === '') {
									$countryCode = 'DE';
								}
								$countryOptions = [
									'DE' => $l->t('Germany'),
									'AT' => $l->t('Austria'),
									'CH' => $l->t('Switzerland'),
									'LI' => $l->t('Liechtenstein'),
									'LU' => $l->t('Luxembourg'),
									'NL' => $l->t('Netherlands'),
									'BE' => $l->t('Belgium'),
									'FR' => $l->t('France'),
									'IT' => $l->t('Italy'),
									'ES' => $l->t('Spain'),
									'PL' => $l->t('Poland'),
									'CZ' => $l->t('Czechia'),
									'DK' => $l->t('Denmark'),
									'SE' => $l->t('Sweden'),
									'NO' => $l->t('Norway'),
									'GB' => $l->t('United Kingdom'),
									'IE' => $l->t('Ireland'),
									'US' => $l->t('United States'),
								];
								?>
								<select class="crm-input form-select" id="crm-company-country" name="country" autocomplete="country">
									<?php foreach ($countryOptions as $code => $label): ?>
										<option value="<?php p($code); ?>" <?php if ($countryCode === $code): ?>selected<?php endif; ?>><?php p($label); ?> (<?php p($code); ?>)</option>
									<?php endforeach; ?>
									<?php if (!isset($countryOptions[$countryCode])): ?>
										<option value="<?php p($countryCode); ?>" selected><?php p($countryCode); ?></option>
									<?php endif; ?>
								</select>
							</div>
						</fieldset>
						<div class="form-group">
							<label class="form-label" for="crm-company-notes"><?php p($l->t('Notes')); ?></label>
							<textarea class="crm-input form-input" id="crm-company-notes" name="notes" rows="3"><?php p($company ? (string)$company->getNotes() : ''); ?></textarea>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-company-slug"><?php p($l->t('Short address')); ?></label>
							<input class="crm-input form-input" id="crm-company-slug" name="slug" pattern="[a-z0-9-]{3,64}" maxlength="64" value="<?php p($company ? $company->getSlug() : ''); ?>" <?php if ($mode === 'edit'): ?>readonly<?php endif; ?> aria-describedby="crm-company-slug-help" />
							<p id="crm-company-slug-help" class="form-help"><?php p($l->t('Filled automatically from the name. Lowercase letters, numbers and hyphens.')); ?></p>
						</div>
						<div class="form-group">
							<?php if (!empty($_['pcCustomersAvailable'])): ?>
								<span class="form-label" id="crm-company-pc-label"><?php p($l->t('ProjectCheck customer')); ?></span>
								<label class="visually-hidden" for="crm-company-pc-filter"><?php p($l->t('Search ProjectCheck customers')); ?></label>
								<input type="search" class="crm-input form-input" id="crm-company-pc-filter" autocomplete="off" placeholder="<?php p($l->t('Search by name…')); ?>" aria-controls="crm-company-pc" aria-labelledby="crm-company-pc-label" aria-describedby="crm-company-pc-help" />
								<select class="crm-input form-select" id="crm-company-pc" name="pcCustomerId" data-crm-sibling-select aria-labelledby="crm-company-pc-label" aria-describedby="crm-company-pc-help">
									<option value=""><?php p($l->t('Not linked')); ?></option>
									<?php foreach (($_['pcCustomerOptions'] ?? []) as $opt): ?>
										<option value="<?php p((string)$opt['id']); ?>" <?php if ($company && (int)$company->getPcCustomerId() === (int)$opt['id']): ?>selected<?php endif; ?>><?php p($opt['label']); ?></option>
									<?php endforeach; ?>
								</select>
								<p id="crm-company-pc-help" class="form-help"><?php p($l->t('Pick the ProjectCheck customer by name to load invoices and projects. Choose “Not linked” to unlink.')); ?></p>
								<?php if ($mode === 'edit' && !empty($_['pcWriteAvailable'])): ?>
									<button type="button" class="crm-btn crm-btn--secondary crm-mt-2" id="crm-create-pc-customer">
										<?php p($l->t('Create in ProjectCheck')); ?>
									</button>
								<?php endif; ?>
							<?php else: ?>
								<span class="form-label"><?php p($l->t('ProjectCheck customer')); ?></span>
								<p id="crm-company-pc-help" class="form-help crm-muted"><?php p($l->t('ProjectCheck is not available on this instance, so invoice/project linking is disabled.')); ?></p>
								<input type="hidden" id="crm-company-pc" name="pcCustomerId" value="" />
							<?php endif; ?>
						</div>
						<div class="form-group">
							<?php if (!empty($_['tcCustomersAvailable'])): ?>
								<span class="form-label" id="crm-company-tc-label"><?php p($l->t('TicketCheck customer')); ?></span>
								<label class="visually-hidden" for="crm-company-tc-filter"><?php p($l->t('Search TicketCheck customers')); ?></label>
								<input type="search" class="crm-input form-input" id="crm-company-tc-filter" autocomplete="off" placeholder="<?php p($l->t('Search by name…')); ?>" aria-controls="crm-company-tc" aria-labelledby="crm-company-tc-label" aria-describedby="crm-company-tc-help" />
								<select class="crm-input form-select" id="crm-company-tc" name="tcCustomerId" data-crm-sibling-select aria-labelledby="crm-company-tc-label" aria-describedby="crm-company-tc-help">
									<option value=""><?php p($l->t('Not linked')); ?></option>
									<?php foreach (($_['tcCustomerOptions'] ?? []) as $opt): ?>
										<option value="<?php p((string)$opt['id']); ?>" <?php if ($company && (int)$company->getTcCustomerId() === (int)$opt['id']): ?>selected<?php endif; ?>><?php p($opt['label']); ?></option>
									<?php endforeach; ?>
								</select>
								<p id="crm-company-tc-help" class="form-help"><?php p($l->t('Pick the TicketCheck customer by name for open tickets on the 360 view. Choose “Not linked” to unlink.')); ?></p>
								<?php if ($mode === 'edit' && !empty($_['tcWriteAvailable'])): ?>
									<button type="button" class="crm-btn crm-btn--secondary crm-mt-2" id="crm-create-tc-customer">
										<?php p($l->t('Create in TicketCheck')); ?>
									</button>
								<?php endif; ?>
							<?php else: ?>
								<span class="form-label"><?php p($l->t('TicketCheck customer')); ?></span>
								<p id="crm-company-tc-help" class="form-help crm-muted"><?php p($l->t('TicketCheck is not available on this instance, so ticket linking is disabled.')); ?></p>
								<input type="hidden" id="crm-company-tc" name="tcCustomerId" value="" />
							<?php endif; ?>
						</div>
					</div>
				</details>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg"><?php p($l->t('Save')); ?></button>
					<a class="crm-btn crm-btn--secondary" href="<?php p($_['urls']['companies'] ?? '#'); ?>"><?php p($l->t('Cancel')); ?></a>
				</div>
				<div id="crm-rename-push-alert" class="crm-callout crm-callout--warning" role="alert" tabindex="-1" hidden></div>
				<div id="crm-company-form-error" class="crm-callout crm-callout--danger" role="alert" hidden></div>
			</form>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
