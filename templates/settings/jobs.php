<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
$serverTz = (string)(($_['clientHints']['timezone'] ?? null) ?: (date_default_timezone_get() ?: 'UTC'));
?>
<section class="crm-card" id="section-jobs" aria-labelledby="section-jobs-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-jobs-heading" class="crm-card__title"><?php p($l->t('Reminders & digest')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Server-timezone schedules for renewal reminders and the weekly digest.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<form id="crm-settings-jobs-form" class="crm-form crm-form--stack">
				<label class="crm-switch">
					<input type="checkbox" name="cadence_enabled" value="1" <?php echo !empty($settings['cadence_enabled']) ? 'checked' : ''; ?>>
					<span><?php p($l->t('Renewal reminder cadence (60/30/7/0/+7)')); ?></span>
				</label>
				<div class="form-group">
					<label for="crm-reminder-hour" class="form-label"><?php p($l->t('Reminder hour')); ?></label>
					<input type="number" id="crm-reminder-hour" name="reminder_hour" class="form-input crm-input" min="0" max="23" value="<?php p((int)($settings['reminder_hour'] ?? 5)); ?>">
					<p class="form-help"><?php p($l->t('Hour on the server clock (%s). Callbacks and due activities use the same hour.', [$serverTz])); ?></p>
				</div>
				<label class="crm-switch">
					<input type="checkbox" name="digest_enabled" value="1" <?php echo !empty($settings['digest_enabled']) ? 'checked' : ''; ?>>
					<span><?php p($l->t('Weekly digest notification')); ?></span>
				</label>
				<p class="form-help"><?php p($l->t('App-wide switch. Each team can still opt out under Team & mailbox → Enable weekly digest.')); ?></p>
				<div class="form-group">
					<label for="crm-digest-weekday" class="form-label"><?php p($l->t('Digest weekday')); ?></label>
					<select id="crm-digest-weekday" name="digest_weekday" class="form-select crm-input">
						<?php
						$weekdays = [
							1 => $l->t('Monday'), 2 => $l->t('Tuesday'), 3 => $l->t('Wednesday'),
							4 => $l->t('Thursday'), 5 => $l->t('Friday'), 6 => $l->t('Saturday'), 7 => $l->t('Sunday'),
						];
						$currentDow = (int)($settings['digest_weekday'] ?? 1);
						foreach ($weekdays as $num => $label): ?>
							<option value="<?php p($num); ?>" <?php if ($currentDow === $num): ?>selected<?php endif; ?>><?php p($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label for="crm-digest-hour" class="form-label"><?php p($l->t('Digest hour')); ?></label>
					<input type="number" id="crm-digest-hour" name="digest_hour" class="form-input crm-input" min="0" max="23" value="<?php p((int)($settings['digest_hour'] ?? 7)); ?>">
					<p class="form-help"><?php p($l->t('Hour on the server clock (%s).', [$serverTz])); ?></p>
				</div>
				<div class="form-group">
					<label for="crm-stale-days" class="form-label"><?php p($l->t('Stale deal days')); ?></label>
					<input type="number" id="crm-stale-days" name="stale_deal_days" class="form-input crm-input" min="1" max="365" value="<?php p((int)($settings['stale_deal_days'] ?? 14)); ?>">
				</div>
				<label class="crm-switch">
					<input type="checkbox" name="mail_related_enabled" value="1" <?php echo !empty($settings['mail_related_enabled']) ? 'checked' : ''; ?>>
					<span><?php p($l->t('Show Mail helpers on “Who wrote what”')); ?></span>
				</label>
				<p class="form-help"><?php p($l->t('Does not sync your inbox into CRM. When on, company pages can offer “Open in Mail” for each contact. Your CRM email log always works.')); ?></p>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Save jobs')); ?></button>
				</div>
			</form>
		</div>
	</section>
