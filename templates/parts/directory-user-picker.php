<?php
/**
 * Directory user picker fragment (ACCESS-AND-DIRECTORY-PICKERS).
 *
 * Expects: $crmPickerId (slug), $crmPickerLabel, $crmPickerHelp (optional), $l
 *
 * @var \OCP\IL10N $l
 * @var string $crmPickerId
 * @var string $crmPickerLabel
 * @var string $crmPickerHelp
 */
$crmPickerId = preg_replace('/[^a-z0-9-]/', '', (string)($crmPickerId ?? '')) ?: 'user';
$crmPickerLabel = (string)($crmPickerLabel ?? $l->t('Colleague'));
$crmPickerHelp = (string)($crmPickerHelp ?? $l->t('Never type a raw user id — search and pick.'));
$searchId = 'crm-' . $crmPickerId . '-search';
$suggestId = 'crm-' . $crmPickerId . '-suggest';
$hiddenId = 'crm-' . $crmPickerId . '-uid';
$clearId = 'crm-' . $crmPickerId . '-clear';
$pickerWrapId = 'crm-' . $crmPickerId . '-picker';
?>
<div class="form-group">
	<label class="form-label" for="<?php p($searchId); ?>"><?php p($crmPickerLabel); ?></label>
	<div class="user-picker" id="<?php p($pickerWrapId); ?>">
		<div class="user-picker__control">
			<input type="search" id="<?php p($searchId); ?>" class="form-input crm-input user-picker__search"
				role="combobox" aria-autocomplete="list" aria-expanded="false"
				aria-controls="<?php p($suggestId); ?>"
				autocomplete="off"
				placeholder="<?php p($l->t('Search by name…')); ?>">
			<button type="button" class="user-picker__clear" id="<?php p($clearId); ?>" hidden
				aria-label="<?php p($l->t('Clear selection')); ?>">&times;</button>
			<input type="hidden" id="<?php p($hiddenId); ?>" value="">
		</div>
		<ul id="<?php p($suggestId); ?>" class="user-picker__list" role="listbox" hidden></ul>
	</div>
	<p class="form-help"><?php p($crmPickerHelp); ?></p>
</div>
