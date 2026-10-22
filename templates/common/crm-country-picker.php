<?php
/**
 * Country combobox — full ISO 3166-1 (+XK) with localized labels, searchable.
 * WAI-ARIA combobox per design system §3.6.7 (>20 options ⇒ not a <select>).
 *
 * Wired by js/common/country-picker.js (CustomerCheckCountryPicker).
 *
 * Expects in scope:
 *   $l — IL10N
 *   $countryPickerId — base id (e.g. 'crm-company-country')
 *   $countryPickerValue — current ISO code ('' allowed)
 *   $countryPickerField — server `fields` key for inline errors (default 'country')
 *   $countryPickerName — hidden input name (default: same as $countryPickerField)
 */
/** @var \OCP\IL10N $l */
$countryPickerId = (string)($countryPickerId ?? 'crm-country');
$countryPickerValue = strtoupper(trim((string)($countryPickerValue ?? '')));
$countryPickerField = (string)($countryPickerField ?? 'country');
$countryPickerName = (string)($countryPickerName ?? $countryPickerField);
$countryPickerLang = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$countryPickerOptions = \OCA\CustomerCheck\Support\CountryDirectory::options($countryPickerLang);
$countryPickerLabel = $countryPickerValue !== ''
	? \OCA\CustomerCheck\Support\CountryDirectory::label($countryPickerValue, $countryPickerLang)
	: '';
?>
<div class="user-picker crm-country-picker" data-crm-country-picker>
	<div class="user-picker__control">
		<input type="search" id="<?php p($countryPickerId); ?>-search"
			class="form-input crm-input user-picker__search"
			role="combobox" aria-autocomplete="list" aria-expanded="false"
			aria-controls="<?php p($countryPickerId); ?>-list"
			autocomplete="off" spellcheck="false"
			data-field="<?php p($countryPickerField); ?>"
			data-crm-country-search
			placeholder="<?php p($l->t('Type to search countries')); ?>"
			value="<?php p($countryPickerLabel); ?>" />
		<button type="button" class="user-picker__clear" id="<?php p($countryPickerId); ?>-clear"
			data-crm-country-clear
			aria-label="<?php p($l->t('Clear country selection')); ?>" hidden>×</button>
	</div>
	<input type="hidden" id="<?php p($countryPickerId); ?>" name="<?php p($countryPickerName); ?>"
		value="<?php p($countryPickerValue); ?>" data-crm-country-value />
	<ul id="<?php p($countryPickerId); ?>-list" class="user-picker__list" role="listbox"
		aria-label="<?php p($l->t('Countries')); ?>" data-crm-country-list hidden>
		<?php foreach ($countryPickerOptions as $countryPickerCode => $countryPickerName_): ?>
			<li class="user-picker__item" role="option"
				id="<?php p($countryPickerId); ?>-opt-<?php p($countryPickerCode); ?>"
				data-code="<?php p($countryPickerCode); ?>"
				aria-selected="<?php p($countryPickerValue === $countryPickerCode ? 'true' : 'false'); ?>">
				<span class="user-picker__name"><?php p($countryPickerName_); ?></span>
				<span class="crm-country-picker__code" aria-hidden="true"><?php p($countryPickerCode); ?></span>
			</li>
		<?php endforeach; ?>
		<?php if ($countryPickerValue !== '' && !isset($countryPickerOptions[$countryPickerValue])): ?>
			<li class="user-picker__item" role="option"
				id="<?php p($countryPickerId); ?>-opt-<?php p($countryPickerValue); ?>"
				data-code="<?php p($countryPickerValue); ?>" aria-selected="true">
				<span class="user-picker__name"><?php p($countryPickerValue); ?></span>
				<span class="crm-country-picker__code" aria-hidden="true"><?php p($countryPickerValue); ?></span>
			</li>
		<?php endif; ?>
	</ul>
</div>
