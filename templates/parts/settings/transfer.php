<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="crm-card crm-section" aria-labelledby="crm-xfer-export-h" id="crm-transfer-export-card">
	<header class="crm-section__header">
		<div>
			<h2 id="crm-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="crm-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="crm-callout crm-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="crm-transfer-export-form" class="crm-form" novalidate>
		<div class="crm-form-row">
			<label for="crm-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="crm-input-group">
				<input type="password" id="crm-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="crm-transfer-passphrase-hint crm-transfer-passphrase-strength">
				<button type="button" class="crm-btn crm-btn--ghost" id="crm-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="crm-form-row__hint" id="crm-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="crm-form-row__hint crm-transfer-strength" id="crm-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="crm-actions">
			<button type="submit" class="crm-btn crm-btn--primary" id="crm-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="crm-card crm-section" aria-labelledby="crm-xfer-import-h" id="crm-transfer-import-card">
	<header class="crm-section__header">
		<div>
			<h2 id="crm-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="crm-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="crm-transfer-last" id="crm-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="crm-transfer-import-form" class="crm-form" novalidate>
		<fieldset class="crm-form-row crm-fieldset" aria-describedby="crm-transfer-replace-hint">
			<legend><?php p($l->t('Mode')); ?></legend>
			<div class="crm-choice-cards">
				<label class="crm-choice-card">
					<input type="radio" name="mode" value="merge" checked>
					<span class="crm-choice-card__copy">
						<span class="crm-choice-card__title"><?php p($l->t('Merge')); ?></span>
						<span class="crm-choice-card__hint"><?php p($l->t('add and update; nothing is deleted')); ?></span>
					</span>
				</label>
				<label class="crm-choice-card crm-choice-card--danger">
					<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
					<span class="crm-choice-card__copy">
						<span class="crm-choice-card__title"><?php p($l->t('Replace')); ?></span>
						<span class="crm-choice-card__hint"><?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
					</span>
				</label>
			</div>
			<p class="crm-form-row__hint" id="crm-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="crm-form-row">
			<label for="crm-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="crm-transfer-file" name="file" accept=".nctransfer,application/json" class="crm-file-input">
		</div>
		<div class="crm-form-row">
			<label for="crm-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="crm-input-group">
				<input type="password" id="crm-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="crm-btn crm-btn--ghost" id="crm-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="crm-transfer-import-status" class="crm-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="crm-transfer-report" class="crm-dialog crm-transfer-report" aria-labelledby="crm-transfer-report-h">
	<div class="crm-dialog__body">
		<h3 id="crm-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="crm-transfer-report-verdict" class="crm-transfer-report__verdict"></p>
		<p id="crm-transfer-report-file" class="crm-form-row__hint"></p>
		<div id="crm-transfer-report-groups"></div>
	</div>
	<footer class="crm-dialog__footer">
		<div id="crm-transfer-replace-confirm" class="crm-form-row" hidden>
			<label for="crm-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="crm-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="crm-actions">
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="crm-btn crm-btn--primary" id="crm-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="crm-transfer-progress" class="crm-dialog" aria-labelledby="crm-transfer-progress-h" aria-live="polite">
	<div class="crm-dialog__body">
		<h3 id="crm-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="crm-transfer-progress-bar" max="100" value="0"></progress>
		<p id="crm-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="crm-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="crm-transfer-result-live" class="crm-visually-hidden" role="status" aria-live="polite"></div>
