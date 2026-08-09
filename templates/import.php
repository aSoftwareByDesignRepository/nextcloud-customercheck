<?php
$caps = $_['capabilities'] ?? [];
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-import-root" data-crm-import-ready="0">
	<section class="crm-card" id="crm-import-source" aria-labelledby="crm-import-source-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-import-source-title" class="crm-card__title"><?php p($l->t('Where should we look?')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('We only preview first. Nothing is saved until you tap Import.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body crm-import-source-body">
			<fieldset class="crm-import-sources">
				<legend class="crm-sr-only"><?php p($l->t('Import source')); ?></legend>
				<label class="crm-import-tile">
					<input type="radio" name="crm-import-source" value="csv" checked />
					<span class="crm-import-tile__text">
						<span class="crm-import-tile__title"><?php p($l->t('File or paste')); ?></span>
						<span class="crm-import-tile__hint"><?php p($l->t('CSV or JSON from mail — or type names')); ?></span>
					</span>
				</label>
				<?php if (!empty($caps['sbdlicenseops'])): ?>
				<label class="crm-import-tile">
					<input type="radio" name="crm-import-source" value="sales_log" />
					<span class="crm-import-tile__text">
						<span class="crm-import-tile__title"><?php p($l->t('License sales')); ?></span>
						<span class="crm-import-tile__hint"><?php p($l->t('From the sales log in this Nextcloud')); ?></span>
					</span>
				</label>
				<?php endif; ?>
				<?php if (!empty($caps['projectcheck'])): ?>
				<label class="crm-import-tile">
					<input type="radio" name="crm-import-source" value="projectcheck" />
					<span class="crm-import-tile__text">
						<span class="crm-import-tile__title"><?php p($l->t('ProjectCheck')); ?></span>
						<span class="crm-import-tile__hint"><?php p($l->t('Customers already in ProjectCheck')); ?></span>
					</span>
				</label>
				<?php endif; ?>
				<?php if (!empty($caps['ticketcheck'])): ?>
				<label class="crm-import-tile">
					<input type="radio" name="crm-import-source" value="ticketcheck" />
					<span class="crm-import-tile__text">
						<span class="crm-import-tile__title"><?php p($l->t('TicketCheck')); ?></span>
						<span class="crm-import-tile__hint"><?php p($l->t('Customers already in TicketCheck')); ?></span>
					</span>
				</label>
				<?php endif; ?>
			</fieldset>

			<div id="crm-import-csv-wrap" class="crm-import-ingest">
				<label class="crm-import-drop" id="crm-import-drop" for="crm-import-file">
					<input
						type="file"
						id="crm-import-file"
						class="crm-sr-only"
						accept=".csv,.json,.txt,text/csv,application/json,text/plain"
					/>
					<span class="crm-import-drop__title"><?php p($l->t('Drop a CSV or JSON file here')); ?></span>
					<span class="crm-import-drop__hint" id="crm-import-drop-help"><?php p($l->t('Works with sales-log CSV and company JSON from mail. Or click to choose a file.')); ?></span>
					<span class="crm-import-drop__file" id="crm-import-file-name" hidden></span>
					<span class="crm-import-drop__cta"><?php p($l->t('Choose file')); ?></span>
				</label>

				<div class="crm-import-or" aria-hidden="true"><span><?php p($l->t('or')); ?></span></div>

				<div class="crm-import-paste">
					<label class="form-label" for="crm-import-csv"><?php p($l->t('Paste the file contents')); ?></label>
					<textarea
						id="crm-import-csv"
						class="crm-input form-input crm-input--lg crm-import-textarea"
						rows="10"
						spellcheck="false"
						placeholder="<?php p($l->t('Paste CSV or JSON here — or one company name per line.')); ?>"
						aria-describedby="crm-import-csv-help"
					></textarea>
					<p class="form-help" id="crm-import-csv-help"><?php p($l->t('A company name is enough. Extra columns and JSON exports are fine.')); ?></p>
				</div>
			</div>

			<div id="crm-import-link-default" class="crm-import-link-default" hidden>
				<p class="form-help" id="crm-import-link-h"><?php p($l->t('New companies will stay linked to ProjectCheck (recommended).')); ?></p>
				<details class="crm-advanced crm-import-advanced">
					<summary class="crm-advanced__summary"><?php p($l->t('Advanced options')); ?></summary>
					<div class="crm-advanced__body">
						<fieldset class="crm-form">
							<legend class="crm-sr-only"><?php p($l->t('When creating a new company from ProjectCheck')); ?></legend>
							<label class="crm-choice">
								<input type="radio" name="crm-import-link-sibling" value="1" checked />
								<?php p($l->t('Link to ProjectCheck customer (recommended)')); ?>
							</label>
							<label class="crm-choice">
								<input type="radio" name="crm-import-link-sibling" value="0" />
								<?php p($l->t('Create unlinked (separate CRM company)')); ?>
							</label>
							<p class="form-help"><?php p($l->t('Linking keeps one commercial identity across apps. Choose unlinked only if you intentionally want a separate CRM company.')); ?></p>
						</fieldset>
					</div>
				</details>
			</div>

			<div class="crm-form-actions crm-import-scan-actions">
				<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-import-scan"><?php p($l->t('Check my list')); ?></button>
			</div>
			<p id="crm-import-source-status" class="form-help crm-import-status" role="status" aria-live="polite"></p>
		</div>
	</section>

	<section class="crm-card" id="crm-import-review" hidden aria-labelledby="crm-import-review-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-import-review-title" class="crm-card__title"><?php p($l->t('Check before import')); ?></h2>
				<p class="crm-card__lead" id="crm-import-meta"><?php p($l->t('Ready rows can be imported in one tap.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<div id="crm-import-stats" class="crm-import-stats" aria-live="polite" hidden>
				<div class="crm-import-stat crm-import-stat--ready">
					<span class="crm-import-stat__value" id="crm-import-stat-ready">0</span>
					<span class="crm-import-stat__label"><?php p($l->t('Ready')); ?></span>
				</div>
				<div class="crm-import-stat crm-import-stat--choice">
					<span class="crm-import-stat__value" id="crm-import-stat-choice">0</span>
					<span class="crm-import-stat__label"><?php p($l->t('Need a pick')); ?></span>
				</div>
				<div class="crm-import-stat crm-import-stat--skip">
					<span class="crm-import-stat__value" id="crm-import-stat-skip">0</span>
					<span class="crm-import-stat__label"><?php p($l->t('Skip')); ?></span>
				</div>
			</div>

			<div class="crm-form-actions mb-4 crm-import-actions">
				<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-import-accept-unambiguous" disabled>
					<?php p($l->t('Import ready')); ?>
				</button>
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-import-apply" hidden>
					<?php p($l->t('Import selected')); ?>
				</button>
			</div>

			<div class="table-container crm-table--responsive">
				<table class="table table--hover" id="crm-import-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Company')); ?></th>
						<th scope="col"><?php p($l->t('What we will do')); ?></th>
						<th scope="col" class="crm-table-actions-col"><?php p($l->t('Include')); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>
			<div id="crm-import-errors" class="crm-callout crm-callout--warning" role="status" hidden></div>
			<p id="crm-import-empty" class="crm-muted" hidden><?php p($l->t('Nothing to import from this source yet.')); ?></p>
		</div>
	</section>

	<section class="crm-card" id="crm-import-summary" hidden aria-labelledby="crm-import-summary-title">
		<header class="crm-card__header"><h2 id="crm-import-summary-title" class="crm-card__title"><?php p($l->t('Done')); ?></h2></header>
		<div class="crm-card__body" id="crm-import-summary-body"></div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
