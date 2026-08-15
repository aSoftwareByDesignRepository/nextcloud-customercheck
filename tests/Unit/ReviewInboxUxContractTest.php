<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** GNQ §5.6 / §5.8 — Review disclosure + suggestion name surface. */
final class ReviewInboxUxContractTest extends TestCase
{
	public function testUnfilteredInboxAlwaysShowsDisclosure(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString("id = 'crm-review-disclosure'", $src);
		$this->assertStringContainsString('This inbox may include shared mail and platform sources', $src);
		$this->assertStringContainsString('CC the shared mailbox', $src);
		$this->assertStringContainsString('unique To/CC contact', $src);
		$this->assertStringContainsString('forward their mail', $src);
	}

	public function testSuggestionsPreferCompanyNameOverBareId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('top.companyName', $src);
		$this->assertStringContainsString('Ambiguous company name from import', $src);
		$this->assertStringNotContainsString("' (#' + String(top.companyId) + ')'", $src);
	}

	public function testFailedSendsSurfaceOnSharedMailReview(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('async function renderFailedSendsSection', $src);
		$this->assertStringContainsString("kind === 'shared_mail' || kind === 'mail'", $src);
		$this->assertStringContainsString('renderFailedSendsSection(mount, ws', $src);
		$this->assertStringContainsString('/mail/drafts', $src);
		$this->assertStringContainsString('data-crm-draft-resend', $src);
		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString('persona_api#syncMailbox', $routes);
		$this->assertStringContainsString('/mailboxes/{id}/sync', $routes);
		$this->assertStringContainsString('persona_api#deleteMailbox', $routes);
		$this->assertStringContainsString("/mailboxes/{id}', 'verb' => 'DELETE'", $routes);
		$this->assertStringContainsString("/mailboxes/{id}/remove', 'verb' => 'POST'", $routes);
		$this->assertStringContainsString("'postfix' => 'Remove'", $routes);
	}

	public function testImportAnomalyCompanyNameFeedsOrgSuggestion(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString("\$ref['org'] = \$raw", $src);
		$this->assertStringContainsString("\$kind === 'import_anomaly' && (\$ref['fieldKey'] ?? '') === 'company_name'", $src);
	}

	public function testMatchLogicReturnsCompanyName(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewMatchLogic.php');
		$this->assertStringContainsString("'companyName'", $src);
		$this->assertStringContainsString('private static function hit', $src);
	}

	public function testSuiteTablePutsResponsiveClassOnTableAndActionCell(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('function renderReviewBoard', $js);
		$this->assertStringContainsString('crm-review-board', $js);
		$this->assertStringContainsString('crm-review-row', $js);
		$this->assertStringContainsString('crm-review-list', $js);
		$this->assertStringContainsString('crm-review-table', $js);
		$this->assertStringContainsString('crm-mail-thread', $js);
		$this->assertStringContainsString('crm-mail-thread-row', $js);
		$this->assertStringContainsString('data-crm-review-open', $js);
		$this->assertStringContainsString('data-crm-review-sort', $js);
		$this->assertStringContainsString('crm-review-sortbar', $js);
		$this->assertStringContainsString("t('Date')", $js);
		$this->assertStringContainsString("sortBtn('sent', t('Date'))", $js);
		$this->assertStringContainsString("t('From')", $js);
		$this->assertStringContainsString("t('Subject')", $js);
		$this->assertStringContainsString('crm-mail-thread-row__when', $js);
		$this->assertStringContainsString('data-crm-sort-sent', $js);
		$this->assertStringContainsString('sentAt', $js);
		$this->assertStringContainsString('function parseReviewTimestamp', $js);
		$this->assertStringContainsString('parseReviewTimestamp(a.sentAt', $js);
		$this->assertStringContainsString('From, subject, date, or snippet', $js);
		$this->assertStringContainsString('data-crm-review-reader', $js);
		$this->assertStringContainsString('placeDetailUnderRow', $js);
		$this->assertStringContainsString('crm-review-detail-row', $js);
		$this->assertStringContainsString('crm-mail-3pane__reader crm-review-detail-row', $js);
		$this->assertStringContainsString('row.after(detailTr)', $js);
		$this->assertStringNotContainsString("role: 'presentation'", $js);
		$this->assertStringNotContainsString('role="presentation"', $js);
		$this->assertStringNotContainsString("reader.scrollIntoView", $js);
		$this->assertStringContainsString('row.scrollIntoView', $js);
		$this->assertStringContainsString('crm-review-pick-company', $js);
		$this->assertStringContainsString("t('Assign to a company')", $js);
		$this->assertStringContainsString('crm-review-mail-tools', $js);
		$this->assertStringContainsString('function decodeMimeHeader', $js);
		$this->assertStringContainsString('decodeMimeHeader(it.title', $js);
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-review-board', $css);
		$this->assertStringContainsString('.crm-review-row', $css);
		$this->assertStringContainsString('.crm-review-table', $css);
		$this->assertStringContainsString('.crm-mail-thread-row', $css);
		$this->assertStringContainsString('.crm-review-detail-row', $css);
		$this->assertStringContainsString('.crm-review-detail', $css);
		$this->assertStringContainsString('.crm-review-sort', $css);
		$this->assertStringContainsString('.crm-review-sortbar', $css);
		$this->assertStringContainsString('flex-direction: row', $css);
		$this->assertStringContainsString('.crm-mail-thread-row__when', $css);
		$this->assertStringContainsString('width: auto', $css);
		$this->assertStringNotContainsString(
			".crm-review-sort {\n\tmin-height: 44px;\n\tpadding-inline: 0.5rem;\n\tfont-weight: 700;\n\ttext-align: start;\n\twidth: 100%;",
			$css,
		);
		$this->assertStringContainsString('.crm-review-detail__title', $css);
		$this->assertStringContainsString('.crm-review-pick-company__body', $css);
		$pagePatterns = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/page-patterns.css');
		$this->assertStringContainsString('td.crm-table-actions-col', $pagePatterns);
		$this->assertStringContainsString(':not(.crm-review-table)', $pagePatterns);
	}

	public function testReviewDiscardAndBulkMultiselectWired(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('data-crm-review-discard', $js);
		$this->assertStringNotContainsString('data-crm-review-discard-disabled', $js);
		$this->assertMatchesRegularExpression("/data-crm-review-discard(?![\\w-])/", $js);
		$this->assertStringContainsString('data-crm-review-select', $js);
		$this->assertStringContainsString('data-crm-review-select-all', $js);
		$this->assertStringContainsString('data-crm-review-bulk-remove', $js);
		$this->assertStringContainsString('data-crm-review-bulk-spam', $js);
		$this->assertStringContainsString('data-crm-review-spam', $js);
		$this->assertStringContainsString("/review/items/' + rid + '/discard", $js);
		$this->assertStringContainsString("/review/items/discard-bulk", $js);
		$this->assertStringContainsString("/review/items/' + rid + '/mark-spam", $js);
		$this->assertStringContainsString("/review/items/mark-spam-bulk", $js);
		$this->assertStringNotContainsString('/review/items/bulk-discard', $js);
		$this->assertStringContainsString('Delete forever', $js);
		$this->assertStringContainsString('Mark as spam', $js);
		$this->assertStringContainsString('Delete selected', $js);
		$this->assertStringContainsString('Delete this mail forever?', $js);
		$this->assertStringContainsString('Permanently deletes the message from the shared mailbox', $js);
		$this->assertStringContainsString('selectable: canWrite', $js);

		$inboxJs = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/sales-inbox.js');
		$this->assertStringContainsString("postInboxMutate('delete'", $inboxJs);
		$this->assertStringContainsString("postInboxMutate('mark-spam'", $inboxJs);
		$this->assertStringContainsString("'/mail/' + mid + '/' + pathSuffix", $inboxJs);
		$this->assertStringContainsString('mutateActionVisibility', $inboxJs);

		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString("'name' => 'review_api#discardBulk'", $routes);
		$this->assertStringContainsString("/review/items/discard-bulk', 'verb' => 'POST'", $routes);
		$this->assertStringContainsString("'name' => 'review_api#markSpam'", $routes);
		$this->assertStringContainsString("/review/items/{id}/mark-spam', 'verb' => 'POST'", $routes);
		$this->assertStringContainsString("'name' => 'review_api#markSpamBulk'", $routes);
		$this->assertStringContainsString("/review/items/mark-spam-bulk', 'verb' => 'POST'", $routes);
		$this->assertStringContainsString("'name' => 'persona_api#deleteMail'", $routes);
		$this->assertStringContainsString("/mail/{id}/delete', 'verb' => 'POST'", $routes);
		$this->assertStringContainsString("'name' => 'persona_api#markSpamMail'", $routes);
		$this->assertStringContainsString("/mail/{id}/mark-spam', 'verb' => 'POST'", $routes);
		$this->assertStringNotContainsString('review_api#bulkDiscard', $routes);

		$personaCtrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('function deleteMail', $personaCtrl);
		$this->assertStringContainsString('function markSpamMail', $personaCtrl);
		$this->assertStringContainsString('deleteIndexedMessageFromInbox', $personaCtrl);
		$this->assertStringContainsString('markIndexedMessageSpamFromInbox', $personaCtrl);

		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/ReviewApiController.php');
		$this->assertStringContainsString('function discardBulk', $ctrl);
		$this->assertStringContainsString('function markSpam', $ctrl);
		$this->assertStringContainsString('function markSpamBulk', $ctrl);
		$this->assertStringContainsString('assertReviewWrite', $ctrl);

		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('function discardBulk', $svc);
		$this->assertStringContainsString('function markSpam', $svc);
		$this->assertStringContainsString('function markSpamBulk', $svc);
		$this->assertStringContainsString('normalizeDiscardBulkIds', $svc);
		$this->assertStringContainsString('REVIEW_DISCARD_BULK_MAX', $svc);
		$this->assertStringContainsString('deleteIndexedMessage', $svc);
		$this->assertStringContainsString('moveIndexedMessageToJunk', $svc);
		$this->assertStringContainsString('no live mail-server id yet', $svc);
		$this->assertStringContainsString('markRemoteDeleted', $svc);
		$this->assertStringContainsString("'failed'", $svc);
		$this->assertStringNotContainsString('Never deletes the remote IMAP message', $svc);

		$sync = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('function deleteIndexedMessage', $sync);
		$this->assertStringContainsString('function moveIndexedMessageToJunk', $sync);
		$this->assertStringContainsString('deleteIndexedMessageViaLiveProbe', $sync);
		$this->assertStringContainsString('ImapLiveProbe::deleteUid', $sync);
		$this->assertStringContainsString('ImapLiveProbe::moveUidToJunk', $sync);
		$this->assertStringContainsString('imap_delete', $sync);
		$this->assertStringContainsString('imap_expunge', $sync);
		$this->assertStringContainsString('ImapConnection::isLiveUid', $sync);
		$this->assertStringContainsString('$mailboxId <= 0 || !ImapConnection::isLiveUid($uidRaw)', $sync);
		$this->assertStringNotContainsString(
			'This message cannot be deleted from the shared mailbox (missing folder or id).',
			$sync,
		);
		$this->assertStringNotContainsString(
			'This message cannot be marked as spam (missing folder or id).',
			$sync,
		);
		$this->assertStringNotContainsString("str_starts_with(\$uidRaw, 'outbound:')", $sync);

		$mbox = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString("neq('m.direction'", $mbox);
		$this->assertStringContainsString("notLike('m.message_uid'", $mbox);

		$send = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailSendService.php');
		$this->assertStringContainsString('Never open Assign/Unassigned for CRM-sent copies', $send);

		$triage = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ReviewSharedMailTriage.php');
		$this->assertStringContainsString('isCrmOutboundNotForAssign', $triage);
		$this->assertStringContainsString("return 'dead'", $triage);

		$probe = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ImapLiveProbe.php');
		$this->assertStringContainsString('function deleteUid', $probe);
		$this->assertStringContainsString('function moveUidToJunk', $probe);
		$this->assertStringContainsString('function parseListEntries', $probe);
		$this->assertStringContainsString('UID STORE', $probe);
		$this->assertStringContainsString('EXPUNGE', $probe);
		$this->assertStringContainsString('UID MOVE', $probe);

		$conn = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ImapConnection.php');
		$this->assertStringContainsString('function pickJunkFolder', $conn);
		$this->assertStringContainsString('function isLiveUid', $conn);

		$mail = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function markRemoteDeleted', $mail);
		$this->assertStringContainsString("'remote_deleted'", $mail);

		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-review-bulk-bar', $css);
		$this->assertStringContainsString('.crm-review-bulk-bar[hidden]', $css);
		$this->assertStringContainsString('position: sticky', $css);
		$this->assertStringContainsString('.crm-table-select-col', $css);
	}

	public function testSharedMailPreviewAndReviewSearchWired(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('function bindMailPreviewActions', $js);
		$this->assertStringContainsString('function openMailPreview', $js);
		$this->assertStringContainsString('function ensureMailPreviewsOpen', $js);
		$this->assertStringContainsString('ensureMailPreviewsOpen(reader, ws, mount', $js);
		$this->assertStringContainsString('data-crm-mail-auto-open', $js);
		$this->assertStringContainsString('crm-review-zone--read', $js);
		$this->assertStringContainsString('crm-review-zone--decide', $js);
		$this->assertStringContainsString("t('What should happen?')", $js);
		$this->assertStringContainsString("t('Message')", $js);
		$this->assertStringContainsString('visually-hidden', $js);
		$this->assertGreaterThanOrEqual(
			1,
			preg_match_all('/^\s{4}bindMailPreviewActions\(body, ws, mount\);$/m', $js),
			'Mail triage must call bindMailPreviewActions(body…)',
		);
		$this->assertStringContainsString('bindMailPreviewActions(reader, ws, mount)', $js);
		$this->assertStringContainsString('data-crm-mail-reply', $js);
		$this->assertStringContainsString("/mail/' + mailId + '/reply", $js);
		$this->assertStringContainsString('Answer', $js);
		$this->assertStringContainsString("t('Answer: %s'", $js);
		$this->assertStringContainsString('CustomerCheckSharedMail', $js);
		$this->assertStringContainsString('offerPreparedMail', $js);
		$this->assertStringContainsString('replyMode: true', $js);
		$this->assertStringContainsString('safeExternalHref', $js);
		$this->assertStringContainsString("apiPost('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/preview', {})", $js);
		$this->assertStringNotContainsString("apiGet('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/preview'", $js);
		$this->assertStringContainsString('Hydrate cached preview once when Answer is pressed first', $js);
		$this->assertStringContainsString("id=\"crm-review-q\"", $js);
		$this->assertStringContainsString('crm-review-toolbar', $js);
		$this->assertStringContainsString('crm-review-search__field', $js);
		$this->assertStringNotContainsString('crm-advanced crm-review-tools', $js);
		$this->assertStringContainsString('Show message', $js);
		$this->assertStringContainsString('crm-triage-preview-body-', $js);
		$this->assertStringContainsString('pre.textContent', $js);
		$this->assertStringContainsString('aria-expanded="false"', $js);
		$this->assertStringContainsString("setAttribute('aria-expanded'", $js);
		$this->assertStringContainsString('aria-controls=', $js);
		$this->assertStringContainsString('crm-mail-preview-body-', $js);
		$this->assertStringContainsString("role=\"region\"", $js);
		$this->assertStringContainsString("aria-busy", $js);
		$this->assertStringContainsString('Never use innerHTML', $js);
		$this->assertStringNotContainsString('pre.innerHTML', $js);
		$this->assertStringNotContainsString('crm-mail-preview__hint', $js);
		$this->assertStringContainsString('handled this message|not open for triage|Reload Assign', $js);

		$company = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/company-detail.js');
		$this->assertStringContainsString('data-crm-mail-reply', $company);
		$this->assertStringContainsString("/mail/' + crmMailId + '/reply", $company);
		$this->assertStringContainsString("Api.post('/workspaces/' + ws + '/mail/' + crmMailId + '/preview', {}", $company);
		$this->assertStringContainsString('signal: previewSignal', $company);
		$this->assertStringNotContainsString("Api.get('/workspaces/' + ws + '/mail/' + crmMailId + '/preview'", $company);
		$this->assertStringContainsString('Hydrate once when Answer is first', $company);
		$this->assertStringContainsString("t('Answer: %s'", $company);
		$this->assertStringContainsString('CustomerCheckSharedMail', $company);
		$this->assertStringContainsString('offerPreparedMail', $company);
		$this->assertStringContainsString('replyMode: true', $company);
		$this->assertStringContainsString('Never seed body text from list/feed JSON', $company);
		$this->assertStringContainsString('aria-expanded', $company);
		$this->assertStringNotContainsString('crm-mail-preview__hint', $company);
		$this->assertStringNotContainsString('if (item.preview)', $company);

		$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/appinfo/routes.php');
		$this->assertStringContainsString("'name' => 'persona_api#previewMail'", $routes);
		$this->assertStringContainsString("/mail/{id}/preview', 'verb' => 'POST'", $routes);
		$this->assertStringNotContainsString("/mail/{id}/preview', 'verb' => 'GET'", $routes);
		$this->assertStringContainsString("'name' => 'persona_api#replyMail'", $routes);
		$this->assertStringContainsString("/mail/{id}/reply', 'verb' => 'GET'", $routes);

		$ctrl = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Controller/PersonaApiController.php');
		$this->assertStringContainsString('function previewMail', $ctrl);
		$this->assertStringContainsString('POST-only', $ctrl);
		$this->assertStringContainsString('function replyMail', $ctrl);
		$this->assertStringContainsString("assertCapability(\$uid, \$workspaceId, 'mail.read')", $ctrl);

		$tpl = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/settings/workspace-mailbox.php');
		$this->assertStringContainsString('short plain-text previews cached in CustomerCheck', $tpl);

		$compliance = (string)file_get_contents(dirname(__DIR__, 2) . '/docs/security/MAIL-PREVIEW-COMPLIANCE.md');
		$this->assertStringContainsString('Art. 6(1)(f)', $compliance);
		$this->assertStringContainsString('≤ 1 hour', $compliance);
		$sec = (string)file_get_contents(dirname(__DIR__, 2) . '/SECURITY.md');
		$this->assertStringContainsString('Rotate the shared IMAP/SMTP password within **1 hour**', $sec);

		$sync = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxSyncService.php');
		$this->assertStringContainsString('function previewIndexedMessage', $sync);
		$this->assertStringContainsString('function replyComposeIndexedMessage', $sync);
		$this->assertStringContainsString('MailtoReplyBuilder::build', $sync);
		$this->assertStringContainsString('MailPreviewText::toPlainPreview', $sync);
		$this->assertStringContainsString('ImapLiveProbe::fetchUidTextPreview', $sync);
		$this->assertStringContainsString('Rate limits run *after* lock', $sync);
		$this->assertStringContainsString('Mailbox is busy (sync or another preview)', $sync);
		$this->assertStringContainsString('never imap_body()', $sync);
		$this->assertStringNotContainsString('@imap_body', $sync);
		$this->assertStringNotContainsString('imap_body($', $sync);
		$this->assertStringContainsString('Open the target folder directly', $sync);

		$mbox = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/MailboxService.php');
		$this->assertStringContainsString('function findMessageForPreview', $mbox);
		$this->assertStringContainsString('function cacheMessagePreview', $mbox);
		$this->assertStringContainsString('MailPreviewText::toPlainPreview', $mbox);
		$this->assertStringContainsString('Someone else already handled this message. Reload Assign and try again.', $mbox);

		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		$this->assertStringContainsString('.crm-mail-preview__body', $css);
		$this->assertStringContainsString('.crm-mail-preview__body:focus', $css);
		$this->assertMatchesRegularExpression('/\.crm-mail-preview__body\s*\{[^}]*user-select:\s*text/s', $css);
		$this->assertStringContainsString('-webkit-user-select: text', $css);

		$previewText = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/MailPreviewText.php');
		$this->assertStringContainsString('function extractBestMimePart', $previewText);
		$this->assertStringContainsString('text/plain', $previewText);

		$persona = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('data-crm-mail-copy', $persona);
		$this->assertStringContainsString("t('Copy message')", $persona);
		$this->assertStringContainsString('navigator.clipboard.writeText', $persona);
	}

	public function testReviewListPurgesDeadMailAndUsesStableQueueWindow(): void
	{
		$svc = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Service/ReviewService.php');
		$this->assertStringContainsString('ReviewSharedMailTriage::fetchWindow', $svc);
		$this->assertStringContainsString("orderBy('id', 'DESC')", $svc);
		$this->assertStringNotContainsString("orderBy('updated_at', 'DESC')->setMaxResults(\$fetchLimit)", $svc);
		$this->assertStringContainsString('function reconcileOpenSharedMailReviews', $svc);
		$this->assertStringContainsString('function dropDeadSharedMailItems', $svc);
		$this->assertStringContainsString('$out = $this->dropDeadSharedMailItems($workspaceId, $out);', $svc);
		$this->assertStringContainsString('function closeSharedMailReviewAsGone', $svc);
		$this->assertStringContainsString('Removed — message no longer in the shared mailbox', $svc);
		$this->assertStringContainsString('ReviewSharedMailTriage::openReviewAction', $svc);

		$triage = (string)file_get_contents(dirname(__DIR__, 2) . '/lib/Support/ReviewSharedMailTriage.php');
		$this->assertStringContainsString("'remote_deleted'", $triage);
		$this->assertStringContainsString("'discarded'", $triage);
		$this->assertStringContainsString('function fetchWindow', $triage);

		$js = (string)file_get_contents(dirname(__DIR__, 2) . '/js/pages/persona.js');
		$this->assertStringContainsString('Showing %1$s of %2$s items that need a decision', $js);
		$this->assertStringContainsString('Inbox refreshed — next messages in the queue are shown.', $js);
		$this->assertStringContainsString('Nothing needs a decision right now.', $js);
	}
}
