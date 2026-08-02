<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$canManage = !empty($_['canManage']);
$canManageWorkspace = !empty($_['canManageWorkspace']);
?>
<section class="crm-card" id="section-workspace-mailbox" aria-labelledby="section-workspace-mailbox-heading">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="section-workspace-mailbox-heading" class="crm-card__title"><?php p($l->t('Workspace & mailbox')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Manage members, WIP limits, and connect a shared IMAP mailbox. Only app administrators can create new workspaces.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<p id="crm-ws-status" class="crm-muted" role="status" aria-live="polite"><?php p($l->t('Loading workspace…')); ?></p>
			<p id="crm-ws-error" class="form-error" role="alert" hidden></p>

			<?php if ($canManage): ?>
			<form id="crm-ws-create-form" class="crm-form" novalidate>
				<h3 class="crm-card__title"><?php p($l->t('Create workspace')); ?></h3>
				<div class="form-group">
					<label class="form-label" for="crm-ws-name"><?php p($l->t('Name')); ?></label>
					<input type="text" id="crm-ws-name" name="name" class="form-input crm-input" required maxlength="120" autocomplete="organization">
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ws-slug"><?php p($l->t('Slug')); ?></label>
					<input type="text" id="crm-ws-slug" name="slug" class="form-input crm-input" required maxlength="64" pattern="[a-z0-9-]{2,64}" autocomplete="off" spellcheck="false">
					<p class="form-help"><?php p($l->t('Lowercase letters, digits, hyphens.')); ?></p>
				</div>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Create workspace')); ?></button>
				</div>
			</form>
			<?php endif; ?>

			<div id="crm-ws-current" hidden>
				<h3 class="crm-card__title"><?php p($l->t('Current workspace')); ?></h3>
				<p id="crm-ws-current-label" class="crm-muted"></p>

				<form id="crm-ws-settings-form" class="crm-form" novalidate>
					<div class="form-group">
						<label class="form-label" for="crm-ws-wip"><?php p($l->t('Weekly WIP limit')); ?></label>
						<input type="number" id="crm-ws-wip" name="wipLimit" class="form-input crm-input" min="3" max="15" step="1" required>
						<p class="form-help"><?php p($l->t('How many new commitments the team may take on each week.')); ?></p>
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-ws-lead-sla"><?php p($l->t('Lead reply SLA (hours)')); ?></label>
						<input type="number" id="crm-ws-lead-sla" name="leadSlaHours" class="form-input crm-input" min="1" max="168" step="1" required>
						<p class="form-help"><?php p($l->t('Website leads must get a first touch within this many hours.')); ?></p>
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-ws-stuck"><?php p($l->t('Quote follow-up after (days)')); ?></label>
						<input type="number" id="crm-ws-stuck" name="stuckChaseDays" class="form-input crm-input" min="1" max="90" step="1" required>
						<p class="form-help"><?php p($l->t('Quoted deals without a follow-up show in Chase after this many days.')); ?></p>
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-ws-mail-retention"><?php p($l->t('Shared mail retention (days)')); ?></label>
						<input type="number" id="crm-ws-mail-retention" name="mailRetentionDays" class="form-input crm-input" min="30" max="3650" step="1" required>
						<p class="form-help"><?php p($l->t('Indexed shared-mailbox messages older than this are deleted from CustomerCheck (minimum 30 days). The mailbox itself is unchanged.')); ?></p>
					</div>
					<label class="crm-switch">
						<input type="checkbox" id="crm-ws-digest" name="digestEnabled" value="1">
						<span><?php p($l->t('Enable workspace digest')); ?></span>
					</label>
					<div class="form-group" id="crm-ws-ics-wrap" hidden>
						<label class="form-label" for="crm-ws-ics-url"><?php p($l->t('ICS renewal feed (this workspace)')); ?></label>
						<div class="crm-token-row">
							<input id="crm-ws-ics-url" class="form-input crm-input" type="text" readonly value="">
							<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-ws-ics-url"><?php p($l->t('Copy')); ?></button>
							<button type="button" class="crm-btn crm-btn--danger" id="crm-ws-ics-rotate"><?php p($l->t('Rotate')); ?></button>
						</div>
						<p class="form-help"><?php p($l->t('Calendar feed for renewals and open due dates in this book only. Separate from the Default-workspace feed under Public feeds.')); ?></p>
					</div>
					<div class="crm-form-actions">
						<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Save workspace settings')); ?></button>
					</div>
				</form>

				<h3 class="crm-card__title"><?php p($l->t('Members')); ?></h3>
				<ul id="crm-ws-members" class="crm-ws-members" aria-label="<?php p($l->t('Workspace members')); ?>"></ul>
				<form id="crm-ws-member-form" class="crm-form" novalidate>
					<div class="form-group">
						<label class="form-label" for="crm-ws-member-search"><?php p($l->t('Colleague')); ?></label>
						<div class="user-picker" id="crm-ws-member-picker">
							<div class="user-picker__control">
								<input type="search" id="crm-ws-member-search" class="form-input crm-input user-picker__search"
									role="combobox" aria-autocomplete="list" aria-expanded="false"
									aria-controls="crm-ws-member-suggest"
									placeholder="<?php p($l->t('Search by name or login…')); ?>"
									autocomplete="off" spellcheck="false">
								<button type="button" class="user-picker__clear" id="crm-ws-member-clear" hidden
									aria-label="<?php p($l->t('Clear selected colleague')); ?>">×</button>
								<input type="hidden" id="crm-ws-member-uid" name="userId" value="" required>
							</div>
							<ul id="crm-ws-member-suggest" class="user-picker__list" role="listbox" hidden></ul>
						</div>
						<p class="form-help"><?php p($l->t('Search by name or login, then pick a colleague. Never type a raw user id.')); ?></p>
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-ws-member-role"><?php p($l->t('Role')); ?></label>
						<select id="crm-ws-member-role" name="role" class="form-select crm-input">
							<option value="viewer"><?php p($l->t('Viewer')); ?></option>
							<option value="member" selected><?php p($l->t('Member')); ?></option>
							<option value="manager"><?php p($l->t('Manager')); ?></option>
						</select>
					</div>
					<label class="crm-switch">
						<input type="checkbox" id="crm-ws-member-mail" name="can_mail_send" value="1">
						<span><?php p($l->t('Can send from shared mailbox')); ?></span>
					</label>
					<div class="crm-form-actions">
						<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Add member')); ?></button>
					</div>
				</form>

				<details class="crm-advanced" id="crm-ws-member-import">
					<summary><?php p($l->t('Import a Nextcloud group once')); ?></summary>
					<p class="crm-muted"><?php p($l->t('Adds everyone in that group to this book now. Not live sync — run again if the group changes.')); ?></p>
					<form id="crm-ws-member-import-form" class="crm-form crm-form--stack" novalidate>
						<div class="form-group">
							<label class="form-label" for="crm-ws-import-group"><?php p($l->t('Nextcloud group')); ?></label>
							<select id="crm-ws-import-group" name="groupId" class="form-select crm-input" required>
								<option value=""><?php p($l->t('Choose a group…')); ?></option>
								<?php foreach (($_['availableGroups'] ?? []) as $g):
									$gid = (string)($g['id'] ?? '');
									$glabel = (string)($g['displayName'] ?? $gid);
									if ($gid === '') {
										continue;
									}
									?>
									<option value="<?php p($gid); ?>"><?php p($glabel); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ws-import-role"><?php p($l->t('Role for imported people')); ?></label>
							<select id="crm-ws-import-role" name="role" class="form-select crm-input">
								<option value="viewer"><?php p($l->t('Viewer')); ?></option>
								<option value="member" selected><?php p($l->t('Member')); ?></option>
								<option value="manager"><?php p($l->t('Manager')); ?></option>
							</select>
						</div>
						<label class="crm-switch">
							<input type="checkbox" id="crm-ws-import-mail" name="can_mail_send" value="1">
							<span><?php p($l->t('Can send from shared mailbox')); ?></span>
						</label>
						<div class="crm-form-actions">
							<button type="submit" class="crm-btn crm-btn--secondary" id="crm-ws-import-submit"><?php p($l->t('Import group')); ?></button>
						</div>
					</form>
				</details>

				<?php if (!empty($_['circlesAvailable'])): ?>
				<details class="crm-advanced" id="crm-ws-member-import-circle">
					<summary><?php p($l->t('Import a Circle once')); ?></summary>
					<p class="crm-muted"><?php p($l->t('Adds local people from a Circle you belong to. Not live sync — run again if the Circle changes.')); ?></p>
					<form id="crm-ws-member-import-circle-form" class="crm-form crm-form--stack" novalidate>
						<div class="form-group">
							<label class="form-label" for="crm-ws-import-circle"><?php p($l->t('Circle')); ?></label>
							<select id="crm-ws-import-circle" name="circleId" class="form-select crm-input" required>
								<option value=""><?php p($l->t('Choose a Circle…')); ?></option>
								<?php foreach (($_['availableCircles'] ?? []) as $c):
									$cid = (string)($c['id'] ?? '');
									$clabel = (string)($c['displayName'] ?? $cid);
									if ($cid === '') {
										continue;
									}
									?>
									<option value="<?php p($cid); ?>"><?php p($clabel); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ws-import-circle-role"><?php p($l->t('Role for imported people')); ?></label>
							<select id="crm-ws-import-circle-role" name="role" class="form-select crm-input">
								<option value="viewer"><?php p($l->t('Viewer')); ?></option>
								<option value="member" selected><?php p($l->t('Member')); ?></option>
								<option value="manager"><?php p($l->t('Manager')); ?></option>
							</select>
						</div>
						<label class="crm-switch">
							<input type="checkbox" id="crm-ws-import-circle-mail" name="can_mail_send" value="1">
							<span><?php p($l->t('Can send from shared mailbox')); ?></span>
						</label>
						<div class="crm-form-actions">
							<button type="submit" class="crm-btn crm-btn--secondary" id="crm-ws-import-circle-submit"><?php p($l->t('Import Circle')); ?></button>
						</div>
					</form>
				</details>
				<?php endif; ?>

				<h3 class="crm-card__title"><?php p($l->t('Shared mailbox')); ?></h3>
				<p class="crm-callout" role="status"><?php p($l->t('Messages from a connected mailbox are shared with everyone in this workspace who can read mail. Do not connect a personal inbox.')); ?></p>
				<form id="crm-mailbox-form" class="crm-form" novalidate>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-label"><?php p($l->t('Label')); ?></label>
						<input type="text" id="crm-mbox-label" name="label" class="form-input crm-input" required maxlength="120">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-email"><?php p($l->t('Email')); ?></label>
						<input type="email" id="crm-mbox-email" name="email" class="form-input crm-input" required autocomplete="off">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-imap-host"><?php p($l->t('IMAP host')); ?></label>
						<input type="text" id="crm-mbox-imap-host" name="imapHost" class="form-input crm-input" required autocomplete="off">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-imap-port"><?php p($l->t('IMAP port')); ?></label>
						<input type="number" id="crm-mbox-imap-port" name="imapPort" class="form-input crm-input" value="993" min="1" max="65535">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-smtp-host"><?php p($l->t('SMTP host')); ?></label>
						<input type="text" id="crm-mbox-smtp-host" name="smtpHost" class="form-input crm-input" required autocomplete="off">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-smtp-port"><?php p($l->t('SMTP port')); ?></label>
						<input type="number" id="crm-mbox-smtp-port" name="smtpPort" class="form-input crm-input" value="587" min="1" max="65535">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-user"><?php p($l->t('Username')); ?></label>
						<input type="text" id="crm-mbox-user" name="username" class="form-input crm-input" required autocomplete="off">
					</div>
					<div class="form-group">
						<label class="form-label" for="crm-mbox-pass"><?php p($l->t('Password')); ?></label>
						<input type="password" id="crm-mbox-pass" name="password" class="form-input crm-input" required autocomplete="new-password">
					</div>
					<div id="crm-mailbox-folders-wrap" class="form-group" hidden>
						<label class="form-label" for="crm-mbox-folders"><?php p($l->t('Folders to sync')); ?></label>
						<textarea id="crm-mbox-folders" name="folders" class="form-input crm-input" rows="4" placeholder="<?php p($l->t('One folder path per line, e.g. INBOX')); ?>"></textarea>
						<p class="form-help"><?php p($l->t('Shown after a successful connection test. Enter folder paths the team should see.')); ?></p>
					</div>
					<label class="crm-switch">
						<input type="checkbox" id="crm-mbox-dsgvo" name="dsgvoAck" value="1" required>
						<span><?php p($l->t('I confirm this is a shared mailbox and colleagues in the workspace may read these messages (DSGVO).')); ?></span>
					</label>
					<p id="crm-mailbox-error" class="form-error" role="alert" hidden></p>
					<p id="crm-mailbox-test-status" class="form-help" role="status" aria-live="polite"></p>
					<div class="crm-form-actions">
						<button type="button" class="crm-btn crm-btn--secondary" id="crm-mailbox-test"><?php p($l->t('Test connection')); ?></button>
						<button type="submit" class="crm-btn crm-btn--primary" id="crm-mailbox-save" disabled><?php p($l->t('Save mailbox')); ?></button>
					</div>
				</form>

				<details class="crm-advanced" id="crm-value-maps">
					<summary><?php p($l->t('Data quality — country maps')); ?></summary>
					<p class="crm-muted"><?php p($l->t('Learned country aliases for this workspace. Remove a mapping if it was wrong.')); ?></p>
					<div id="crm-value-maps-list" class="crm-stack" role="region" aria-live="polite" aria-busy="false"></div>
					<form id="crm-value-maps-form" class="crm-form crm-form--stack" novalidate>
						<div class="form-group">
							<label class="form-label" for="crm-vm-raw"><?php p($l->t('Raw value')); ?></label>
							<input type="text" id="crm-vm-raw" class="form-input crm-input" autocomplete="off" placeholder="<?php p($l->t('e.g. Deutschland')); ?>">
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-vm-canonical"><?php p($l->t('ISO country')); ?></label>
							<input type="text" id="crm-vm-canonical" class="form-input crm-input" maxlength="2" autocomplete="off" placeholder="<?php p($l->t('DE')); ?>">
						</div>
						<p id="crm-value-maps-status" class="form-help" role="status" aria-live="polite"></p>
						<div class="crm-form-actions">
							<button type="submit" class="crm-btn crm-btn--secondary" id="crm-value-maps-learn" style="min-height:44px"><?php p($l->t('Apply & learn')); ?></button>
						</div>
					</form>
				</details>
			</div>
		</div>
	</section>
