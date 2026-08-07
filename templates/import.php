<?php
$caps = $_['capabilities'] ?? [];
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-import-root">
	<section class="crm-card" id="crm-import-source" aria-labelledby="crm-import-source-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-import-source-title" class="crm-card__title"><?php p($l->t('Choose a source')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Nothing is written until you accept.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<fieldset class="crm-form">
				<legend class="crm-sr-only"><?php p($l->t('Import source')); ?></legend>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="csv" checked /> <?php p($l->t('CSV file')); ?></label>
				<?php if (!empty($caps['sbdlicenseops'])): ?>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="sales_log" /> <?php p($l->t('License sales log')); ?></label>
				<?php endif; ?>
				<?php if (!empty($caps['projectcheck'])): ?>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="projectcheck" /> <?php p($l->t('ProjectCheck customers')); ?></label>
				<?php endif; ?>
				<?php if (!empty($caps['ticketcheck'])): ?>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="ticketcheck" /> <?php p($l->t('TicketCheck customers')); ?></label>
				<?php endif; ?>
			</fieldset>
			<div id="crm-import-csv-wrap" class="form-group mt-4">
				<label class="form-label" for="crm-import-csv"><?php p($l->t('Paste CSV')); ?></label>
				<textarea id="crm-import-csv" class="crm-input form-input" rows="8" spellcheck="false" placeholder="<?php p($l->t('Paste rows here. First line can be column names.')); ?>"></textarea>
				<p class="form-help"><?php p($l->t('Company name is enough to start. Product codes and seats are optional.')); ?></p>
			</div>
			<div id="crm-import-link-default" class="form-group mt-4" hidden>
				<fieldset class="crm-form">
					<legend id="crm-import-link-h"><?php p($l->t('When creating a new company from ProjectCheck')); ?></legend>
					<label class="crm-choice">
						<input type="radio" name="crm-import-link-sibling" value="1" checked />
						<?php p($l->t('Link to ProjectCheck customer (recommended)')); ?>
					</label>
					<details class="crm-import-advanced mt-2">
						<summary><?php p($l->t('Advanced options')); ?></summary>
						<label class="crm-choice">
							<input type="radio" name="crm-import-link-sibling" value="0" />
							<?php p($l->t('Create unlinked (separate CRM company)')); ?>
						</label>
						<p class="form-help"><?php p($l->t('Linking keeps one commercial identity across apps. Choose unlinked only if you intentionally want a separate CRM company.')); ?></p>
					</details>
				</fieldset>
			</div>
			<div class="crm-form-actions">
				<button type="button" class="crm-btn crm-btn--primary" id="crm-import-scan"><?php p($l->t('Scan')); ?></button>
			</div>
		</div>
	</section>

	<section class="crm-card" id="crm-import-review" hidden aria-labelledby="crm-import-review-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-import-review-title" class="crm-card__title"><?php p($l->t('Review proposals')); ?></h2>
				<p class="crm-card__lead" id="crm-import-meta"></p>
			</div>
		</header>
		<div class="crm-card__body">
			<div class="crm-form-actions mb-4">
				<button type="button" class="crm-btn crm-btn--primary" id="crm-import-accept-unambiguous"><?php p($l->t('Import clear rows')); ?></button>
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-import-apply"><?php p($l->t('Apply selected')); ?></button>
			</div>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover" id="crm-import-table">
					<thead><tr>
						<th scope="col"><span class="crm-sr-only"><?php p($l->t('Include')); ?></span></th>
						<th scope="col"><?php p($l->t('Company')); ?></th>
						<th scope="col"><?php p($l->t('Action')); ?></th>
						<th scope="col"><?php p($l->t('Match')); ?></th>
						<th scope="col"><?php p($l->t('Decision')); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>
			<div id="crm-import-errors" class="crm-callout crm-callout--warning" role="status" hidden></div>
		</div>
	</section>

	<section class="crm-card" id="crm-import-summary" hidden aria-labelledby="crm-import-summary-title">
		<header class="crm-card__header"><h2 id="crm-import-summary-title" class="crm-card__title"><?php p($l->t('Import summary')); ?></h2></header>
		<div class="crm-card__body" id="crm-import-summary-body"></div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
