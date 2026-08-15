<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

use OCA\CustomerCheck\Service\IconCatalog;

$canManageWorkspace = !empty($_['canManageWorkspace']);
?>
<section class="crm-mailbox-settings" id="section-workspace-mailbox" aria-labelledby="section-workspace-mailbox-heading">
	<header class="crm-mailbox-settings__intro">
		<h2 id="section-workspace-mailbox-heading" class="crm-card__title"><?php p($l->t('Team & mailbox')); ?></h2>
		<p class="crm-card__lead"><?php p($l->t('Manage members, weekly promise limits, and connect a shared mailbox.')); ?></p>
	</header>

	<?php if ($canManageWorkspace): ?>
	<nav class="crm-mailbox-settings__jump" aria-label="<?php p($l->t('On this page')); ?>">
		<a class="crm-mailbox-settings__jump-link" href="#crm-ws-mailbox-card"><?php p($l->t('Shared mailbox')); ?></a>
		<a class="crm-mailbox-settings__jump-link" href="#crm-ws-members-card"><?php p($l->t('Members')); ?></a>
		<a class="crm-mailbox-settings__jump-link" href="#crm-ws-personal-mail-policy"><?php p($l->t('Personal email')); ?></a>
		<a class="crm-mailbox-settings__jump-link" href="#crm-ws-limits-card"><?php p($l->t('Weekly limits')); ?></a>
		<a class="crm-mailbox-settings__jump-link" href="#crm-value-maps"><?php p($l->t('More')); ?></a>
	</nav>
	<?php endif; ?>

	<?php include __DIR__ . '/../parts/workspace-boot-status.php'; ?>

	<div id="crm-ws-current" class="crm-page-stack crm-mailbox-settings__stack" hidden>
		<p id="crm-ws-current-label" class="crm-muted crm-mailbox-settings__current crm-sr-only"></p>

		<?php if ($canManageWorkspace): ?>
		<section class="crm-card" id="crm-ws-members-card" aria-labelledby="crm-ws-members-title">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h3 id="crm-ws-members-title" class="crm-card__title"><?php p($l->t('Members')); ?></h3>
					<p class="crm-card__lead"><?php p($l->t('Who can work here — and who may send from the shared mailbox.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<ul id="crm-ws-members" class="crm-ws-members" aria-label="<?php p($l->t('Team members')); ?>"></ul>

				<details class="crm-advanced" id="crm-ws-member-add">
					<summary class="crm-advanced__summary"><?php p($l->t('Add a member')); ?></summary>
					<form id="crm-ws-member-form" class="crm-form crm-form--stack" novalidate>
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
								<option value="viewer"><?php p($l->t('Look only')); ?></option>
								<option value="member" selected><?php p($l->t('Can edit')); ?></option>
								<option value="manager"><?php p($l->t('Can manage team')); ?></option>
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
						<summary class="crm-advanced__summary"><?php p($l->t('Import a Nextcloud group once')); ?></summary>
						<p class="crm-muted"><?php p($l->t('Adds everyone in that group now. Not live sync — run again if the group changes.')); ?></p>
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
									<option value="viewer"><?php p($l->t('Look only')); ?></option>
									<option value="member" selected><?php p($l->t('Can edit')); ?></option>
									<option value="manager"><?php p($l->t('Can manage team')); ?></option>
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
						<summary class="crm-advanced__summary"><?php p($l->t('Import a Circle once')); ?></summary>
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
									<option value="viewer"><?php p($l->t('Look only')); ?></option>
									<option value="member" selected><?php p($l->t('Can edit')); ?></option>
									<option value="manager"><?php p($l->t('Can manage team')); ?></option>
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
				</details>
			</div>
		</section>
		<?php endif; ?>

		<?php if ($canManageWorkspace): ?>
		<section class="crm-card" id="crm-ws-personal-mail-policy" aria-labelledby="crm-ws-personal-mail-policy-title">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h3 id="crm-ws-personal-mail-policy-title" class="crm-card__title"><?php p($l->t('Personal email sync')); ?></h3>
					<p class="crm-card__lead"><?php p($l->t('Let teammates connect their own inboxes under My email. Messages stay private until they share a folder.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<form id="crm-ws-personal-mail-policy-form" class="crm-form crm-form--stack" novalidate>
					<label class="crm-switch">
						<input type="checkbox" id="crm-ws-personal-mail-enabled" name="personalMailEnabled" value="1">
						<span><?php p($l->t('Enable personal email sync for this workspace')); ?></span>
					</label>
					<label class="crm-check" id="crm-ws-personal-mail-dsgvo-wrap">
						<input type="checkbox" id="crm-ws-personal-mail-dsgvo" name="personalMailDsgvoAck" value="1">
						<span><?php p($l->t('I confirm this workspace may sync personal mail that members connect, store short previews and bodies in CustomerCheck under retention rules, and that members must acknowledge sharing before teammates can read shared folders on linked customers.')); ?></span>
					</label>
					<p id="crm-ws-personal-mail-policy-status" class="form-help" role="status" aria-live="polite"></p>
					<p id="crm-ws-personal-mail-policy-error" class="form-error" role="alert" hidden></p>
					<div class="crm-form-actions">
						<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Save personal email policy')); ?></button>
					</div>
				</form>
			</div>
		</section>
		<?php endif; ?>

		<?php if ($canManageWorkspace): ?>
		<section class="crm-card" id="crm-ws-mailbox-card" aria-labelledby="crm-ws-mailbox-title">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h3 id="crm-ws-mailbox-title" class="crm-card__title"><?php p($l->t('Shared mailbox')); ?></h3>
					<p class="crm-card__lead"><?php p($l->t('Up to three shared team inboxes. Test each connection, then save. Sync now refreshes every healthy mailbox.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<div class="crm-callout crm-callout--warning" role="status">
					<?php print_unescaped(IconCatalog::renderCalloutWell('alert-triangle', 'warning')); ?>
					<p class="crm-callout__text"><?php p($l->t('Connect a team inbox only — not a personal one. Typical flow: write to the customer and CC this address, or forward their mail here. We file one copy on the unique customer (From, To/CC, or original sender in a forward) — never on every recipient. Unclear mail stays in Assign.')); ?></p>
				</div>

				<div id="crm-mailbox-list" class="crm-mailbox-list-host" aria-live="polite"></div>
				<div id="crm-mailbox-empty" class="crm-empty-state" role="status" hidden>
					<h4 class="crm-empty-state__title"><?php p($l->t('No shared mailbox yet')); ?></h4>
					<p class="crm-empty-state__text"><?php p($l->t('Connect a team inbox below so Talk and Assign can show shared mail. Personal inboxes stay out.')); ?></p>
				</div>

				<details class="crm-mailbox-compose" id="crm-mailbox-compose">
					<summary class="crm-mailbox-compose__summary">
						<span class="crm-mailbox-compose__summary-title"><?php p($l->t('Add or edit mailbox')); ?></span>
						<span class="crm-muted crm-mailbox-compose__summary-hint"><?php p($l->t('Opens the connection form')); ?></span>
					</summary>
					<form id="crm-mailbox-form" class="crm-form crm-form--stack" novalidate data-crm-mailbox-locked="0">
						<input type="hidden" id="crm-mbox-id" name="id" value="">
						<p id="crm-mailbox-form-mode" class="crm-muted" hidden></p>

						<fieldset class="crm-fieldset">
							<legend class="crm-fieldset__legend"><?php p($l->t('Inbox')); ?></legend>
							<div class="form-group">
								<label class="form-label" for="crm-mbox-label"><?php p($l->t('Label')); ?></label>
								<input type="text" id="crm-mbox-label" name="label" class="form-input crm-input" required maxlength="120">
							</div>
							<div class="form-group">
								<label class="form-label" for="crm-mbox-email"><?php p($l->t('Email')); ?></label>
								<input type="email" id="crm-mbox-email" name="email" class="form-input crm-input" required autocomplete="off">
								<p class="form-help"><?php p($l->t('The shared team address, e.g. info@example.com.')); ?></p>
							</div>
							<div class="form-group">
								<label class="form-label" for="crm-mbox-user"><?php p($l->t('Username')); ?></label>
								<input type="text" id="crm-mbox-user" name="username" class="form-input crm-input" required autocomplete="off">
								<p class="form-help"><?php p($l->t('Usually the full email address. Do not paste the account id in parentheses (e.g. m0...).')); ?></p>
							</div>
							<div class="form-group">
								<label class="form-label" for="crm-mbox-pass"><?php p($l->t('Password')); ?></label>
								<input type="password" id="crm-mbox-pass" name="password" class="form-input crm-input" required autocomplete="new-password">
								<p id="crm-mbox-pass-help" class="form-help"><?php p($l->t('Required for a new mailbox. When editing, leave blank to keep the saved password.')); ?></p>
							</div>
						</fieldset>

						<details class="crm-advanced" id="crm-mailbox-servers">
							<summary class="crm-advanced__summary"><?php p($l->t('Mail servers (from your hoster)')); ?></summary>
							<fieldset class="crm-fieldset">
							<legend class="crm-fieldset__legend visually-hidden"><?php p($l->t('Mail servers')); ?></legend>
							<p class="crm-muted"><?php p($l->t('Copy these from your hoster manual setup sheet. IMAP receives mail; SMTP sends it.')); ?></p>
							<div class="form-group">
								<label class="form-label" for="crm-mbox-imap-host"><?php p($l->t('IMAP host')); ?></label>
								<input type="text" id="crm-mbox-imap-host" name="imapHost" class="form-input crm-input" required autocomplete="off">
							</div>
							<div class="crm-form-row-2">
								<div class="form-group">
									<label class="form-label" for="crm-mbox-imap-port"><?php p($l->t('IMAP port')); ?></label>
									<input type="number" id="crm-mbox-imap-port" name="imapPort" class="form-input crm-input" value="993" min="1" max="65535" required>
								</div>
								<div class="form-group">
									<label class="form-label" for="crm-mbox-imap-enc"><?php p($l->t('IMAP encryption')); ?></label>
									<select id="crm-mbox-imap-enc" name="imapEnc" class="form-select crm-input">
										<option value="ssl" selected><?php p($l->t('SSL (port 993)')); ?></option>
										<option value="tls"><?php p($l->t('STARTTLS (port 143)')); ?></option>
										<option value="none"><?php p($l->t('None (not recommended)')); ?></option>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="form-label" for="crm-mbox-smtp-host"><?php p($l->t('SMTP host')); ?></label>
								<input type="text" id="crm-mbox-smtp-host" name="smtpHost" class="form-input crm-input" required autocomplete="off">
							</div>
							<div class="crm-form-row-2">
								<div class="form-group">
									<label class="form-label" for="crm-mbox-smtp-port"><?php p($l->t('SMTP port')); ?></label>
									<input type="number" id="crm-mbox-smtp-port" name="smtpPort" class="form-input crm-input" value="465" min="1" max="65535" required>
								</div>
								<div class="form-group">
									<label class="form-label" for="crm-mbox-smtp-enc"><?php p($l->t('SMTP encryption')); ?></label>
									<select id="crm-mbox-smtp-enc" name="smtpEnc" class="form-select crm-input">
										<option value="ssl" selected><?php p($l->t('SSL (port 465)')); ?></option>
										<option value="tls"><?php p($l->t('STARTTLS (port 587)')); ?></option>
										<option value="none"><?php p($l->t('None (not recommended)')); ?></option>
									</select>
								</div>
							</div>
							<p class="form-help"><?php p($l->t('Common defaults: IMAP 993 + SSL, SMTP 465 + SSL. Changing the port updates encryption automatically.')); ?></p>
							</fieldset>
						</details>

						<div id="crm-mailbox-folders-wrap" class="form-group" hidden>
							<span class="form-label" id="crm-mbox-folders-label"><?php p($l->t('Folders to sync')); ?></span>
							<div id="crm-mbox-folder-picker" class="crm-folder-picker" role="group" aria-labelledby="crm-mbox-folders-label" aria-describedby="crm-mbox-folders-help">
								<label class="crm-check">
									<input type="checkbox" name="folders" value="INBOX" checked>
									<span>INBOX</span>
								</label>
							</div>
							<p id="crm-mbox-folders-help" class="form-help"><?php p($l->t('Shown after a successful connection test. Tick every folder the team should see. INBOX is on by default.')); ?></p>
						</div>
						<details class="crm-advanced" id="crm-mbox-signature-details">
							<summary class="crm-advanced__summary"><?php p($l->t('Team signature')); ?></summary>
							<div class="form-group crm-signature-editor" id="crm-mbox-signature-wrap">
								<span class="form-label crm-sales-compose__label visually-hidden" id="crm-mbox-signature-label"><?php p($l->t('Team signature')); ?></span>
								<p class="form-help" id="crm-mbox-signature-help"><?php p($l->t('Saved separately from the mailbox connection. Members who send as this inbox can include it. Add a logo from Files or a public https image link.')); ?></p>
								<textarea id="crm-mbox-signature" class="visually-hidden" rows="1" aria-hidden="true" tabindex="-1"></textarea>
								<div id="crm-mbox-signature-editor" class="crm-signature-editor__mount" aria-labelledby="crm-mbox-signature-label" aria-describedby="crm-mbox-signature-help"></div>
								<div class="crm-form-actions crm-signature-editor__actions">
									<button type="button" class="crm-btn crm-btn--primary" id="crm-mbox-signature-save" disabled><?php p($l->t('Save Team signature')); ?></button>
								</div>
							</div>
						</details>
						<label class="crm-switch">
							<input type="checkbox" id="crm-mbox-dsgvo" name="dsgvoAck" value="1" required>
							<span><?php p($l->t('I confirm this is a shared team inbox — not a personal mailbox. Colleagues who can read mail may see these messages, including short plain-text previews cached in CustomerCheck until retention deletes them.')); ?></span>
						</label>
						<p id="crm-mailbox-error" class="form-error" role="alert" hidden></p>
						<p id="crm-mailbox-test-status" class="form-help" role="status" aria-live="polite"></p>
						<div class="crm-form-actions">
							<button type="button" class="crm-btn crm-btn--ghost" id="crm-mailbox-reset" hidden><?php p($l->t('New mailbox')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" id="crm-mailbox-test"><?php p($l->t('Test connection')); ?></button>
							<button type="submit" class="crm-btn crm-btn--primary" id="crm-mailbox-save" disabled><?php p($l->t('Save mailbox')); ?></button>
						</div>
					</form>
				</details>
			</div>
		</section>

		<section class="crm-card" id="crm-ws-limits-card" aria-labelledby="crm-ws-limits-title">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h3 id="crm-ws-limits-title" class="crm-card__title"><?php p($l->t('Weekly limits')); ?></h3>
					<p class="crm-card__lead"><?php p($l->t('How many promises, how fast leads get a reply, and how long shared mail stays indexed.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<form id="crm-ws-settings-form" class="crm-form crm-form--stack" novalidate>
					<div class="crm-form-row-2">
						<div class="form-group">
							<label class="form-label" for="crm-ws-wip"><?php p($l->t('Weekly promise limit')); ?></label>
							<input type="number" id="crm-ws-wip" name="wipLimit" class="form-input crm-input" min="3" max="15" step="1" required>
							<p class="form-help"><?php p($l->t('How many new commitments the team may take on each week.')); ?></p>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ws-lead-sla"><?php p($l->t('First reply within (hours)')); ?></label>
							<input type="number" id="crm-ws-lead-sla" name="leadSlaHours" class="form-input crm-input" min="1" max="168" step="1" required>
							<p class="form-help"><?php p($l->t('Website leads must get a first touch within this many hours.')); ?></p>
						</div>
					</div>
					<div class="crm-form-row-2">
						<div class="form-group">
							<label class="form-label" for="crm-ws-stuck"><?php p($l->t('Quote follow-up after (days)')); ?></label>
							<input type="number" id="crm-ws-stuck" name="stuckChaseDays" class="form-input crm-input" min="1" max="90" step="1" required>
							<p class="form-help"><?php p($l->t('Quoted deals without a follow-up show on Today after this many days.')); ?></p>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ws-mail-retention"><?php p($l->t('Shared mail retention (days)')); ?></label>
							<input type="number" id="crm-ws-mail-retention" name="mailRetentionDays" class="form-input crm-input" min="30" max="3650" step="1" required>
							<p class="form-help"><?php p($l->t('Indexed shared-mailbox messages older than this are deleted from CustomerCheck (minimum 30 days). The mailbox itself is unchanged.')); ?></p>
						</div>
					</div>
					<label class="crm-switch">
						<input type="checkbox" id="crm-ws-digest" name="digestEnabled" value="1">
						<span><?php p($l->t('Enable weekly digest')); ?></span>
					</label>
					<p class="form-help"><?php
						p($l->t('Sends a short “This week” note to managers and members. The app-wide schedule must also be on under Reminders & digest.'));
					?></p>
					<div class="form-group" id="crm-ws-ics-wrap" hidden>
						<label class="form-label" for="crm-ws-ics-url"><?php p($l->t('Calendar feed for renewals')); ?></label>
						<div class="crm-token-row">
							<input id="crm-ws-ics-url" class="form-input crm-input" type="text" readonly value="">
							<button type="button" class="crm-btn crm-btn--secondary" data-copy-target="crm-ws-ics-url" aria-label="<?php p($l->t('Copy calendar feed URL')); ?>"><?php p($l->t('Copy')); ?></button>
							<button type="button" class="crm-btn crm-btn--danger" id="crm-ws-ics-rotate" aria-label="<?php p($l->t('Rotate calendar feed URL')); ?>"><?php p($l->t('Rotate')); ?></button>
						</div>
						<p class="form-help"><?php p($l->t('Calendar feed for renewals and open due dates. Rotate if the link leaks.')); ?></p>
					</div>
					<div class="crm-form-actions">
						<button type="submit" class="crm-btn crm-btn--secondary"><?php p($l->t('Save team settings')); ?></button>
					</div>
				</form>
			</div>
		</section>

		<details class="crm-advanced crm-card" id="crm-value-maps">
			<summary class="crm-advanced__summary"><?php p($l->t('Data quality — country maps')); ?></summary>
			<p class="crm-muted"><?php p($l->t('Learned country aliases. Remove a mapping if it was wrong.')); ?></p>
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
					<button type="submit" class="crm-btn crm-btn--secondary" id="crm-value-maps-learn"><?php p($l->t('Apply & learn')); ?></button>
				</div>
			</form>
		</details>
		<?php endif; ?>
	</div>
</section>
