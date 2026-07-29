<?php
$caps = $_['capabilities'] ?? [];
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-import-root">
	<section class="crm-card" id="crm-import-source" aria-labelledby="crm-import-source-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-import-source-title" class="crm-card__title"><?php p($l->t('Choose a source')); ?></h2>
				<p class="crm-card__lead"><?php p($l->t('Scan first. Nothing is written until you accept proposals.')); ?></p>
			</div>
		</header>
		<div class="crm-card__body">
			<fieldset class="crm-form">
				<legend class="crm-sr-only"><?php p($l->t('Import source')); ?></legend>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="csv" checked /> <?php p($l->t('CSV file')); ?></label>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="sales_log" <?php if (empty($caps['sbdlicenseops'])): ?>disabled<?php endif; ?> /> <?php p($l->t('License sales log')); ?><?php if (empty($caps['sbdlicenseops'])): ?> — <?php p($l->t('not available')); ?><?php endif; ?></label>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="projectcheck" <?php if (empty($caps['projectcheck'])): ?>disabled<?php endif; ?> /> <?php p($l->t('ProjectCheck customers')); ?><?php if (empty($caps['projectcheck'])): ?> — <?php p($l->t('not available')); ?><?php endif; ?></label>
				<label class="crm-choice"><input type="radio" name="crm-import-source" value="ticketcheck" <?php if (empty($caps['ticketcheck'])): ?>disabled<?php endif; ?> /> <?php p($l->t('TicketCheck customers')); ?><?php if (empty($caps['ticketcheck'])): ?> — <?php p($l->t('not available')); ?><?php endif; ?></label>
			</fieldset>
			<div id="crm-import-csv-wrap" class="form-group mt-4">
				<label class="form-label" for="crm-import-csv"><?php p($l->t('Paste CSV')); ?></label>
				<textarea id="crm-import-csv" class="crm-input form-input" rows="8" spellcheck="false" placeholder="customerId,companyName,contactName,email,productCode,mobileSeats,terminalDevices,validUntil,invoiceId,amountEur,paidAt,keyIssuedAt,renewalDue,notes"></textarea>
				<p class="form-help"><?php p($l->t('Optional column: productCode. Leave it empty to infer a neutral default, or add the code to the agreement catalogue first.')); ?></p>
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
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-import-accept-unambiguous"><?php p($l->t('Accept all unambiguous')); ?></button>
				<button type="button" class="crm-btn crm-btn--primary" id="crm-import-apply"><?php p($l->t('Apply selected')); ?></button>
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
