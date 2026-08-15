<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */

$canManageWorkspace = !empty($_['canManageWorkspace']);
$urls = is_array($_['urls'] ?? null) ? $_['urls'] : [];
$settingsSections = is_array($urls['settingsSections'] ?? null) ? $urls['settingsSections'] : [];
$teamMailboxUrl = (string)($settingsSections['mailbox'] ?? '');
?>
<section class="crm-my-email-settings" id="section-my-email" aria-labelledby="section-my-email-heading">
	<header class="crm-my-email-settings__intro">
		<h2 id="section-my-email-heading" class="crm-card__title"><?php p($l->t('My email')); ?></h2>
		<p class="crm-card__lead"><?php p($l->t('Connect your personal mailbox. Folders stay private until you share them with the workspace.')); ?></p>
	</header>

	<?php include __DIR__ . '/../parts/workspace-boot-status.php'; ?>

	<div id="crm-ws-current" class="crm-page-stack crm-my-email-settings__stack" hidden>
		<p id="crm-ws-current-label" class="crm-muted crm-sr-only"></p>

		<section class="crm-card" id="crm-ws-personal-mail-mine" aria-labelledby="crm-ws-personal-mail-mine-title">
			<header class="crm-card__header">
				<div class="crm-card__header-text">
					<h3 id="crm-ws-personal-mail-mine-title" class="crm-card__title"><?php p($l->t('Your inbox')); ?></h3>
					<p class="crm-card__lead"><?php p($l->t('Sync brings mail into Inbox. Share a folder only when teammates should see it on linked customers.')); ?></p>
				</div>
			</header>
			<div class="crm-card__body">
				<p id="crm-personal-mail-state" class="crm-muted" role="status" aria-live="polite"><?php p($l->t('Loading…')); ?></p>
				<div id="crm-personal-mail-disabled" class="crm-empty-state" role="status" hidden>
					<h4 class="crm-empty-state__title"><?php p($l->t('Personal sync is off')); ?></h4>
					<p class="crm-empty-state__text"><?php p($l->t('Ask a manager to enable Personal email sync under Team & mailbox, then you can connect your inbox here.')); ?></p>
					<?php if ($canManageWorkspace && $teamMailboxUrl !== ''): ?>
					<p class="crm-empty-state__actions">
						<a class="crm-btn crm-btn--secondary" href="<?php p($teamMailboxUrl); ?>"><?php p($l->t('Open Team & mailbox')); ?></a>
					</p>
					<?php endif; ?>
				</div>

				<div id="crm-personal-mail-connected" hidden data-crm-bachus-pm-connected="1">
					<section class="crm-personal-mail-panel" aria-labelledby="crm-personal-mail-account-heading">
						<h4 id="crm-personal-mail-account-heading" class="crm-personal-mail-panel__title"><?php p($l->t('Connection')); ?></h4>
						<div id="crm-personal-mail-account" class="crm-stack crm-personal-mail-account" aria-live="polite"></div>
						<div class="crm-form-actions crm-personal-mail-connected-actions">
							<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-personal-mail-sync"><?php p($l->t('Sync now')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" id="crm-personal-mail-edit-connection"><?php p($l->t('Update password or servers')); ?></button>
						</div>
						<p class="form-help" id="crm-personal-mail-edit-hint"><?php p($l->t('Hoster changed? New app password? Update here — your synced mail stays.')); ?></p>
						<details class="crm-advanced crm-personal-mail-remove" id="crm-personal-mail-remove">
							<summary class="crm-advanced__summary"><?php p($l->t('Remove inbox')); ?></summary>
							<div class="crm-advanced__body">
								<p class="form-help" id="crm-personal-mail-disconnect-help"><?php p($l->t('Disconnect erases this inbox from CustomerCheck. Prefer Update password or servers when only the hoster or app password changed.')); ?></p>
								<div class="crm-form-actions">
									<button type="button" class="crm-btn crm-btn--ghost crm-btn--danger" id="crm-personal-mail-disconnect" aria-describedby="crm-personal-mail-disconnect-help"><?php p($l->t('Disconnect')); ?></button>
								</div>
							</div>
						</details>
					</section>
					<section class="crm-personal-mail-panel" id="crm-personal-mail-folders-wrap" aria-labelledby="crm-personal-mail-folders-label">
						<h4 class="crm-personal-mail-panel__title" id="crm-personal-mail-folders-heading"><?php p($l->t('Folders')); ?></h4>
						<span class="form-label visually-hidden" id="crm-personal-mail-folders-label"><?php p($l->t('Folders')); ?></span>
						<div id="crm-personal-mail-folders" class="crm-folder-picker crm-personal-mail-folders" role="group" aria-labelledby="crm-personal-mail-folders-label" aria-describedby="crm-personal-mail-folders-help"></div>
						<p id="crm-personal-mail-folders-help" class="form-help"><?php p($l->t('Turn on Sync to bring mail into Inbox — changes save automatically. Share only when teammates should read that folder on linked customers. Refresh folders after you add a mailbox folder.')); ?></p>
						<p id="crm-personal-mail-folders-status" class="form-help" role="status" aria-live="polite"></p>
						<div class="crm-form-actions">
							<button type="button" class="crm-btn crm-btn--secondary" id="crm-personal-mail-folders-refresh"><?php p($l->t('Refresh folders')); ?></button>
							<button type="button" class="crm-btn crm-btn--ghost" id="crm-personal-mail-folders-save" hidden><?php p($l->t('Save folders')); ?></button>
						</div>
					</section>
					<details class="crm-advanced" id="crm-personal-mail-signature-details">
						<summary class="crm-advanced__summary"><?php p($l->t('Signature (optional)')); ?></summary>
						<div class="crm-advanced__body">
							<div class="form-group crm-signature-editor">
								<span class="form-label crm-sales-compose__label" id="crm-personal-mail-signature-label"><?php p($l->t('Your signature')); ?></span>
								<p class="form-help" id="crm-personal-mail-signature-help"><?php p($l->t('Added when you send from this account. Add a logo from Files or a public https image link.')); ?></p>
								<div id="crm-personal-mail-signature-editor" class="crm-signature-editor__mount" aria-labelledby="crm-personal-mail-signature-label" aria-describedby="crm-personal-mail-signature-help"></div>
								<textarea id="crm-personal-mail-signature" class="visually-hidden" rows="1" aria-hidden="true" tabindex="-1"></textarea>
								<div class="crm-form-actions crm-signature-editor__actions">
									<button type="button" class="crm-btn crm-btn--primary" id="crm-personal-mail-signature-save"><?php p($l->t('Save signature')); ?></button>
								</div>
							</div>
						</div>
					</details>
				</div>

				<form id="crm-personal-mail-connect-form" class="crm-form crm-form--stack crm-personal-mail-connect" novalidate hidden data-crm-bachus-pm="1" aria-labelledby="crm-personal-mail-connect-heading">
					<h4 id="crm-personal-mail-connect-heading" class="crm-personal-mail-panel__title"><?php p($l->t('Connect your inbox')); ?></h4>
					<p id="crm-personal-mail-connect-lead" class="crm-muted"><?php p($l->t('Prefer Google or Microsoft when offered. Or connect with an app password below.')); ?></p>
					<div id="crm-personal-mail-oauth" class="crm-inline-actions crm-personal-mail-oauth" hidden></div>
					<label class="crm-switch crm-personal-mail-dsgvo" id="crm-pm-dsgvo-wrap">
						<input type="checkbox" id="crm-pm-dsgvo" name="dsgvoAck" value="1" required>
						<span><?php p($l->t('I confirm CustomerCheck may sync mail from my personal mailbox into this workspace. Messages stay private until I share a folder. Shared folders can be read by teammates on linked customers.')); ?></span>
					</label>
					<details class="crm-advanced crm-personal-mail-imap" id="crm-personal-mail-imap">
						<summary class="crm-advanced__summary"><?php p($l->t('Use email and app password')); ?></summary>
						<div class="crm-advanced__body crm-personal-mail-imap__body">
							<div class="form-group">
								<label class="form-label" for="crm-pm-email"><?php p($l->t('Email')); ?></label>
								<input type="email" id="crm-pm-email" name="email" class="form-input crm-input" required autocomplete="username" aria-describedby="crm-pm-email-help">
								<p id="crm-pm-email-help" class="form-help" hidden><?php p($l->t('To use a different email address, Disconnect first (that erases synced mail for this inbox).')); ?></p>
							</div>
							<div class="form-group" id="crm-pm-user-wrap">
								<label class="form-label" for="crm-pm-user"><?php p($l->t('Username (if different)')); ?></label>
								<input type="text" id="crm-pm-user" name="username" class="form-input crm-input" autocomplete="off" aria-describedby="crm-pm-user-help">
								<p id="crm-pm-user-help" class="form-help"><?php p($l->t('Leave blank to use your email address.')); ?></p>
							</div>
							<div class="form-group">
								<label class="form-label" for="crm-pm-pass"><?php p($l->t('Password')); ?></label>
								<input type="password" id="crm-pm-pass" name="password" class="form-input crm-input" autocomplete="new-password" aria-describedby="crm-pm-pass-help">
								<p id="crm-pm-pass-help" class="form-help" hidden><?php p($l->t('Leave blank to keep your current password. Enter a new app password when it changed.')); ?></p>
							</div>

							<details class="crm-personal-mail-servers" id="crm-personal-mail-servers">
								<summary class="crm-personal-mail-servers__summary"><?php p($l->t('Mail servers (from your hoster)')); ?></summary>
								<div class="crm-personal-mail-servers__body" role="group" aria-label="<?php p($l->t('Mail servers')); ?>">
									<div class="form-group">
										<label class="form-label" for="crm-pm-imap-host"><?php p($l->t('IMAP host')); ?></label>
										<input type="text" id="crm-pm-imap-host" name="imapHost" class="form-input crm-input" autocomplete="off">
									</div>
									<div class="crm-form-row-2">
										<div class="form-group">
											<label class="form-label" for="crm-pm-imap-port"><?php p($l->t('IMAP port')); ?></label>
											<input type="number" id="crm-pm-imap-port" name="imapPort" class="form-input crm-input" value="993" min="1" max="65535">
										</div>
										<div class="form-group">
											<label class="form-label" for="crm-pm-imap-enc"><?php p($l->t('IMAP encryption')); ?></label>
											<select id="crm-pm-imap-enc" name="imapEnc" class="form-select crm-input">
												<option value="ssl" selected><?php p($l->t('SSL (port 993)')); ?></option>
												<option value="tls"><?php p($l->t('STARTTLS (port 143)')); ?></option>
												<option value="none"><?php p($l->t('None (not recommended)')); ?></option>
											</select>
										</div>
									</div>
									<div class="form-group">
										<label class="form-label" for="crm-pm-smtp-host"><?php p($l->t('SMTP host')); ?></label>
										<input type="text" id="crm-pm-smtp-host" name="smtpHost" class="form-input crm-input" autocomplete="off">
									</div>
									<div class="crm-form-row-2">
										<div class="form-group">
											<label class="form-label" for="crm-pm-smtp-port"><?php p($l->t('SMTP port')); ?></label>
											<input type="number" id="crm-pm-smtp-port" name="smtpPort" class="form-input crm-input" value="465" min="1" max="65535">
										</div>
										<div class="form-group">
											<label class="form-label" for="crm-pm-smtp-enc"><?php p($l->t('SMTP encryption')); ?></label>
											<select id="crm-pm-smtp-enc" name="smtpEnc" class="form-select crm-input">
												<option value="ssl" selected><?php p($l->t('SSL (port 465)')); ?></option>
												<option value="tls"><?php p($l->t('STARTTLS (port 587)')); ?></option>
												<option value="none"><?php p($l->t('None (not recommended)')); ?></option>
											</select>
										</div>
									</div>
								</div>
							</details>
						</div>
					</details>
					<div class="crm-form-actions crm-personal-mail-connect__actions">
						<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-personal-mail-connect"><?php p($l->t('Connect my email')); ?></button>
						<button type="button" class="crm-btn crm-btn--ghost" id="crm-personal-mail-edit-cancel" hidden><?php p($l->t('Cancel')); ?></button>
					</div>
					<p id="crm-personal-mail-error" class="form-error" role="alert" hidden></p>
					<p id="crm-personal-mail-connect-status" class="form-help" role="status" aria-live="polite"></p>
				</form>
			</div>
		</section>
	</div>
</section>
