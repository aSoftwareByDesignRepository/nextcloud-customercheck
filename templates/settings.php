<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = $_['settings'] ?? [];
$canManage = !empty($_['canManage']);
$availableAppAdmins = $_['availableAppAdmins'] ?? [];
$availableGroups = $_['availableGroups'] ?? [];
$selectedAllowedUsers = $_['selectedAllowedUsers'] ?? [];
$selectedAppAdmins = is_array($settings['app_admin_user_ids'] ?? null) ? $settings['app_admin_user_ids'] : [];
$selectedGroups = is_array($settings['access_allowed_group_ids'] ?? null) ? $settings['access_allowed_group_ids'] : [];
$restrictionOn = !empty($settings['access_restriction_enabled']);
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-settings-root"
	data-can-manage="<?php p($canManage ? '1' : '0'); ?>"
	data-pipeline-view="<?php p($settings['pipelineView'] ?? 'table'); ?>">

	<?php if (!empty($settings['failClosedEmptyAllowlists'])): ?>
		<div class="crm-callout crm-callout--danger" role="alert">
			<p><?php p($l->t('Access restriction is on and no users or groups are allowlisted. Only app administrators can use CustomerCheck. Add colleagues below.')); ?></p>
		</div>
	<?php endif; ?>
	<?php if (!$restrictionOn): ?>
		<div class="crm-callout crm-callout--warning" role="status">
			<p><?php p($l->t('Access restriction is off — every logged-in user can see sales data. Not recommended.')); ?></p>
		</div>
	<?php endif; ?>

	<section class="crm-card" id="section-access" aria-labelledby="section-access-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-access-heading" class="crm-card__title"><?php p($l->t('Access control')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('App administrators always have access. When restriction is on, only allowlisted users and groups may use the app.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (!$canManage): ?>
				<p><?php p($l->t('You can use CustomerCheck. Only app administrators can change access policy and tokens.')); ?></p>
				<p class="crm-muted"><?php p($l->t('Your pipeline view preference can still be changed below.')); ?></p>
			<?php else: ?>
			<form id="crm-settings-access-form" class="crm-form" novalidate>
				<div class="form-group">
					<label for="crm-app-admins-search" class="form-label"><?php p($l->t('CustomerCheck app administrators')); ?></label>
					<input type="search" id="crm-app-admins-search" class="form-input crm-input" autocomplete="off" spellcheck="false"
						placeholder="<?php p($l->t('Search administrators…')); ?>"
						aria-describedby="crm-app-admins-count crm-app-admins-help">
					<p id="crm-app-admins-count" class="form-help form-help--note" aria-live="polite">
						<?php
						$count = count($selectedAppAdmins);
						p($count > 0
							? $l->t('%d app admin(s) selected', [$count])
							: $l->t('No app admins selected (all Nextcloud admins are allowed).'));
						?>
					</p>
					<div id="crm-app-admins-list" class="access-groups-list" role="group" aria-label="<?php p($l->t('App administrator selection')); ?>">
						<?php foreach ($availableAppAdmins as $adminOption):
							$adminId = (string)($adminOption['id'] ?? '');
							if ($adminId === '') {
								continue;
							}
							$adminDisplayName = (string)($adminOption['displayName'] ?? $adminId);
							$isSelected = in_array($adminId, $selectedAppAdmins, true);
							?>
							<label class="access-groups-item" data-search="<?php p(strtolower($adminDisplayName . ' ' . $adminId)); ?>">
								<input type="checkbox" name="app_admin_user_ids[]" value="<?php p($adminId); ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
								<span class="access-groups-item__label"><?php p($adminDisplayName); ?></span>
								<span class="access-groups-item__meta"><?php p($adminId); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p id="crm-app-admins-help" class="form-help"><?php p($l->t('Only Nextcloud admins are listed. Empty list means every Nextcloud admin is a CustomerCheck app administrator.')); ?></p>
				</div>

				<div class="form-group">
					<label class="crm-switch">
						<input type="checkbox" id="crm-access-restriction" name="access_restriction_enabled" value="1" <?php echo $restrictionOn ? 'checked' : ''; ?>>
						<span><?php p($l->t('Restrict access')); ?></span>
					</label>
					<p class="form-help"><?php p($l->t('When on (recommended), only allowlisted users and groups plus app administrators may open CustomerCheck.')); ?></p>
					<label class="crm-switch crm-switch--danger" id="crm-open-access-confirm-wrap" <?php if ($restrictionOn): ?>hidden<?php endif; ?>>
						<input type="checkbox" id="crm-confirm-open-access" name="confirmOpenAccess" value="1">
						<span><?php p($l->t('Allow all logged-in users — not recommended')); ?></span>
					</label>
				</div>

				<div class="form-group">
					<label for="crm-allowed-users-search" class="form-label"><?php p($l->t('Allowed users')); ?></label>
					<input type="search" id="crm-allowed-users-search" class="form-input crm-input" autocomplete="off" spellcheck="false"
						placeholder="<?php p($l->t('Search users to add…')); ?>"
						aria-controls="crm-allowed-users-suggest" aria-autocomplete="list">
					<div id="crm-allowed-users-suggest" class="crm-suggest" role="listbox" hidden></div>
					<ul id="crm-allowed-users-chips" class="crm-chip-list" role="list" aria-label="<?php p($l->t('Selected allowed users')); ?>">
						<?php foreach ($selectedAllowedUsers as $userOption): ?>
							<li class="crm-chip" data-uid="<?php p($userOption['id']); ?>">
								<span><?php p($userOption['displayName']); ?></span>
								<button type="button" class="crm-chip__remove" data-remove-uid="<?php p($userOption['id']); ?>" aria-label="<?php p($l->t('Remove %s', [$userOption['displayName']])); ?>">×</button>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="form-help"><?php p($l->t('Sales colleagues who are not in a dedicated group yet can be allowlisted by user id.')); ?></p>
				</div>

				<div class="form-group">
					<label for="crm-allowed-groups-search" class="form-label"><?php p($l->t('Allowed groups')); ?></label>
					<input type="search" id="crm-allowed-groups-search" class="form-input crm-input" autocomplete="off" spellcheck="false"
						placeholder="<?php p($l->t('Search groups…')); ?>"
						aria-describedby="crm-allowed-groups-count">
					<p id="crm-allowed-groups-count" class="form-help form-help--note" aria-live="polite">
						<?php
						$gCount = count($selectedGroups);
						p($gCount > 0
							? $l->t('%d group(s) selected', [$gCount])
							: $l->t('No groups selected.'));
						?>
					</p>
					<div id="crm-allowed-groups-list" class="access-groups-list" role="group" aria-label="<?php p($l->t('Group selection')); ?>">
						<?php foreach ($availableGroups as $groupOption):
							$groupId = (string)($groupOption['id'] ?? '');
							if ($groupId === '') {
								continue;
							}
							$groupDisplayName = (string)($groupOption['displayName'] ?? $groupId);
							$isSelected = in_array($groupId, $selectedGroups, true);
							?>
							<label class="access-groups-item" data-search="<?php p(strtolower($groupDisplayName . ' ' . $groupId)); ?>">
								<input type="checkbox" name="access_allowed_group_ids[]" value="<?php p($groupId); ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
								<span class="access-groups-item__label"><?php p($groupDisplayName); ?></span>
								<span class="access-groups-item__meta"><?php p($groupId); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="form-help"><?php p($l->t('Administrators (app admins) are always allowed. Changes apply immediately after save.')); ?></p>
				</div>

				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Save access control')); ?></button>
				</div>
			</form>
			<?php endif; ?>
		</div>
	</section>

	<section class="crm-card" id="section-preferences" aria-labelledby="section-preferences-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-preferences-heading" class="crm-card__title"><?php p($l->t('Your preferences')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Personal choices that do not affect other users.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<form id="crm-settings-me-form" class="crm-form">
				<div class="form-group">
					<label for="crm-pipeline-view" class="form-label"><?php p($l->t('Default pipeline view')); ?></label>
					<select id="crm-pipeline-view" name="pipelineView" class="form-select crm-input">
						<option value="table" <?php if (($settings['pipelineView'] ?? 'table') === 'table'): ?>selected<?php endif; ?>><?php p($l->t('Table')); ?></option>
						<option value="board" <?php if (($settings['pipelineView'] ?? '') === 'board'): ?>selected<?php endif; ?>><?php p($l->t('Board')); ?></option>
					</select>
				</div>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Save preference')); ?></button>
				</div>
			</form>
		</div>
	</section>

	<?php if ($canManage): ?>
	<section class="crm-card" id="section-jobs" aria-labelledby="section-jobs-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-jobs-heading" class="crm-card__title"><?php p($l->t('Reminders & digest')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Server-timezone schedules for renewal reminders and the weekly digest.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<form id="crm-settings-jobs-form" class="crm-form">
				<label class="crm-switch">
					<input type="checkbox" name="cadence_enabled" value="1" <?php echo !empty($settings['cadence_enabled']) ? 'checked' : ''; ?>>
					<span><?php p($l->t('Renewal reminder cadence (60/30/7/0/+7)')); ?></span>
				</label>
				<div class="form-group">
					<label for="crm-reminder-hour" class="form-label"><?php p($l->t('Reminder hour')); ?></label>
					<input type="number" id="crm-reminder-hour" name="reminder_hour" class="form-input crm-input" min="0" max="23" value="<?php p((int)($settings['reminder_hour'] ?? 5)); ?>">
				</div>
				<label class="crm-switch">
					<input type="checkbox" name="digest_enabled" value="1" <?php echo !empty($settings['digest_enabled']) ? 'checked' : ''; ?>>
					<span><?php p($l->t('Weekly digest notification')); ?></span>
				</label>
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

	<section class="crm-card" id="section-tokens" aria-labelledby="section-tokens-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-tokens-heading" class="crm-card__title"><?php p($l->t('Public feeds & intake')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Anyone with these URLs can read renewal titles or post leads. Rotate if a token leaks.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<?php if (!empty($settings['tokensVisible'])): ?>
				<div class="form-group">
					<label class="form-label" for="crm-ics-url"><?php p($l->t('ICS renewal feed')); ?></label>
					<div class="crm-token-row">
						<input id="crm-ics-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['icsFeedUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-ics-url"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="ics"><?php p($l->t('Rotate')); ?></button>
					</div>
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-lead-url"><?php p($l->t('Lead webhook')); ?></label>
					<div class="crm-token-row">
						<input id="crm-lead-url" class="form-input crm-input" type="text" readonly value="<?php p($settings['leadWebhookUrl'] ?? ''); ?>">
						<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-lead-url"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger" data-rotate-token="lead"><?php p($l->t('Rotate')); ?></button>
					</div>
					<p class="form-help"><?php p($l->t('Website “Angebot anfragen” should POST JSON to this URL. Rate limit: 30 requests per IP per 10 minutes.')); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<section class="crm-card" id="section-integrations" aria-labelledby="section-integrations-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-integrations-heading" class="crm-card__title"><?php p($l->t('Integrations')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Sibling apps are read-only. Missing apps hide their panel without breaking pages.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<ul class="crm-simple-list">
			<?php foreach (($_['capabilities'] ?? []) as $app => $ok): ?>
				<li>
					<strong><?php p($app); ?></strong>
					· <span class="crm-badge crm-badge--<?php p($ok ? 'success' : 'neutral'); ?>"><?php p($ok ? $l->t('available') : $l->t('not available')); ?></span>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
