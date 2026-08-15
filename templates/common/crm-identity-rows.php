<?php

declare(strict_types=1);

/**
 * ProjectCheck / TicketCheck / MaintenanceCheck link rows for Company 360.
 * Expects: $l, $company, $identity, $pcState, $tcState, $pcWrite, $tcWrite,
 * $mnAvail, $canWriteIdentity, $showPcCta, $showTcCta.
 * Optional: $crmIdentityShowMoreWrap (bool) — wrap MaintenanceCheck under a details.
 */
$crmIdentityShowMoreWrap = $crmIdentityShowMoreWrap ?? true;
$pcLabel = (string)($identity['pc']['label'] ?? '');
$tcLabel = (string)($identity['tc']['label'] ?? '');
$pcShowDetail = in_array($pcState, ['linked', 'broken', 'sibling_unavailable'], true) && $pcLabel !== '';
$tcShowDetail = in_array($tcState, ['linked', 'broken', 'sibling_unavailable'], true) && $tcLabel !== '';
?>
			<div id="crm-identity-alert" class="crm-callout crm-callout--danger" role="alert" hidden></div>

			<div class="crm-identity-row" data-sibling="pc">
				<div class="crm-identity-row__head">
					<h3 class="crm-identity-row__app"><?php p($l->t('ProjectCheck')); ?></h3>
					<span class="crm-badge crm-badge--<?php p($pcState === 'linked' ? 'success' : ($pcState === 'broken' ? 'danger' : 'neutral')); ?>"><?php
						p($pcState === 'linked' ? $l->t('Linked') : ($pcState === 'broken' ? $l->t('Broken link') : ($pcState === 'sibling_unavailable' ? $l->t('Unavailable') : $l->t('Not linked'))));
					?></span>
				</div>
				<?php if ($pcShowDetail && $pcState !== 'broken'): ?>
					<p class="crm-muted crm-identity-row__detail"><?php p($pcLabel); ?></p>
				<?php endif; ?>
				<?php if ($pcState === 'broken' && $canWriteIdentity): ?>
					<div class="crm-callout crm-callout--danger" role="alert">
						<p class="crm-callout__text"><?php p($pcLabel !== '' ? $pcLabel : $l->t('Broken ProjectCheck link')); ?></p>
						<div class="crm-inline-actions crm-inline-actions--roomy">
							<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="pc"><?php p($l->t('Unlink')); ?></button>
							<?php if ($pcWrite): ?>
							<button type="button" class="crm-btn crm-btn--primary" data-crm-identity-create="pc"><?php p($l->t('Create in ProjectCheck')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" data-crm-identity-link="pc"><?php p($l->t('Link existing')); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($showPcCta): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg"
							aria-label="<?php p($l->t('Create ProjectCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-create="pc"><?php p($l->t('Create in ProjectCheck')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg"
							aria-label="<?php p($l->t('Link existing ProjectCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-link="pc"><?php p($l->t('Link existing')); ?></button>
					</div>
				<?php elseif ($pcState === 'linked' && !empty($identity['pc']['deepLink'])): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<a class="crm-btn crm-btn--secondary" href="<?php p((string)$identity['pc']['deepLink']); ?>"><?php p($l->t('Open in ProjectCheck')); ?></a>
						<?php if ($canWriteIdentity): ?>
						<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="pc"><?php p($l->t('Unlink')); ?></button>
						<?php endif; ?>
					</div>
				<?php elseif ($pcState === 'linked' && $canWriteIdentity): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="pc"><?php p($l->t('Unlink')); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<div class="crm-identity-row crm-identity-row--spaced" data-sibling="tc">
				<div class="crm-identity-row__head">
					<h3 class="crm-identity-row__app"><?php p($l->t('TicketCheck')); ?></h3>
					<span class="crm-badge crm-badge--<?php p($tcState === 'linked' ? 'success' : ($tcState === 'broken' ? 'danger' : 'neutral')); ?>"><?php
						p($tcState === 'linked' ? $l->t('Linked') : ($tcState === 'broken' ? $l->t('Broken link') : ($tcState === 'sibling_unavailable' ? $l->t('Unavailable') : $l->t('Not linked'))));
					?></span>
				</div>
				<?php if ($tcShowDetail && $tcState !== 'broken'): ?>
					<p class="crm-muted crm-identity-row__detail"><?php p($tcLabel); ?></p>
				<?php endif; ?>
				<?php if ($tcState === 'broken' && $canWriteIdentity): ?>
					<div class="crm-callout crm-callout--danger" role="alert">
						<p class="crm-callout__text"><?php p($tcLabel !== '' ? $tcLabel : $l->t('Broken TicketCheck link')); ?></p>
						<div class="crm-inline-actions crm-inline-actions--roomy">
							<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="tc"><?php p($l->t('Unlink')); ?></button>
							<?php if ($tcWrite): ?>
							<button type="button" class="crm-btn crm-btn--primary" data-crm-identity-create="tc"><?php p($l->t('Create in TicketCheck')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" data-crm-identity-link="tc"><?php p($l->t('Link existing')); ?></button>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif ($showTcCta): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--lg"
							aria-label="<?php p($l->t('Create TicketCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-create="tc"><?php p($l->t('Create in TicketCheck')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg"
							aria-label="<?php p($l->t('Link existing TicketCheck customer for %s', [(string)$company->getName()])); ?>"
							data-crm-identity-link="tc"><?php p($l->t('Link existing')); ?></button>
					</div>
				<?php elseif ($tcState === 'linked' && !empty($identity['tc']['deepLink'])): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<a class="crm-btn crm-btn--secondary" href="<?php p((string)$identity['tc']['deepLink']); ?>"><?php p($l->t('Open in TicketCheck')); ?></a>
						<?php if ($canWriteIdentity): ?>
						<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="tc"><?php p($l->t('Unlink')); ?></button>
						<?php endif; ?>
					</div>
				<?php elseif ($tcState === 'linked' && $canWriteIdentity): ?>
					<div class="crm-inline-actions crm-inline-actions--roomy">
						<button type="button" class="crm-btn crm-btn--ghost" data-crm-identity-unlink="tc"><?php p($l->t('Unlink')); ?></button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ($mnAvail && $canWriteIdentity): ?>
			<?php if (!empty($crmIdentityShowMoreWrap)): ?>
			<details class="crm-identity-more">
				<summary class="crm-identity-more__summary"><?php p($l->t('More apps')); ?></summary>
				<div class="crm-identity-more__body">
			<?php endif; ?>
			<div class="crm-identity-row<?php if (empty($crmIdentityShowMoreWrap)): ?> crm-identity-row--spaced<?php endif; ?>" data-sibling="mn">
				<div class="crm-identity-row__head">
					<h3 class="crm-identity-row__app"><?php p($l->t('MaintenanceCheck')); ?></h3>
					<span class="crm-muted"><?php p($l->t('Field register')); ?></span>
				</div>
				<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" data-crm-identity-create-field>
					<?php p($l->t('Create field customer')); ?>
				</button>
			</div>
			<?php if (!empty($crmIdentityShowMoreWrap)): ?>
				</div>
			</details>
			<?php endif; ?>
			<?php endif; ?>
