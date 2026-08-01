<?php

declare(strict_types=1);

/**
 * Accessible company cell link for list tables.
 *
 * Variables:
 * - int|string|null $crmCompanyLinkId
 * - string|null $crmCompanyLinkLabel
 * - \OCP\IURLGenerator $urlGenerator
 * - \OCP\IL10N $l
 */
$crmCompanyLinkId = (int)($crmCompanyLinkId ?? 0);
$crmCompanyLinkLabel = trim((string)($crmCompanyLinkLabel ?? ''));
if ($crmCompanyLinkLabel === '') {
	$crmCompanyLinkLabel = $l->t('Unknown company');
}
if ($crmCompanyLinkId > 0) {
	?><a class="crm-table-link" href="<?php p($urlGenerator->linkToRoute('customercheck.page.companyDetail', ['id' => $crmCompanyLinkId])); ?>"><?php p($crmCompanyLinkLabel); ?></a><?php
} else {
	p($crmCompanyLinkLabel);
}
