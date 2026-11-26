<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (crm).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$crm_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$crm_tokens = is_array($crm_state['tokens']) ? $crm_state['tokens'] : [];
?>
<section class="crm-section crm-agent" id="crm-agent-access" aria-labelledby="crm-agent-h">
	<h2 class="crm-section-title" id="crm-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="crm-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="crm-agent-live" class="crm-visually-hidden" aria-live="polite"></div>

	<?php if ($crm_tokens === []): ?>
	<div class="crm-agent-empty" id="crm-agent-empty">
		<p class="crm-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($crm_state['canMint']): ?>
		<button type="button" class="crm-btn crm-btn--primary" id="crm-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="crm-table-wrap crm-agent-tablewrap" tabindex="0" role="region" aria-labelledby="crm-agent-h">
		<table class="crm-table crm-table--cards crm-agent-table" id="crm-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="crm-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="crm-agent-tbody">
				<?php foreach ($crm_tokens as $crm_tok): ?>
				<tr data-token-id="<?php p((string)($crm_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($crm_tok['label'] ?? '')); ?>"<?php if (($crm_tok['state'] ?? '') !== 'active'): ?> class="crm-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>">
						<span class="crm-agent-label"><?php p((string)($crm_tok['label'] ?? '')); ?></span>
						<?php if (($crm_tok['state'] ?? '') === 'revoked'): ?>
							<span class="crm-badge crm-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($crm_tok['state'] ?? '') === 'expired'): ?>
							<span class="crm-badge crm-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="crm-badge crm-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($crm_state['isAdmin'] && ($crm_tok['userId'] ?? '') !== ''): ?>
							<span class="crm-agent-owner"><?php p((string)$crm_tok['userId']); ?></span>
						<?php endif; ?>
					</td>
					<td data-cell="<?php p($l->t('Scopes')); ?>">
						<span class="crm-agent-scopes">
						<?php foreach (($crm_tok['scopes'] ?? []) as $crm_scope): ?>
							<span class="crm-badge crm-badge--neutral crm-agent-scope" title="<?php p((string)$crm_scope); ?>"><?php p((string)($crm_state['scopeLabels'][$crm_scope] ?? $crm_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($crm_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($crm_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($crm_tok['lastUsedAt'] ?? null) === null): ?> class="crm-agent-never"<?php endif; ?> data-ts="<?php p((string)($crm_tok['lastUsedAt'] ?? '')); ?>"><?php if (($crm_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="crm-row-actions">
						<?php if (($crm_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="crm-btn crm-btn--ghost crm-agent-rotate" data-token-id="<?php p((string)$crm_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="crm-btn crm-btn--danger crm-agent-revoke" data-token-id="<?php p((string)$crm_tok['id']); ?>" data-token-label="<?php p((string)($crm_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($crm_state['canMint']): ?>
		<div class="crm-agent-actions">
			<button type="button" class="crm-btn crm-btn--primary" id="crm-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($crm_state['isAdmin']): ?>
	<div class="crm-form-row crm-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="crm-checkbox-label" for="crm-agent-selfmint-cb">
			<input type="checkbox" id="crm-agent-selfmint-cb" <?php if ($crm_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="crm-form-row__hint" id="crm-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="crm-agent-mint-dialog" class="crm-dialog crm-agent-mint-dialog" aria-labelledby="crm-agent-mint-h">
		<div class="crm-dialog__body">
			<h3 id="crm-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="crm-agent-mint-form" novalidate>
				<div class="crm-form-row">
					<label for="crm-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="crm-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="crm-field-error" id="crm-agent-label-error" hidden></p>
				</div>
				<fieldset class="crm-form-row crm-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="crm-choice-cards">
						<label class="crm-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="crm-choice-card__copy">
								<span class="crm-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="crm-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="crm-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="crm-choice-card__copy">
								<span class="crm-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="crm-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="crm-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="crm-choice-card__copy">
								<span class="crm-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="crm-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="crm-form-row crm-fieldset" id="crm-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="crm-agent-chips" id="crm-agent-chips"></div>
					<p class="crm-field-error" id="crm-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="crm-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="crm-dialog__footer">
			<div class="crm-actions">
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="crm-agent-mint-form" class="crm-btn crm-btn--primary" id="crm-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="crm-agent-secret-dialog" class="crm-dialog crm-agent-secret-dialog" aria-labelledby="crm-agent-secret-h">
		<div class="crm-dialog__body">
			<h3 id="crm-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="crm-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="crm-form-row">
				<label for="crm-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="crm-input-group">
					<input type="password" id="crm-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="crm-btn crm-btn--ghost" id="crm-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="crm-btn crm-btn--ghost" id="crm-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="crm-form-row">
				<label for="crm-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="crm-agent-config" class="crm-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="crm-dialog__footer">
			<div class="crm-actions">
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="crm-btn crm-btn--primary" id="crm-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="crm-agent-revoke-dialog" class="crm-dialog" aria-labelledby="crm-agent-revoke-h">
		<div class="crm-dialog__body">
			<h3 id="crm-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="crm-agent-revoke-text"></p>
		</div>
		<footer class="crm-dialog__footer">
			<div class="crm-actions">
				<button type="button" class="crm-btn crm-btn--secondary" id="crm-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="crm-btn crm-btn--danger" id="crm-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="crm-agent-state"><?php
		print_unescaped(json_encode($crm_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
