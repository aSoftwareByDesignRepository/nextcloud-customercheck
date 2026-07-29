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
					</div>
					<label class="crm-switch">
						<input type="checkbox" id="crm-ws-digest" name="digestEnabled" value="1">
						<span><?php p($l->t('Enable workspace digest')); ?></span>
					</label>
					<div class="crm-form-actions">
						<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Save workspace settings')); ?></button>
					</div>
				</form>

				<h3 class="crm-card__title"><?php p($l->t('Members')); ?></h3>
				<ul id="crm-ws-members" class="crm-ws-members" aria-label="<?php p($l->t('Workspace members')); ?>"></ul>
				<form id="crm-ws-member-form" class="crm-form" novalidate>
					<div class="form-group">
						<label class="form-label" for="crm-ws-member-uid"><?php p($l->t('User id')); ?></label>
						<input type="text" id="crm-ws-member-uid" name="userId" class="form-input crm-input" required autocomplete="username">
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
			</div>
		</div>
	</section>
