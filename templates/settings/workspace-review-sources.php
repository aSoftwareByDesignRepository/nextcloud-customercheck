<?php
/**
 * Workspace Review inbox sources — first-class settings card (not buried under mailbox Advanced).
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
		<form id="crm-review-sources-form" class="crm-form crm-form--stack" novalidate>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-shared-mail" data-review-kind="shared_mail" checked>
				<span><?php p($l->t('Shared mailbox unmatched mail')); ?></span>
			</label>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-contacts" data-review-kind="contact_suggest">
				<span><?php p($l->t('Contacts suggestions (opt-in)')); ?></span>
			</label>
			<div class="form-group crm-review-src-extra" data-review-extra="contact_suggest">
				<label class="form-label" for="crm-review-contacts-actor"><?php p($l->t('Scan as workspace member')); ?></label>
				<input type="text" id="crm-review-contacts-actor" class="form-input crm-input" autocomplete="off" placeholder="<?php p($l->t('Username whose Contacts address books are scanned')); ?>">
				<p class="crm-muted"><?php p($l->t('Periodic scan needs a member login. Without it, use Send to Review only.')); ?></p>
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-files" data-review-kind="file">
				<span><?php p($l->t('Files in review folder (opt-in)')); ?></span>
			</label>
			<div class="form-group crm-review-src-extra" data-review-extra="file">
				<label class="form-label" for="crm-review-file-folder"><?php p($l->t('Review folder file id')); ?></label>
				<input type="number" min="1" id="crm-review-file-folder" class="form-input crm-input" inputmode="numeric" autocomplete="off" placeholder="<?php p($l->t('Nextcloud file id of the folder')); ?>">
				<label class="form-label" for="crm-review-file-actor"><?php p($l->t('Scan as workspace member')); ?></label>
				<input type="text" id="crm-review-file-actor" class="form-input crm-input" autocomplete="off" placeholder="<?php p($l->t('Username that can read that folder')); ?>">
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-calendar" data-review-kind="calendar_event">
				<span><?php p($l->t('Calendar events (opt-in)')); ?></span>
			</label>
			<div class="form-group crm-review-src-extra" data-review-extra="calendar_event">
				<label class="form-label" for="crm-review-cal-uris"><?php p($l->t('Calendar URIs (one per line)')); ?></label>
				<textarea id="crm-review-cal-uris" class="form-input crm-input" rows="3" placeholder="<?php p($l->t('One calendar URI per line, e.g. personal')); ?>"></textarea>
				<label class="form-label" for="crm-review-cal-actor"><?php p($l->t('Scan as workspace member')); ?></label>
				<input type="text" id="crm-review-cal-actor" class="form-input crm-input" autocomplete="off">
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-tasks" data-review-kind="task">
				<span><?php p($l->t('Tasks lists (opt-in)')); ?></span>
			</label>
			<div class="form-group crm-review-src-extra" data-review-extra="task">
				<label class="form-label" for="crm-review-task-lists"><?php p($l->t('Task calendar URIs (one per line)')); ?></label>
				<textarea id="crm-review-task-lists" class="form-input crm-input" rows="3"></textarea>
				<label class="form-label" for="crm-review-task-actor"><?php p($l->t('Scan as workspace member')); ?></label>
				<input type="text" id="crm-review-task-actor" class="form-input crm-input" autocomplete="off">
			</div>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-import" data-review-kind="import_anomaly">
				<span><?php p($l->t('Import anomalies (country / ambiguous names)')); ?></span>
			</label>
			<p class="crm-muted"><?php p($l->t('Off by default. Enable only after DSGVO acknowledgement — unmatched import values appear in Review.')); ?></p>
			<label class="crm-switch">
				<input type="checkbox" id="crm-review-src-dsgvo" name="dsgvoAck" value="1">
				<span><?php p($l->t('I confirm DSGVO disclosure for any newly enabled non-mail sources.')); ?></span>
			</label>
			<p id="crm-review-sources-status" class="form-help" role="status" aria-live="polite"></p>
			<div class="crm-form-actions">
				<button type="submit" class="crm-btn crm-btn--primary" id="crm-review-sources-save"><?php p($l->t('Save review sources')); ?></button>
			</div>
		</form>
	</div>
</section>
