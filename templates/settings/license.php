<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$license = is_array($_['license'] ?? null) ? $_['license'] : null;
$licenseState = is_array($license['state'] ?? null) ? $license['state'] : null;
$licenseSeats = is_array($_['licenseSeats'] ?? null) ? $_['licenseSeats'] : null;
$seatRows = is_array($licenseSeats['data'] ?? null) ? $licenseSeats['data'] : [];
$seatLimit = (int)($license['seats']['limit'] ?? 0);
$seatAssigned = (int)($license['seats']['assigned'] ?? 0);
?>
<section class="crm-card" id="section-license" aria-labelledby="section-license-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-license-heading" class="crm-card__title"><?php p($l->t('Mobile app license')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('The web app is free and never locked. A CUC2 key only unlocks the official CustomerCheck mobile app for the colleagues you pick below.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if ($licenseState === null): ?>
				<div class="crm-callout crm-callout--neutral" id="crm-license-empty" role="status">
					<p><?php p($l->t('No license key yet. Everything on the web keeps working — you only need a key for the mobile app.')); ?></p>
				</div>
			<?php else: ?>
				<?php if (empty($licenseState['valid'])): ?>
					<div class="crm-callout crm-callout--danger" role="alert">
						<p><?php p($l->t('The mobile license expired on %s. The mobile app is blocked until you paste a renewed key.', [(string)$licenseState['validUntil']])); ?></p>
					</div>
				<?php elseif (!empty($licenseState['expiresSoon'])): ?>
					<div class="crm-callout crm-callout--warning" role="status">
						<p><?php p($l->t('The mobile license expires on %s (%d day(s) left). Renew in time so mobile colleagues stay connected.', [(string)$licenseState['validUntil'], (int)$licenseState['daysRemaining']])); ?></p>
					</div>
				<?php endif; ?>
				<dl class="crm-dl" id="crm-license-facts">
					<div class="crm-dl__row">
						<dt><?php p($l->t('Status')); ?></dt>
						<dd>
							<span class="crm-badge crm-badge--<?php p(!empty($licenseState['valid']) ? 'success' : 'danger'); ?>">
								<?php p(!empty($licenseState['valid']) ? $l->t('valid') : $l->t('expired')); ?>
							</span>
						</dd>
					</div>
					<div class="crm-dl__row">
						<dt><?php p($l->t('Customer')); ?></dt>
						<dd><?php p((string)$licenseState['customerId']); ?></dd>
					</div>
					<div class="crm-dl__row">
						<dt><?php p($l->t('Valid until')); ?></dt>
						<dd><?php p((string)$licenseState['validUntil']); ?></dd>
					</div>
					<div class="crm-dl__row">
						<dt><?php p($l->t('Mobile seats')); ?></dt>
						<dd><?php p($l->t('%1$d of %2$d assigned', [$seatAssigned, $seatLimit])); ?></dd>
					</div>
				</dl>
			<?php endif; ?>

			<form id="crm-license-form" class="crm-form" novalidate>
				<div class="form-group">
					<label for="crm-license-key" class="form-label"><?php p($l->t('Paste license key')); ?></label>
					<textarea id="crm-license-key" name="key" class="form-input crm-input crm-license-key" rows="3"
						autocomplete="off" spellcheck="false"
						placeholder="CUC2.…"
						aria-describedby="crm-license-key-help"></textarea>
					<p id="crm-license-key-help" class="form-help"><?php p($l->t('Copy the whole key from the delivery email, including the CUC2 prefix. Whitespace from the email client is fine.')); ?></p>
					<p id="crm-license-error" class="form-error" role="alert" hidden></p>
				</div>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Validate & save')); ?></button>
					<?php if ($licenseState !== null): ?>
						<button type="button" class="crm-btn crm-btn--danger" id="crm-license-remove"><?php p($l->t('Remove license')); ?></button>
					<?php endif; ?>
				</div>
			</form>

			<div class="form-group" id="crm-license-seats-block" <?php if ($licenseState === null): ?>hidden<?php endif; ?>>
				<h3 class="crm-card__subtitle"><?php p($l->t('Mobile seats')); ?></h3>
				<p class="form-help"><?php p($l->t('Only listed colleagues can log in with the mobile app. If the license shrinks, the newest seats are paused first.')); ?></p>
				<div class="crm-token-row">
					<input type="text" id="crm-license-seat-user" class="form-input crm-input"
						autocomplete="off" spellcheck="false"
						placeholder="<?php p($l->t('Nextcloud user id, e.g. anna')); ?>"
						aria-label="<?php p($l->t('Nextcloud user id for the new mobile seat')); ?>">
					<button type="button" class="crm-btn crm-btn--secondary" id="crm-license-seat-add"><?php p($l->t('Assign seat')); ?></button>
				</div>
				<p id="crm-license-seat-error" class="form-error" role="alert" hidden></p>
				<ul class="crm-simple-list" id="crm-license-seat-list" aria-label="<?php p($l->t('Assigned mobile seats')); ?>">
					<?php foreach ($seatRows as $seatRow): ?>
						<li data-seat-uid="<?php p((string)$seatRow['uid']); ?>">
							<strong><?php p((string)$seatRow['displayName']); ?></strong>
							<span class="crm-muted"><?php p((string)$seatRow['uid']); ?></span>
							<?php if (empty($seatRow['withinLimit'])): ?>
								· <span class="crm-badge crm-badge--warning"><?php p($l->t('over limit — paused')); ?></span>
							<?php endif; ?>
							<button type="button" class="crm-btn crm-btn--ghost crm-btn--small" data-remove-seat="<?php p((string)$seatRow['uid']); ?>"
								aria-label="<?php p($l->t('Remove mobile seat of %s', [(string)$seatRow['displayName']])); ?>">
								<?php p($l->t('Remove')); ?>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</section>
