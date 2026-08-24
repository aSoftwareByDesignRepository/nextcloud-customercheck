<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<section class="crm-card" id="section-personal-mail" aria-labelledby="section-personal-mail-heading">
	<header class="crm-card__header">
		<div class="crm-card__header-text">
			<h2 id="section-personal-mail-heading" class="crm-card__title"><?php p($l->t('Personal email OAuth')); ?></h2>
			<p class="crm-card__lead"><?php p($l->t('Register Google and Microsoft OAuth apps so workspace members can connect Gmail or Outlook without storing a password.')); ?></p>
		</div>
	</header>
	<div class="crm-card__body">
		<div class="crm-callout crm-callout--info" role="status">
			<p class="crm-callout__text"><?php p($l->t('Redirect URI for both providers (copy into the provider console):')); ?></p>
			<p><code id="crm-oauth-redirect-uri" class="crm-code"><?php p($_['personalMailOAuthRedirectUri'] ?? ''); ?></code></p>
		</div>
		<form id="crm-personal-mail-oauth-form" class="crm-form crm-form--stack" novalidate>
			<fieldset class="crm-fieldset">
				<legend class="form-label"><?php p($l->t('Google')); ?></legend>
				<div class="form-group">
					<label class="form-label" for="crm-oauth-google-client-id"><?php p($l->t('Client ID')); ?></label>
					<input type="text" id="crm-oauth-google-client-id" name="googleClientId" class="form-input crm-input" autocomplete="off" spellcheck="false">
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-oauth-google-client-secret"><?php p($l->t('Client secret')); ?></label>
					<input type="password" id="crm-oauth-google-client-secret" name="googleClientSecret" class="form-input crm-input" autocomplete="new-password" placeholder="<?php p($l->t('Leave blank to keep the saved secret')); ?>">
					<p class="form-help" id="crm-oauth-google-secret-hint"></p>
				</div>
			</fieldset>
			<fieldset class="crm-fieldset">
				<legend class="form-label"><?php p($l->t('Microsoft')); ?></legend>
				<div class="form-group">
					<label class="form-label" for="crm-oauth-microsoft-client-id"><?php p($l->t('Client ID')); ?></label>
					<input type="text" id="crm-oauth-microsoft-client-id" name="microsoftClientId" class="form-input crm-input" autocomplete="off" spellcheck="false">
				</div>
				<div class="form-group">
					<label class="form-label" for="crm-oauth-microsoft-client-secret"><?php p($l->t('Client secret')); ?></label>
					<input type="password" id="crm-oauth-microsoft-client-secret" name="microsoftClientSecret" class="form-input crm-input" autocomplete="new-password" placeholder="<?php p($l->t('Leave blank to keep the saved secret')); ?>">
					<p class="form-help" id="crm-oauth-microsoft-secret-hint"></p>
				</div>
			</fieldset>
			<p id="crm-oauth-config-error" class="form-error" role="alert" hidden></p>
			<p id="crm-oauth-config-status" class="form-help" role="status" aria-live="polite"></p>
			<div class="crm-form-actions">
				<button type="submit" class="crm-btn crm-btn--primary"><?php p($l->t('Save OAuth settings')); ?></button>
			</div>
		</form>
	</div>
</section>
