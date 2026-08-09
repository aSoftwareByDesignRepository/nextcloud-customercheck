<?php
/**
 * Workspace Review inbox sources — first-class settings card (not buried under mailbox Advanced).
 *
 * Scan actors use directory pickers (principle 11) — never raw username typing.
 *
 * @var \OCP\IL10N $l
 */
?>
<section class="crm-card" id="crm-review-sources" aria-labelledby="crm-review-sources-title">
	<header class="crm-card__header">
		<div class="crm-card__header-text">
			<h2 id="crm-review-sources-title" class="crm-card__title"><?php p($l->t('Review inbox sources')); ?></h2>
			<p class="crm-card__lead"><?php p($l->t('Choose what lands in Review. Shared mail stays on; other sources need a DSGVO tick first.')); ?></p>
		</div>
	</header>
	<div class="crm-card__body">
		<?php include __DIR__ . '/../parts/workspace-boot-status.php'; ?>
		<form id="crm-review-sources-form" class="crm-form crm-form--stack" novalidate aria-busy="true">
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-shared-mail" data-review-kind="shared_mail" checked>
				<span><?php p($l->t('Shared mailbox unmatched mail')); ?></span>
			</label>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-contacts" data-review-kind="contact_suggest">
				<span><?php p($l->t('Contacts suggestions (opt-in)')); ?></span>
			</label>
			<div class="crm-review-src-extra" data-review-extra="contact_suggest">
				<?php
				$crmPickerId = 'review-contacts-actor';
				$crmPickerLabel = $l->t('Scan as teammate');
				$crmPickerHelp = $l->t('Periodic scan needs a teammate login. Without it, use Send to Review only. Never type a raw user id.');
				include __DIR__ . '/../parts/directory-user-picker.php';
				?>
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-files" data-review-kind="file">
				<span><?php p($l->t('Files in review folder (opt-in)')); ?></span>
			</label>
			<div class="crm-review-src-extra" data-review-extra="file">
				<div class="form-group" id="crm-review-file-folder-group">
					<span class="form-label" id="crm-review-file-folder-label"><?php p($l->t('Review folder')); ?></span>
					<p class="form-help" id="crm-review-file-folder-help"><?php p($l->t('Browse Nextcloud and choose the folder Review should scan. Never type a raw folder file id.')); ?></p>
					<input type="hidden" id="crm-review-file-folder" value="" autocomplete="off">
					<input type="hidden" id="crm-review-file-folder-path" value="" autocomplete="off">
					<p id="crm-review-file-folder-status" class="crm-muted" role="status" aria-live="polite" aria-labelledby="crm-review-file-folder-label"><?php p($l->t('No review folder selected.')); ?></p>
					<div class="crm-form-actions">
						<button type="button" class="crm-btn crm-btn--primary" id="crm-review-file-folder-pick"><?php p($l->t('Browse folders')); ?></button>
						<button type="button" class="crm-btn crm-btn--ghost" id="crm-review-file-folder-clear" hidden><?php p($l->t('Clear folder')); ?></button>
					</div>
				</div>
				<?php
				$crmPickerId = 'review-file-actor';
				$crmPickerLabel = $l->t('Scan as teammate');
				$crmPickerHelp = $l->t('Teammate who can read that folder. Never type a raw user id.');
				include __DIR__ . '/../parts/directory-user-picker.php';
				?>
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-calendar" data-review-kind="calendar_event">
				<span><?php p($l->t('Calendar events (opt-in)')); ?></span>
			</label>
			<div class="crm-review-src-extra" data-review-extra="calendar_event">
				<div class="form-group">
					<label class="form-label" for="crm-review-cal-uris"><?php p($l->t('Calendar URIs (one per line)')); ?></label>
					<textarea id="crm-review-cal-uris" class="form-input crm-input" rows="3" placeholder="<?php p($l->t('One calendar URI per line, e.g. personal')); ?>"></textarea>
				</div>
				<?php
				$crmPickerId = 'review-cal-actor';
				$crmPickerLabel = $l->t('Scan as teammate');
				$crmPickerHelp = $l->t('Never type a raw user id — search and pick.');
				include __DIR__ . '/../parts/directory-user-picker.php';
				?>
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-tasks" data-review-kind="task">
				<span><?php p($l->t('Tasks lists (opt-in)')); ?></span>
			</label>
			<div class="crm-review-src-extra" data-review-extra="task">
				<div class="form-group">
					<label class="form-label" for="crm-review-task-lists"><?php p($l->t('Task calendar URIs (one per line)')); ?></label>
					<textarea id="crm-review-task-lists" class="form-input crm-input" rows="3"></textarea>
				</div>
				<?php
				$crmPickerId = 'review-task-actor';
				$crmPickerLabel = $l->t('Scan as teammate');
				$crmPickerHelp = $l->t('Never type a raw user id — search and pick.');
				include __DIR__ . '/../parts/directory-user-picker.php';
				?>
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-import" data-review-kind="import_anomaly">
				<span><?php p($l->t('Import anomalies (country / ambiguous names)')); ?></span>
			</label>
			<p class="crm-muted"><?php p($l->t('Off by default. Turn on only after you confirm privacy for non-mail sources — unmatched import values appear in Review.')); ?></p>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-dsgvo" name="dsgvoAck" value="1">
				<span><?php p($l->t('I confirm privacy disclosure for any newly enabled non-mail sources.')); ?></span>
			</label>
			<p id="crm-review-sources-status" class="form-help" role="status" aria-live="polite"></p>
			<div class="crm-form-actions">
				<button type="submit" class="crm-btn crm-btn--primary" id="crm-review-sources-save"><?php p($l->t('Save review sources')); ?></button>
			</div>
		</form>
	</div>
</section>
