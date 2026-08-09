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
$companyName = '';
if ($a !== null) {
	foreach ($companies as $c) {
		if ((int)$c->getId() === (int)$a->getCompanyId()) {
			$companyName = (string)$c->getName();
			break;
		}
	}
}
$agStatus = $a ? (string)$a->getStatus() : '';
$statusBadge = match ($agStatus) {
	'active' => 'success',
	'expiring' => 'warning',
	'expired', 'cancelled' => 'danger',
	'draft' => 'neutral',
	default => 'info',
};
$statusLabel = ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService && $agStatus !== '')
	? $labels->agreementStatus($agStatus)
	: $agStatus;
$isTerminal = $mode === 'edit' && in_array($agStatus, \OCA\CustomerCheck\Constants::AGREEMENT_TERMINAL_STATUSES, true);
$canLifecycle = $mode === 'edit' && !$isTerminal && in_array($agStatus, ['draft', 'active', 'expiring', 'expired'], true);
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack">
	<section class="crm-card crm-ag-form" aria-labelledby="crm-ag-form-title">
		<header class="crm-card__header">
			<div class="crm-card__header-text">
				<h2 id="crm-ag-form-title" class="crm-card__title"><?php p($mode === 'edit' ? $l->t('Edit agreement') : $l->t('New agreement')); ?></h2>
				<p class="crm-card__lead"><?php
					if ($isTerminal) {
						p($l->t('This agreement is closed. Create a new agreement instead of editing it.'));
					} elseif ($mode === 'edit') {
						p($l->t('Change the name, end date, or amount. Renew extends it one year.'));
					} else {
						p($l->t('Name, end date, and amount are enough. Open More only if you need type or product.'));
					}
				?></p>
			</div>
			<?php if ($mode === 'edit' && $statusLabel !== ''): ?>
				<span class="crm-badge crm-badge--<?php p($statusBadge); ?>" aria-label="<?php p($l->t('Status')); ?>"><?php p($statusLabel); ?></span>
			<?php endif; ?>
		</header>
		<div class="crm-card__body">
			<?php if ($isTerminal): ?>
				<div class="crm-callout crm-callout--warning" role="status">
					<p><?php p($l->t('Closed agreements are look-only. Renew from an active agreement, or start a new one for this company.')); ?></p>
					<div class="crm-form-actions crm-ag-terminal-actions">
						<a class="crm-btn crm-btn--primary crm-btn--lg" href="<?php p($urlGenerator->linkToRoute('customercheck.page.agreementNew')); ?>?companyId=<?php p((int)$returnCompanyId); ?>"><?php p($l->t('New agreement')); ?></a>
						<a class="crm-btn crm-btn--secondary crm-btn--lg" href="<?php p($cancelHref); ?>"><?php p($l->t('Back')); ?></a>
					</div>
				</div>
			<?php endif; ?>
			<form id="crm-agreement-form" class="crm-form" data-mode="<?php p($mode); ?>" data-id="<?php p($a ? $a->getId() : ''); ?>" data-updated-at="<?php p($a && $a->getUpdatedAt() ? $a->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>" data-status="<?php p($agStatus); ?>" data-terminal="<?php p($isTerminal ? '1' : '0'); ?>" novalidate<?php if ($isTerminal): ?> aria-disabled="true"<?php endif; ?>>
				<div class="crm-ag-essentials" role="group" aria-labelledby="crm-ag-essentials-label">
					<p id="crm-ag-essentials-label" class="crm-ag-zone-label"><?php p($l->t('What matters')); ?></p>

					<?php if ($mode === 'edit'): ?>
						<input type="hidden" id="crm-ag-company" name="companyId" value="<?php p($a ? (int)$a->getCompanyId() : 0); ?>" />
						<div class="form-group">
							<span class="form-label" id="crm-ag-company-label"><?php p($l->t('Company')); ?></span>
							<p class="crm-ag-company-line" aria-labelledby="crm-ag-company-label">
								<a class="crm-ag-company-link" href="<?php p($cancelHref); ?>"><?php p($companyName !== '' ? $companyName : $l->t('Open company')); ?></a>
							</p>
						</div>
					<?php else: ?>
						<div class="form-group">
							<label class="form-label" for="crm-ag-company"><?php p($l->t('Company')); ?></label>
							<select id="crm-ag-company" name="companyId" class="crm-input form-select crm-input--lg" required>
								<option value=""><?php p($l->t('Select…')); ?></option>
								<?php foreach ($companies as $c): ?>
									<option value="<?php p($c->getId()); ?>" <?php if ($returnCompanyId === (int)$c->getId()): ?>selected<?php endif; ?>><?php p($c->getName()); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<div class="form-group">
						<label class="form-label" for="crm-ag-label"><?php p($l->t('Label')); ?></label>
						<input id="crm-ag-label" name="label" class="crm-input form-input crm-input--lg" required maxlength="200" value="<?php p($a ? $a->getLabel() : ''); ?>" autofocus autocomplete="off" <?php if ($isTerminal): ?>readonly<?php endif; ?> />
						<p class="form-help"><?php p($l->t('A short name you will recognize later.')); ?></p>
					</div>

					<div class="crm-ag-money-row">
						<div class="form-group">
							<label class="form-label" for="crm-ag-until"><?php p($l->t('Valid until')); ?></label>
							<input id="crm-ag-until" name="validUntil" type="date" class="crm-input form-input crm-input--lg" value="<?php p($a && $a->getValidUntil() ? $a->getValidUntil()->format('Y-m-d') : ''); ?>" <?php if ($isTerminal): ?>readonly<?php endif; ?> />
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ag-amount"><?php p($l->t('Amount (EUR)')); ?></label>
							<input id="crm-ag-amount" name="amountEur" type="number" min="0" step="0.01" inputmode="decimal" class="crm-input form-input crm-input--lg" value="<?php p($a ? number_format(((int)$a->getAmountCents()) / 100, 2, '.', '') : '0'); ?>" <?php if ($isTerminal): ?>readonly<?php endif; ?> />
						</div>
					</div>
				</div>

				<details class="crm-advanced" id="crm-agreement-advanced">
					<summary class="crm-advanced__summary"><?php p($l->t('More — type, product, start, invoice')); ?></summary>
					<div class="crm-advanced__body">
						<div class="form-group">
							<label class="form-label" for="crm-ag-kind"><?php p($l->t('Type')); ?></label>
							<select id="crm-ag-kind" name="kind" class="crm-input form-select" <?php if ($isTerminal): ?>disabled<?php endif; ?>>
								<?php foreach (['license', 'partner', 'onetime'] as $k): ?>
									<option value="<?php p($k); ?>" <?php if ($a && $a->getKind() === $k): ?>selected<?php endif; ?>><?php
										p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->agreementKind($k) : $k);
									?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ag-product"><?php p($l->t('Product')); ?></label>
							<select id="crm-ag-product" name="productCode" class="crm-input form-select" required <?php if ($isTerminal): ?>disabled<?php endif; ?>>
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
							<input id="crm-ag-starts" name="startsAt" type="date" class="crm-input form-input" value="<?php
								if ($a && $a->getStartsAt()) {
									p($a->getStartsAt()->format('Y-m-d'));
								} elseif ($mode === 'create') {
									p((new \DateTimeImmutable('today'))->format('Y-m-d'));
								}
							?>" <?php if ($isTerminal): ?>readonly<?php endif; ?> />
							<?php if ($mode === 'create'): ?>
								<p class="form-help"><?php p($l->t('Defaults to today — change only if needed.')); ?></p>
							<?php endif; ?>
						</div>
						<div class="form-group">
							<label class="form-label" for="crm-ag-invoice-status"><?php p($l->t('Invoice status')); ?></label>
							<select id="crm-ag-invoice-status" name="invoiceStatus" class="crm-input form-select" <?php if ($isTerminal): ?>disabled<?php endif; ?>>
								<?php foreach (['none', 'sent', 'paid', 'overdue'] as $st): ?>
									<option value="<?php p($st); ?>" <?php if ($a && $a->getInvoiceStatus() === $st): ?>selected<?php endif; ?>><?php
										p($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService ? $labels->invoiceStatus($st) : $st);
									?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</details>

				<div id="crm-agreement-form-error" class="crm-callout crm-callout--danger" role="alert" hidden></div>

				<?php if (!$isTerminal): ?>
				<div class="crm-form-actions">
					<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg"><?php p($l->t('Save')); ?></button>
					<a class="crm-btn crm-btn--secondary crm-btn--lg" href="<?php p($cancelHref); ?>"><?php p($l->t('Back')); ?></a>
				</div>
				<?php endif; ?>
			</form>

			<?php if ($canLifecycle): ?>
			<section class="crm-ag-lifecycle" aria-labelledby="crm-ag-keep-title">
				<h3 id="crm-ag-keep-title" class="crm-ag-lifecycle__title"><?php p($l->t('Keep going')); ?></h3>
				<p class="crm-ag-lifecycle__lead"><?php p($l->t('Renew creates a new agreement one year out and closes this one. Money and reminders update.')); ?></p>
				<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-agreement-renew" data-id="<?php p($a->getId()); ?>" data-updated-at="<?php p($a->getUpdatedAt() ? $a->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>" data-valid-until="<?php p($a->getValidUntil() ? $a->getValidUntil()->format('Y-m-d') : ''); ?>" data-amount-cents="<?php p((int)$a->getAmountCents()); ?>" data-label="<?php p($a->getLabel()); ?>"><?php p($l->t('Renew for one year')); ?></button>
			</section>

			<details class="crm-advanced crm-ag-end" id="crm-agreement-end">
				<summary class="crm-advanced__summary"><?php p($l->t('End this agreement')); ?></summary>
				<div class="crm-advanced__body">
					<p class="form-help"><?php p($l->t('Stops renewals. You will be asked for a short reason.')); ?></p>
					<button type="button" class="crm-btn crm-btn--danger crm-btn--lg" id="crm-agreement-cancel" data-id="<?php p($a->getId()); ?>" data-updated-at="<?php p($a->getUpdatedAt() ? $a->getUpdatedAt()->format(\DateTimeInterface::ATOM) : ''); ?>"><?php p($l->t('Cancel agreement')); ?></button>
				</div>
			</details>
			<?php endif; ?>
		</div>
	</section>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
