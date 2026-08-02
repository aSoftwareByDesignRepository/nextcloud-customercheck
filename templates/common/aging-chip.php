<?php
/**
 * Aging chip for InvoiceCheck facade v3 — text + badge (not colour-only).
 * Expects optional $agingBucket (string|null) and $daysPastDue (int|null).
 * Renders nothing when agingBucket is missing (feature-detect / null-safe).
 *
 * @var \OCP\IL10N $l
 * @var \OCA\CustomerCheck\Service\DisplayLabelService|null $labels
 * @var string|null $agingBucket
 * @var int|null $daysPastDue
 */
$agingLabel = null;
if ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
	$agingLabel = $labels->agingBucket(is_string($agingBucket ?? null) ? $agingBucket : null);
} elseif (isset($agingBucket) && is_string($agingBucket) && trim($agingBucket) !== '') {
	$agingLabel = trim($agingBucket);
}
if ($agingLabel === null || $agingLabel === '') {
	return;
}
$daysLabel = null;
if ($labels instanceof \OCA\CustomerCheck\Service\DisplayLabelService) {
	$daysLabel = $labels->daysPastDueLabel(isset($daysPastDue) && is_int($daysPastDue) ? $daysPastDue : null);
} elseif (isset($daysPastDue) && is_int($daysPastDue)) {
	$daysLabel = $l->n('%n day past due', '%n days past due', $daysPastDue);
}
?>
<span class="crm-badge crm-badge--neutral crm-aging-chip" data-crm-aging-bucket="<?php p(is_string($agingBucket ?? null) ? $agingBucket : ''); ?>"<?php if (isset($daysPastDue) && is_int($daysPastDue)): ?> data-crm-days-past-due="<?php p((string)$daysPastDue); ?>"<?php endif; ?>>
	<?php p($agingLabel); ?><?php if ($daysLabel !== null && $daysLabel !== ''): ?> · <?php p($daysLabel); ?><?php endif; ?>
</span>
