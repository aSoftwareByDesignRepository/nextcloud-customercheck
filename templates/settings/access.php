<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$settings = is_array($_['settings'] ?? null) ? $_['settings'] : [];
$canManage = !empty($_['canManage']);
$availableAppAdmins = is_array($_['availableAppAdmins'] ?? null) ? $_['availableAppAdmins'] : [];
$availableGroups = is_array($_['availableGroups'] ?? null) ? $_['availableGroups'] : [];
$selectedAllowedUsers = is_array($_['selectedAllowedUsers'] ?? null) ? $_['selectedAllowedUsers'] : [];
$selectedAppAdmins = is_array($settings['app_admin_user_ids'] ?? null) ? $settings['app_admin_user_ids'] : [];
$selectedGroups = is_array($settings['access_allowed_group_ids'] ?? null) ? $settings['access_allowed_group_ids'] : [];
$restrictionOn = !empty($settings['access_restriction_enabled']);
?>
<section class="crm-card" id="section-access" aria-labelledby="section-access-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-access-heading" class="crm-card__title"><?php p($l->t('Access control')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('App administrators always have access. When restriction is on, only allowlisted users and groups may use the app.')); ?> <?php p($l->t('All customer data stays on this Nextcloud — CustomerCheck never contacts external services.')); ?></p>
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
