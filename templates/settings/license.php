<?php

declare(strict_types=1);

/**
 * CustomerCheck Settings → License (CUC2 mobile seats).
 *
 * The license UX is the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * This wrapper only adapts CustomerCheck's service shape to the shared
 * contract — do not put markup here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$l = $l ?? (isset($_['l']) ? $_['l'] : null);
if ($l === null) {
	try {
		$l = \OCP\Util::getL10N('customercheck');
	} catch (\Throwable) {
		$l = new class { public function t($s, $p = []) { return $s; } };
	}
}

$licenseStatus = is_array($_['license'] ?? null) ? $_['license'] : null;
$licenseState = is_array($licenseStatus['state'] ?? null) ? $licenseStatus['state'] : null;
$licenseSeatsCounts = is_array($licenseStatus['seats'] ?? null) ? $licenseStatus['seats'] : ['assigned' => 0, 'limit' => 0];

$licenseSeatsPayload = is_array($_['licenseSeats'] ?? null) ? $_['licenseSeats'] : [];
$seatRowsRaw = is_array($licenseSeatsPayload['data'] ?? null) ? $licenseSeatsPayload['data'] : [];

$seatRows = [];
foreach ($seatRowsRaw as $row) {
	if (!is_array($row)) {
		continue;
	}
	$uid = (string)($row['uid'] ?? $row['userId'] ?? $row['id'] ?? '');
	if ($uid === '') {
		continue;
	}
	$assignedAt = $row['assignedAt'] ?? '';
	$seatRows[] = [
		'userId' => $uid,
		'displayName' => (string)($row['displayName'] ?? $uid),
		'assignedAt' => is_int($assignedAt) ? date('c', $assignedAt) : (string)$assignedAt,
	];
}

$license = $licenseState === null ? null : [
	'active' => !empty($licenseState['valid']),
	'cryptographicallyValid' => true,
	'dateValid' => (string)($licenseState['validUntil'] ?? '') !== '',
	'validUntil' => (string)($licenseState['validUntil'] ?? ''),
	'customerId' => (string)($licenseState['customerId'] ?? ''),
];

$mobileLimit = (int)($licenseSeatsCounts['limit'] ?? ($licenseState['mobileSeats'] ?? 0));
$urlGenerator = $_['urlGenerator'] ?? null;
if ($urlGenerator === null) {
	try {
		$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
	} catch (\Throwable) {
		$urlGenerator = null;
	}
}

$_['license'] = $license;
$_['productCode'] = 'CUC2';
$_['companionText'] = $l->t('the mobile companion app');
$_['seatAppName'] = $l->t('the CustomerCheck mobile companion app');
$_['mobileSeatsUsed'] = (int)($licenseSeatsCounts['assigned'] ?? 0);
$_['mobileSeatsLimit'] = $mobileLimit;
$_['mobileSeats'] = $seatRows;
$_['showMobileSeats'] = $mobileLimit > 0;
$_['apiLicenseUrl'] = ($urlGenerator ? $urlGenerator->linkToRoute('customercheck.license.apply') : '');
$_['apiClearLicenseUrl'] = ($urlGenerator ? $urlGenerator->linkToRoute('customercheck.license.remove') : '');
$_['apiSeatsUrl'] = ($urlGenerator ? $urlGenerator->linkToRoute('customercheck.license.seats') : '');
$_['apiSeatsBatchUrl'] = '';
$_['apiRemoveSeatUrl'] = ($urlGenerator ? $urlGenerator->linkToRoute('customercheck.license.removeSeatPost') : '');
$_['apiSearchUsersUrl'] = ($urlGenerator ? $urlGenerator->linkToRoute('customercheck.settings_api.searchUsers') : '');
$_['licenseRenewMailto'] = 'mailto:info@software-by-design.de?subject=' . rawurlencode('CustomerCheck mobile license');
$_['productsUrl'] = 'https://nextcloud.software-by-design.de/';
try {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? \OCP\Util::callRegister());
} catch (\Throwable) {
	$_['requesttoken'] = (string)($_['requesttoken'] ?? '');
}

try {
	\OCP\Util::addScript('customercheck', 'common/license-page');
	\OCP\Util::addStyle('customercheck', 'common/license-page');
} catch (\Throwable) {
}

// Keep the legacy deep-link anchor (#section-license) working.
echo '<div id="section-license">';
include __DIR__ . '/../common/license-page.php';
echo '</div>';
