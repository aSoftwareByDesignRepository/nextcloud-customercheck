<?php
$mode = $_['mode'] ?? 'create';
$a = $_['agreement'] ?? null;
$companies = $_['companies'] ?? [];
/** @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels */
$labels = $_['labels'] ?? null;
$urlGenerator = $_['urlGenerator'];
$returnCompanyId = (int)($_['preselectCompanyId'] ?? ($a ? (int)$a->getCompanyId() : 0));
$cancelHref = $returnCompanyId > 0
	? $urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $returnCompanyId])
	: $urlGenerator->linkToRoute('customercheck.page.agreements');
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack">
	<section class="crm-card">
		<header class="crm-card__header">
			<h2 class="crm-card__title"><?php p($mode === 'edit' ? $l->t('Edit agreement') : $l->t('New agreement')); ?></h2>
			<p class="crm-card__lead"><?php p($l->t('License or partner package for one company.')); ?></p>
		</header>
		<div class="crm-card__body">
			<form id="crm-agreement-form" class="crm-form" data-mode="<?php p($mode); ?>" data-id="<?php p($a ? $a->getId() : ''); ?>" data-updated-at="<?php p($a && $a->getUpdatedAt() ? $a->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>" novalidate>
				<p class="crm-callout" role="status"><?php p($l->t('Company, label, and type are enough to start. Open More for dates and amounts.')); ?></p>
				<div class="form-group">
					<label class="form-label" for="crm-ag-company"><?php p($l->t('Company')); ?></label>
					<select id="crm-ag-company" name="companyId" class="crm-input form-select" required <?php if ($mode === 'edit'): ?>disabled<?php endif; ?>>
						<option value=""><?php p($l->t('Select…')); ?></option>
						<?php foreach ($companies as $c): ?>
							<option value="<?php p($c->getId()); ?>" <?php if (($a && (int)$a->getCompanyId() === (int)$c->getId()) || (!$a && $returnCompanyId === (int)$c->getId())): ?>selected<?php endif; ?>><?php p($c->getName()); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ag-label"><?php p($l->t('Label')); ?></label>
					<input id="crm-ag-label" name="label" class="crm-input form-input" required maxlength="200" value="<?php p($a ? $a->getLabel() : ''); ?>" autofocus />
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ag-kind"><?php p($l->t('Type')); ?></label>
					<select id="crm-ag-kind" name="kind" class="crm-input form-select">
						<?php foreach (['license','partner','onetime'] as $k): ?>
							<option value="<?php p($k); ?>" <?php if ($a && $a->getKind() === $k): ?>selected<?php endif; ?>><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->agreementKind($k) : $k);
							?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<details class="crm-advanced" id="crm-agreement-advanced"<?php if ($mode === 'edit'): ?> open<?php endif; ?>>
					<summary><?php p($l->t('More — product, dates, amount')); ?></summary>
				<div class="form-group">
					<label class="form-label" for="crm-ag-product"><?php p($l->t('Product')); ?></label>
					<select id="crm-ag-product" name="productCode" class="crm-input form-select" required>
						<?php
						$currentCode = $a ? (string)$a->getProductCode() : 'azc';
						$codes = $_['productCodes'] ?? [];
						if ($codes === []) {
							$codes = array_map(static fn (string $c): array => ['code' => $c, 'label' => $c, 'active' => true], \OCA\CustomerCheck\Constants::PRODUCT_CODES);
						}
						foreach ($codes as $row):
							if (empty($row['active']) && ($row['code'] ?? '') !== $currentCode) {
								continue;
							}
							$code = (string)($row['code'] ?? '');
							$label = (string)($row['label'] ?? $code);
							?>
							<option value="<?php p($code); ?>" <?php if ($code === $currentCode): ?>selected<?php endif; ?>>
								<?php p($label); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ag-starts"><?php p($l->t('Starts')); ?></label>
					<input id="crm-ag-starts" name="startsAt" type="date" class="crm-input form-input" value="<?php p($a && $a->getStartsAt() ? $a->getStartsAt()->format('Y-m-d') : ''); ?>" />
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ag-until"><?php p($l->t('Valid until')); ?></label>
					<input id="crm-ag-until" name="validUntil" type="date" class="crm-input form-input" value="<?php p($a && $a->getValidUntil() ? $a->getValidUntil()->format('Y-m-d') : ''); ?>" />
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ag-amount"><?php p($l->t('Amount (EUR)')); ?></label>
					<input id="crm-ag-amount" name="amountEur" type="number" min="0" step="0.01" class="crm-input form-input" value="<?php p($a ? number_format(((int)$a->getAmountCents())/100, 2, '.', '') : '0'); ?>" />
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-ag-invoice-status"><?php p($l->t('Invoice status')); ?></label>
					<select id="crm-ag-invoice-status" name="invoiceStatus" class="crm-input form-select">
						<?php foreach (['none','sent','paid','overdue'] as $st): ?>
							<option value="<?php p($st); ?>" <?php if ($a && $a->getInvoiceStatus() === $st): ?>selected<?php endif; ?>><?php
								p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->invoiceStatus($st) : $st);
							?></option>
						<?php endforeach; ?>
					</select>
				</div>
				</details>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg"><?php p($l->t('Save')); ?></button>
					<a class="crm-btn crm-btn--secondary" href="<?php p($cancelHref); ?>"><?php p($l->t('Back')); ?></a>
				</div>
				<div id="crm-agreement-form-error" class="crm-callout crm-callout--danger" role="alert" hidden></div>
			</form>
			<?php
			$agStatus = $a ? (string)$a->getStatus() : '';
			$canLifecycle = $mode === 'edit' && in_array($agStatus, ['draft', 'active', 'expiring', 'expired'], true);
			if ($canLifecycle):
			?>
			<div class="crm-inline-actions mt-4">
				<button type="button" class="crm-btn crm-btn--primary" id="crm-agreement-renew" data-id="<?php p($a->getId()); ?>" data-updated-at="<?php p($a->getUpdatedAt() ? $a->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>" data-valid-until="<?php p($a->getValidUntil() ? $a->getValidUntil()->format('Y-m-d') : ''); ?>" data-amount-cents="<?php p((int)$a->getAmountCents()); ?>" data-label="<?php p($a->getLabel()); ?>"><?php p($l->t('Renew')); ?></button>
				<button type="button" class="crm-btn crm-btn--danger" id="crm-agreement-cancel" data-id="<?php p($a->getId()); ?>" data-updated-at="<?php p($a->getUpdatedAt() ? $a->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>"><?php p($l->t('Cancel agreement')); ?></button>
			</div>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
