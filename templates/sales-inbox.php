<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
include __DIR__ . '/common/page-start.php';
?>
<div class="crm-page-stack" id="crm-sales-inbox-root" data-crm-sales-inbox="1" data-crm-bachus-mail="1" data-crm-inbox-unified="1">
	<div class="crm-callout crm-callout--warning crm-sales-inbox__alert" role="alert" id="crm-sales-inbox-auth-alert" hidden>
		<p id="crm-sales-inbox-auth-alert-text"></p>
		<p class="crm-inline-actions">
			<a class="crm-btn crm-btn--primary crm-btn--lg" id="crm-sales-inbox-reconnect" href="#"><?php p($l->t('Reconnect')); ?></a>
		</p>
	</div>

	<p class="crm-sales-inbox__hint" role="status" id="crm-sales-inbox-disclosure">
		<?php p($l->t('Your mail, the team inbox, and items that still need a company — one place.')); ?>
	</p>
	<p class="crm-sales-inbox__hint crm-sales-inbox__hint--shared" role="status" id="crm-sales-inbox-shared-folders" hidden></p>

	<div class="crm-sales-inbox__toolbar">
		<div
			class="crm-tabs crm-quick-filters crm-sales-inbox__tabs"
			role="tablist"
			aria-label="<?php p($l->t('Inbox tabs')); ?>"
			id="crm-sales-inbox-tabs">
			<button type="button" class="crm-btn crm-btn--primary crm-tab crm-sales-inbox__tab" role="tab" id="crm-sales-tab-mine" data-crm-inbox-tab="mine" aria-selected="true" aria-controls="crm-sales-inbox-panel"><?php p($l->t('Personal')); ?></button>
			<button type="button" class="crm-btn crm-btn--secondary crm-tab crm-sales-inbox__tab" role="tab" id="crm-sales-tab-team" data-crm-inbox-tab="team" aria-selected="false" aria-controls="crm-sales-inbox-panel"><?php p($l->t('Team')); ?></button>
			<button type="button" class="crm-btn crm-btn--secondary crm-tab crm-sales-inbox__tab" role="tab" id="crm-sales-tab-unmatched" data-crm-inbox-tab="unmatched" aria-selected="false" aria-controls="crm-sales-inbox-panel"><?php p($l->t('Unassigned')); ?></button>
		</div>
		<div class="crm-inline-actions crm-sales-inbox__actions">
			<label class="crm-field crm-field--inline" for="crm-sales-inbox-account" id="crm-sales-inbox-account-wrap" hidden>
				<span class="visually-hidden"><?php p($l->t('Account')); ?></span>
				<select id="crm-sales-inbox-account" class="crm-input crm-input--lg" aria-label="<?php p($l->t('Filter by account')); ?>">
					<option value="0"><?php p($l->t('All accounts')); ?></option>
				</select>
			</label>
			<button type="button" class="crm-btn crm-btn--secondary" id="crm-sales-inbox-sync" hidden><?php p($l->t('Update mail')); ?></button>
		</div>
	</div>

	<p class="crm-sales-inbox__status" id="crm-sales-inbox-status" role="status" aria-live="polite" hidden></p>

	<div class="crm-mail-3pane" id="crm-sales-inbox-panel" role="tabpanel" aria-labelledby="crm-sales-tab-mine">
		<div class="crm-mail-3pane__thread">
			<section class="crm-sales-inbox__list-tools" id="crm-sales-inbox-list-tools" aria-label="<?php p($l->t('Search and sort')); ?>">
				<div class="crm-sales-inbox__list-tools-search">
					<label class="crm-sales-compose__label" for="crm-sales-inbox-q" id="crm-sales-inbox-q-label"><?php p($l->t('Search')); ?></label>
					<input
						type="search"
						id="crm-sales-inbox-q"
						class="crm-input crm-input--lg"
						autocomplete="off"
						placeholder="<?php p($l->t('Search from, subject…')); ?>"
						aria-controls="crm-sales-inbox-list" />
				</div>
				<div class="crm-sales-inbox__list-tools-filters" id="crm-sales-inbox-mail-filters" hidden>
					<p class="crm-sales-compose__label" id="crm-sales-inbox-filter-label"><?php p($l->t('Show')); ?></p>
					<div class="crm-tabs crm-quick-filters" role="group" aria-labelledby="crm-sales-inbox-filter-label">
						<button type="button" class="crm-btn crm-btn--primary crm-btn--sm" data-crm-inbox-mail-filter="mail" aria-pressed="true"><?php p($l->t('All mail')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" data-crm-inbox-mail-filter="shared_mail" aria-pressed="false"><?php p($l->t('Team')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm" data-crm-inbox-mail-filter="personal_mail_shared_unmatched" aria-pressed="false"><?php p($l->t('Personal shared')); ?></button>
					</div>
				</div>
			</section>
			<div
				class="crm-sales-inbox__cols"
				id="crm-sales-inbox-cols"
				role="toolbar"
				aria-label="<?php p($l->t('Message columns')); ?>">
				<button type="button" class="crm-sales-inbox__col-head crm-sales-inbox__sort" data-crm-inbox-sort="from" aria-pressed="false"><?php p($l->t('From')); ?></button>
				<button type="button" class="crm-sales-inbox__col-head crm-sales-inbox__sort" data-crm-inbox-sort="subject" aria-pressed="false"><?php p($l->t('Subject')); ?></button>
				<span class="crm-sales-inbox__col-head crm-sales-inbox__col-head--static" id="crm-sales-inbox-col-account"><?php p($l->t('Account')); ?></span>
				<button type="button" class="crm-sales-inbox__col-head crm-sales-inbox__sort is-active" data-crm-inbox-sort="sent" aria-pressed="true"><?php p($l->t('Date')); ?></button>
			</div>
			<ul class="crm-mail-thread crm-sales-inbox__table" id="crm-sales-inbox-list" role="listbox" aria-label="<?php p($l->t('Message list')); ?>" aria-activedescendant=""></ul>
			<div class="crm-empty-state crm-sales-inbox__empty" id="crm-sales-inbox-empty" role="status" hidden>
				<h2 class="crm-empty-state__title" id="crm-sales-inbox-empty-title"><?php p($l->t('No messages here')); ?></h2>
				<p class="crm-empty-state__text" id="crm-sales-inbox-empty-text"><?php p($l->t('Nothing to show in this tab yet.')); ?></p>
				<p class="crm-form-actions crm-form-actions--center" id="crm-sales-inbox-empty-cta-wrap" hidden>
					<a class="crm-btn crm-btn--primary crm-btn--lg" id="crm-sales-inbox-empty-cta" href="#"><?php p($l->t('Connect my email')); ?></a>
				</p>
			</div>
		</div>
		<div class="crm-mail-3pane__reader crm-review-detail-row" id="crm-sales-inbox-reader" hidden>
			<article class="crm-review-detail crm-review-reader" aria-live="polite">
				<h2 class="crm-review-detail__title visually-hidden"><?php p($l->t('Message preview')); ?></h2>
				<div class="crm-mail-preview" id="crm-sales-inbox-preview" data-crm-mail-preview="0">
					<p class="crm-mail-thread-row__from" id="crm-sales-preview-from"></p>
					<p class="crm-mail-thread-row__subject" id="crm-sales-preview-subject"></p>
					<p class="crm-muted" id="crm-sales-preview-meta"></p>
					<div class="crm-mail-preview__body crm-mail-preview__body--pending" id="crm-sales-preview-body" tabindex="0" role="article" aria-live="polite" hidden></div>
					<p class="crm-muted" id="crm-sales-preview-hint" role="status"><?php p($l->t('Tap a message to read it.')); ?></p>

					<div class="crm-form-actions crm-sales-inbox__actions-bar" id="crm-sales-inbox-actions" hidden>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" id="crm-sales-inbox-open-assign" aria-pressed="false" hidden><?php p($l->t('Assign')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" id="crm-sales-inbox-reply" aria-pressed="false"><?php p($l->t('Reply')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" id="crm-sales-inbox-spam" hidden title="<?php p($l->t('Moves this message into the server Spam/Junk folder and leaves Assign.')); ?>"><?php p($l->t('Mark as spam')); ?></button>
						<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" id="crm-sales-inbox-discard" hidden><?php p($l->t('Delete forever')); ?></button>
					</div>

					<section class="crm-sales-inbox__decide" id="crm-sales-inbox-decide" hidden aria-labelledby="crm-sales-inbox-decide-title">
						<header class="crm-sales-inbox__decide-head">
							<h3 class="crm-sales-inbox__decide-title" id="crm-sales-inbox-decide-title"><?php p($l->t('Assign to a company')); ?></h3>
							<p class="crm-sales-inbox__decide-lead" id="crm-sales-inbox-decide-lead"><?php p($l->t('Pick the company this message belongs to.')); ?></p>
						</header>

						<div class="crm-sales-inbox__suggest-card" id="crm-sales-inbox-suggest-card" hidden>
							<div class="crm-sales-inbox__suggest-body">
								<p class="crm-sales-inbox__suggest-kicker" id="crm-sales-inbox-suggest-kicker"><?php p($l->t('Best match')); ?></p>
								<p class="crm-sales-inbox__suggest-name" id="crm-sales-inbox-suggest-name"></p>
								<p class="crm-sales-inbox__suggest-meta" id="crm-sales-inbox-suggest-meta" hidden></p>
							</div>
							<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-sales-inbox-quick-assign"></button>
						</div>
						<div class="crm-sales-inbox__alt-suggests" id="crm-sales-inbox-alt-suggests" hidden role="group" aria-label="<?php p($l->t('Other matches')); ?>"></div>

						<form class="crm-form crm-sales-inbox__assign-form" id="crm-sales-inbox-assign-form">
							<p class="crm-sales-inbox__assign-heading" id="crm-sales-inbox-assign-heading"><?php p($l->t('Or pick another company')); ?></p>
							<span class="visually-hidden" id="crm-sales-inbox-company-label"><?php p($l->t('Company')); ?></span>
							<div class="crm-company-picker crm-sales-inbox__company-picker" id="crm-sales-inbox-company-picker" aria-labelledby="crm-sales-inbox-company-label">
								<div class="crm-company-choice" id="crm-sales-inbox-company-choice" hidden>
									<div class="crm-company-choice__card">
										<p class="crm-company-choice__name" id="crm-sales-inbox-company-choice-name"></p>
										<button type="button" class="crm-btn crm-btn--secondary crm-btn--sm crm-company-choice__change" id="crm-sales-inbox-company-change"><?php p($l->t('Change company')); ?></button>
									</div>
								</div>
								<div class="crm-company-browse" id="crm-sales-inbox-company-browse">
									<div class="crm-company-picker__filter-wrap" id="crm-sales-inbox-company-filter-wrap">
										<label class="visually-hidden" for="crm-sales-inbox-company-filter"><?php p($l->t('Search companies')); ?></label>
										<input
											type="search"
											id="crm-sales-inbox-company-filter"
											class="form-input crm-input crm-input--lg crm-company-picker__filter"
											autocomplete="off"
											placeholder="<?php p($l->t('Search by company name…')); ?>"
											aria-controls="crm-sales-inbox-company-list" />
									</div>
									<ul id="crm-sales-inbox-company-list" class="crm-company-list" role="listbox" aria-labelledby="crm-sales-inbox-company-label"></ul>
									<p class="crm-muted crm-company-picker__status" id="crm-sales-inbox-company-status" role="status" aria-live="polite" hidden></p>
								</div>
								<input type="hidden" id="crm-sales-inbox-company-id" name="companyId" value="" />
							</div>
							<div class="crm-sales-inbox__assign-actions">
								<button type="submit" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-sales-inbox-assign-submit" disabled><?php p($l->t('Assign')); ?></button>
								<button type="button" class="crm-btn crm-btn--ghost crm-btn--lg" id="crm-sales-inbox-no-relation"><?php p($l->t('No company')); ?></button>
							</div>
						</form>
						<p class="crm-muted crm-sales-inbox__decide-ro" id="crm-sales-inbox-decide-ro" hidden><?php p($l->t('View only — ask a member to assign this.')); ?></p>
					</section>

					<div id="crm-sales-compose-host" class="crm-sales-compose" hidden>
						<header class="crm-sales-compose__head">
							<h3 class="crm-sales-compose__title" id="crm-sales-compose-title"><?php p($l->t('Reply')); ?></h3>
							<p class="crm-sales-compose__hint" id="crm-sales-compose-hint"><?php p($l->t('Write your reply above the original message. Your signature is added when you send.')); ?></p>
						</header>

						<div class="crm-sales-compose__fields">
							<section class="crm-sales-compose__recipients" aria-label="<?php p($l->t('Recipients')); ?>">
								<div class="crm-sales-compose__field">
									<div class="crm-sales-compose__field-head">
										<label class="crm-sales-compose__label" for="crm-sales-compose-to"><?php p($l->t('Send to')); ?></label>
										<button
											type="button"
											class="crm-btn crm-btn--ghost crm-btn--sm crm-sales-compose__cc-toggle"
											id="crm-sales-compose-cc-toggle"
											aria-expanded="false"
											aria-controls="crm-sales-compose-cc-wrap"><?php p($l->t('Add Cc')); ?></button>
									</div>
									<input type="email" id="crm-sales-compose-to" class="form-input crm-input crm-input--lg" autocomplete="email" required>
								</div>
								<div class="crm-sales-compose__field crm-sales-compose__cc" id="crm-sales-compose-cc-wrap" hidden>
									<label class="crm-sales-compose__label" for="crm-sales-compose-cc"><?php p($l->t('Cc')); ?></label>
									<input type="text" id="crm-sales-compose-cc" class="form-input crm-input crm-input--lg" autocomplete="email" aria-describedby="crm-sales-compose-cc-hint">
									<p class="crm-sales-compose__field-hint" id="crm-sales-compose-cc-hint"><?php p($l->t('Optional. Separate addresses with commas.')); ?></p>
								</div>
							</section>

							<div class="crm-sales-compose__field">
								<label class="crm-sales-compose__label" for="crm-sales-compose-subject"><?php p($l->t('Subject')); ?></label>
								<input type="text" id="crm-sales-compose-subject" class="form-input crm-input crm-input--lg">
							</div>

							<div class="crm-sales-compose__field crm-sales-compose__body">
								<span class="crm-sales-compose__label" id="crm-sales-compose-body-label"><?php p($l->t('Your message')); ?></span>
								<div id="crm-sales-compose-editor" aria-labelledby="crm-sales-compose-body-label"></div>
							</div>

							<div class="crm-sales-compose__field crm-sales-compose__attach" id="crm-sales-compose-attach">
								<div class="crm-sales-compose__field-head">
									<span class="crm-sales-compose__label" id="crm-sales-compose-attach-label"><?php p($l->t('Attachments (optional)')); ?></span>
									<button type="button" class="crm-btn crm-btn--secondary crm-btn--lg" id="crm-sales-compose-attach-add"><?php p($l->t('Add file')); ?></button>
								</div>
								<p class="crm-sales-compose__field-hint" id="crm-sales-compose-attach-hint"><?php p($l->t('Choose any file in Files. Only PDF, PNG, JPEG or plain text (max 5 files, 10 MiB each) can be sent.')); ?></p>
								<p class="crm-sales-compose__attach-count" id="crm-sales-compose-attach-count" role="status" aria-live="polite"><?php p($l->t('No files yet — tap Add file to attach.')); ?></p>
								<ul id="crm-sales-compose-attach-list" class="crm-list crm-sales-compose__attach-list" role="list" aria-labelledby="crm-sales-compose-attach-label" aria-live="polite" hidden></ul>
							</div>
						</div>

						<div class="crm-form-actions crm-sales-compose__actions">
							<button type="button" class="crm-btn crm-btn--primary crm-btn--lg" id="crm-sales-compose-send"><?php p($l->t('Send')); ?></button>
							<button type="button" class="crm-btn crm-btn--secondary" id="crm-sales-compose-cancel"><?php p($l->t('Cancel')); ?></button>
						</div>
					</div>
				</div>
			</article>
		</div>
	</div>
</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
