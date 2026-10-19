<?php
/**
 * Postal address fields — used on company create/edit and inline 360 edit.
 *
 * Expects in scope:
 *   $l — IL10N
 *   $crmAddrPrefix — id/name prefix (e.g. crm-company or crm-address)
 *   $crmAddrCompany — \OCA\CustomerCheck\Db\Company|null
 */
/** @var \OCP\IL10N $l */
$crmAddrPrefix = (string)($crmAddrPrefix ?? 'crm-company');
$crmAddrCompany = $crmAddrCompany ?? null;
$crmAddrStreet = $crmAddrCompany ? (string)$crmAddrCompany->getAddrStreet() : '';
$crmAddrZip = $crmAddrCompany ? (string)$crmAddrCompany->getAddrZip() : '';
$crmAddrCity = $crmAddrCompany ? (string)$crmAddrCompany->getAddrCity() : '';
$crmAddrCountry = strtoupper(trim((string)($crmAddrCompany ? $crmAddrCompany->getCountry() : 'DE')));
if ($crmAddrCountry === '') {
	$crmAddrCountry = 'DE';
}
$crmAddrCountryOptions = [
	'DE' => $l->t('Germany'),
	'AT' => $l->t('Austria'),
	'CH' => $l->t('Switzerland'),
	'LI' => $l->t('Liechtenstein'),
	'LU' => $l->t('Luxembourg'),
	'NL' => $l->t('Netherlands'),
	'BE' => $l->t('Belgium'),
	'FR' => $l->t('France'),
	'IT' => $l->t('Italy'),
	'ES' => $l->t('Spain'),
	'PT' => $l->t('Portugal'),
	'GR' => $l->t('Greece'),
	'MT' => $l->t('Malta'),
	'CY' => $l->t('Cyprus'),
	'PL' => $l->t('Poland'),
	'CZ' => $l->t('Czechia'),
	'SK' => $l->t('Slovakia'),
	'HU' => $l->t('Hungary'),
	'SI' => $l->t('Slovenia'),
	'HR' => $l->t('Croatia'),
	'RO' => $l->t('Romania'),
	'BG' => $l->t('Bulgaria'),
	'EE' => $l->t('Estonia'),
	'LV' => $l->t('Latvia'),
	'LT' => $l->t('Lithuania'),
	'DK' => $l->t('Denmark'),
	'SE' => $l->t('Sweden'),
	'NO' => $l->t('Norway'),
	'FI' => $l->t('Finland'),
	'GB' => $l->t('United Kingdom'),
	'IE' => $l->t('Ireland'),
	'US' => $l->t('United States'),
];
?>
<fieldset class="crm-company-address" id="<?php p($crmAddrPrefix); ?>-address">
	<legend class="crm-company-address__legend"><?php p($l->t('Postal address')); ?></legend>
	<p class="form-help" id="<?php p($crmAddrPrefix); ?>-address-help"><?php p($l->t('Street, postcode, city and country for invoices and letters.')); ?></p>
	<div class="form-group">
		<label class="form-label" for="<?php p($crmAddrPrefix); ?>-street"><?php p($l->t('Street and number')); ?></label>
		<input class="crm-input form-input crm-input--lg" id="<?php p($crmAddrPrefix); ?>-street" name="addrStreet" maxlength="255" value="<?php p($crmAddrStreet); ?>" autocomplete="street-address" aria-describedby="<?php p($crmAddrPrefix); ?>-address-help" />
	</div>
	<div class="crm-form-row-2">
		<div class="form-group">
			<label class="form-label" for="<?php p($crmAddrPrefix); ?>-zip"><?php p($l->t('Postcode')); ?></label>
			<input class="crm-input form-input crm-input--lg" id="<?php p($crmAddrPrefix); ?>-zip" name="addrZip" maxlength="16" value="<?php p($crmAddrZip); ?>" autocomplete="postal-code" inputmode="numeric" />
		</div>
		<div class="form-group">
			<label class="form-label" for="<?php p($crmAddrPrefix); ?>-city"><?php p($l->t('City')); ?></label>
			<input class="crm-input form-input crm-input--lg" id="<?php p($crmAddrPrefix); ?>-city" name="addrCity" maxlength="128" value="<?php p($crmAddrCity); ?>" autocomplete="address-level2" />
		</div>
	</div>
	<div class="form-group">
		<label class="form-label" for="<?php p($crmAddrPrefix); ?>-country"><?php p($l->t('Country')); ?></label>
		<select class="crm-input form-select crm-input--lg" id="<?php p($crmAddrPrefix); ?>-country" name="country" autocomplete="country">
			<?php foreach ($crmAddrCountryOptions as $code => $label): ?>
				<option value="<?php p($code); ?>" <?php if ($crmAddrCountry === $code): ?>selected<?php endif; ?>><?php p($label); ?> (<?php p($code); ?>)</option>
			<?php endforeach; ?>
			<?php if (!isset($crmAddrCountryOptions[$crmAddrCountry])): ?>
				<option value="<?php p($crmAddrCountry); ?>" selected><?php p($crmAddrCountry); ?></option>
			<?php endif; ?>
		</select>
	</div>
</fieldset>
