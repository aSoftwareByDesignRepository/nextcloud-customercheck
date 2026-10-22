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
		<label class="form-label" for="<?php p($crmAddrPrefix); ?>-country-search"><?php p($l->t('Country')); ?></label>
		<?php
		$countryPickerId = $crmAddrPrefix . '-country';
		$countryPickerValue = $crmAddrCountry;
		$countryPickerField = 'country';
		$countryPickerName = 'country';
		include __DIR__ . '/crm-country-picker.php';
		?>
	</div>
</fieldset>
