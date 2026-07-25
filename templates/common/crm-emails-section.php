<?php

/**
 * Company 360 — “Who wrote what” / email log (PSD §14.8).
 *
 * Expects in scope: $l, $mail (from MailRelatedService::forCompany).
 *
 * @var \OCP\IL10N $l
 * @var array $mail
 */

$mailMode = (string)($mail['mode'] ?? 'manual_only');
$mailTitle = (string)($mail['title'] ?? $l->t('Who wrote what'));
$mailHeadline = (string)($mail['headline'] ?? '');
$mailNote = (string)($mail['note'] ?? '');
$mailSteps = is_array($mail['steps'] ?? null) ? $mail['steps'] : [];
$mailSummary = is_array($mail['summary'] ?? null) ? $mail['summary'] : [];
$mailRelated = is_array($mail['related'] ?? null) ? $mail['related'] : [];
$mailDeepLinks = is_array($mail['deepLinks'] ?? null) ? $mail['deepLinks'] : [];
$mailTouches = is_array($mail['touches'] ?? null) ? $mail['touches'] : [];
$touchCount = (int)($mailSummary['touchCount'] ?? count($mailTouches));
$contactCount = (int)($mailSummary['contactCount'] ?? 0);
$lastAt = (string)($mailSummary['lastContactAt'] ?? '');
$lastLabel = (string)($mailSummary['lastContactLabel'] ?? '');
?>
<section class="crm-card crm-card--emails" id="crm-emails" aria-labelledby="crm-emails-title" data-crm-mail-mode="<?php p($mailMode); ?>">
	<header class="crm-card__header">
		<div class="crm-card__header-text">
			<h2 id="crm-emails-title" class="crm-card__title"><?php p($mailTitle); ?></h2>
			<?php if ($mailHeadline !== ''): ?>
				<p class="crm-card__lead"><?php p($mailHeadline); ?></p>
			<?php endif; ?>
		</div>
		<button type="button" class="crm-btn crm-btn--primary crm-btn--sm" id="crm-log-email"><?php p($l->t('Log email')); ?></button>
	</header>
	<div class="crm-card__body">
		<?php if ($mailSteps !== []): ?>
			<details class="crm-howto">
				<summary class="crm-howto__summary"><?php p($l->t('How this works (30 seconds)')); ?></summary>
				<ol class="crm-howto__steps">
					<?php foreach ($mailSteps as $step): ?>
						<li class="crm-howto__step">
							<strong class="crm-howto__step-title"><?php p((string)($step['title'] ?? '')); ?></strong>
							<span class="crm-howto__step-text"><?php p((string)($step['text'] ?? '')); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			</details>
		<?php endif; ?>

		<?php if ($mailNote !== ''): ?>
			<p class="crm-muted crm-emails__privacy" role="note"><?php p($mailNote); ?></p>
		<?php endif; ?>

		<div class="crm-emails__summary" aria-label="<?php p($l->t('Communication summary')); ?>">
			<?php if ($touchCount > 0 && $lastLabel !== '' && $lastAt !== '' && $lastAt !== '—'): ?>
				<p class="crm-emails__summary-line">
					<span class="crm-emails__summary-label"><?php p($l->t('Last logged contact')); ?></span>
					<span class="crm-emails__summary-value"><?php p($lastLabel); ?> · <?php p($lastAt); ?></span>
				</p>
			<?php else: ?>
				<p class="crm-emails__summary-line">
					<span class="crm-emails__summary-label"><?php p($l->t('Last logged contact')); ?></span>
					<span class="crm-emails__summary-value"><?php p($l->t('None yet — log the next important message.')); ?></span>
				</p>
			<?php endif; ?>
			<p class="crm-emails__summary-meta">
				<?php p($l->n('%n logged message', '%n logged messages', $touchCount)); ?>
				·
				<?php p($l->n('%n contact with email', '%n contacts with email', $contactCount)); ?>
			</p>
		</div>

		<?php if ($mailDeepLinks !== []): ?>
			<div class="crm-emails__mail-jump">
				<h3 class="crm-emails__subheading" id="crm-emails-mail-jump"><?php p($l->t('Full threads in Mail')); ?></h3>
				<p class="crm-muted"><?php p($l->t('Opens your Mail app search for that person. Only your Mail accounts are visible.')); ?></p>
				<ul class="crm-emails__deep-links" aria-labelledby="crm-emails-mail-jump">
					<?php foreach ($mailDeepLinks as $link): ?>
						<li>
							<a class="crm-btn crm-btn--secondary crm-btn--sm"
								href="<?php p((string)($link['url'] ?? '#')); ?>"
								target="_blank"
								rel="noopener noreferrer"><?php p((string)($link['label'] ?? '')); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ($mailRelated !== []): ?>
			<div class="crm-emails__related">
				<h3 class="crm-emails__subheading" id="crm-emails-related"><?php p($l->t('From your Mail')); ?></h3>
				<div class="table-container crm-table--responsive">
					<table class="table table--hover" aria-labelledby="crm-emails-related">
						<thead>
							<tr>
								<th><?php p($l->t('When')); ?></th>
								<th><?php p($l->t('With')); ?></th>
								<th><?php p($l->t('Direction')); ?></th>
								<th><?php p($l->t('Subject')); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($mailRelated as $row): ?>
							<tr>
								<td data-label="<?php p($l->t('When')); ?>"><?php p((string)($row['whenLabel'] ?? '—')); ?></td>
								<td data-label="<?php p($l->t('With')); ?>">
									<?php p((string)($row['withLabel'] ?? '')); ?>
									<?php if (!empty($row['withWhom']) && ($row['withLabel'] ?? '') !== ($row['withWhom'] ?? '')): ?>
										<br><span class="crm-muted"><code><?php p((string)$row['withWhom']); ?></code></span>
									<?php endif; ?>
								</td>
								<td data-label="<?php p($l->t('Direction')); ?>"><span class="crm-badge"><?php p((string)($row['directionLabel'] ?? $row['direction'] ?? '')); ?></span></td>
								<td data-label="<?php p($l->t('Subject')); ?>">
									<?php if (!empty($row['deepLink'])): ?>
										<a href="<?php p((string)$row['deepLink']); ?>" target="_blank" rel="noopener noreferrer"><?php p((string)($row['subject'] ?? '')); ?></a>
									<?php else: ?>
										<?php p((string)($row['subject'] ?? '')); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>

		<div class="crm-emails__log">
			<h3 class="crm-emails__subheading" id="crm-emails-log"><?php p($l->t('CRM email log')); ?></h3>
			<?php if ($mailTouches === []): ?>
				<div class="crm-empty-state" role="status">
					<h4 class="crm-empty-state__title"><?php p($l->t('Nothing logged for this company yet')); ?></h4>
					<p class="crm-empty-state__text"><?php p($l->t('Tap Log email after an important call-follow-up or reply. Or use a CRM compose button (renewal quote, delivery pack, Partner mail) — that also adds a row here.')); ?></p>
				</div>
			<?php else: ?>
			<div class="table-container crm-table--responsive">
				<table class="table table--hover" aria-labelledby="crm-emails-log">
					<thead>
						<tr>
							<th><?php p($l->t('When')); ?></th>
							<th><?php p($l->t('With')); ?></th>
							<th><?php p($l->t('Direction')); ?></th>
							<th><?php p($l->t('Subject')); ?></th>
							<th><?php p($l->t('How')); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($mailTouches as $t): ?>
						<tr>
							<td data-label="<?php p($l->t('When')); ?>"><?php p((string)($t['whenLabel'] ?? '—')); ?></td>
							<td data-label="<?php p($l->t('With')); ?>">
								<strong><?php p((string)($t['withLabel'] ?? '')); ?></strong>
								<?php if (!empty($t['withWhom']) && ($t['withLabel'] ?? '') !== ($t['withWhom'] ?? '')): ?>
									<br><span class="crm-muted"><code><?php p((string)$t['withWhom']); ?></code></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('Direction')); ?>"><span class="crm-badge"><?php p((string)($t['directionLabel'] ?? $t['direction'] ?? '')); ?></span></td>
							<td data-label="<?php p($l->t('Subject')); ?>">
								<?php p((string)($t['subject'] ?? '')); ?>
								<?php if (!empty($t['snippet'])): ?>
									<br><span class="crm-muted"><?php p((string)$t['snippet']); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php p($l->t('How')); ?>"><span class="crm-badge crm-badge--neutral"><?php p((string)($t['sourceLabel'] ?? $t['source'] ?? '')); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>
