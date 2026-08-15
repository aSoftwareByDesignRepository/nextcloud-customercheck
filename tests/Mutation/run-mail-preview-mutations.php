<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: mail preview lock / rate-limit order / feed minimization / Review a11y.
 *
 * Usage (Docker from nextcloud/):
 *   docker compose exec -u www-data nextcloud php /var/www/html/custom_apps/customercheck/tests/Mutation/run-mail-preview-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

$lockPath = $appRoot . '/.mutation.lock';
$lockFh = fopen($lockPath, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
	fwrite(STDERR, "Another mutation run holds {$lockPath} — aborting.\n");
	exit(1);
}
register_shutdown_function(static function () use ($lockFh, $lockPath): void {
	flock($lockFh, LOCK_UN);
	fclose($lockFh);
	@unlink($lockPath);
});

/**
 * @return list<array{file:string,from:string,to:string,label:string,filter:string}>
 */
function mail_preview_mutations(string $appRoot): array
{
	return [
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "\$lockKey = 'crm_mbox_' . \$mailboxId;",
			'to' => "\$lockKey = 'crm_preview_' . \$mailboxId;",
			'label' => 'preview_uses_separate_lock_key',
			'filter' => 'MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey|MailPreviewSecurityTest::testUncachedPreviewAcquiresMailboxLock',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => 'Rate limits run *after* lock + cache double-check so lock-busy / stampede',
			'to' => 'Rate limits run before lock (mutated) so lock-busy / stampede',
			'label' => 'rate_limit_order_comment_regress',
			'filter' => 'MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey|ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "if (\$actorUid === '') {\n\t\t\tthrow new ValidationException(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\$this->l10n->t('Sign in to load a message preview.'),\n\t\t\t);\n\t\t}",
			'to' => "if (false && \$actorUid === '') {\n\t\t\tthrow new ValidationException(\n\t\t\t\tConstants::ERR_VALIDATION,\n\t\t\t\t\$this->l10n->t('Sign in to load a message preview.'),\n\t\t\t);\n\t\t}",
			'label' => 'empty_actor_bypass_rate_limit_gate',
			'filter' => 'MailPreviewSecurityTest::testEmptyActorRejectedBeforeImap|MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey',
		],
		[
			'file' => $appRoot . '/lib/Service/CompanyCommandService.php',
			'from' => "'preview' => null,",
			'to' => "'preview' => \$msg['snippet'] ?? null,",
			'label' => 'feed_reembeds_body_like_preview',
			'filter' => 'MailPreviewSecurityTest::testCompanyFeedContractDoesNotEmbedPreviewBody',
		],
		[
			'file' => $appRoot . '/js/pages/company-detail.js',
			'from' => '// Never seed body text from list/feed JSON — on-demand preview API only.',
			'to' => "if (item.preview) { pre.textContent = String(item.preview); pre.setAttribute('data-loaded', '1'); }\n              // mutated: seed from feed",
			'label' => 'company_feed_seeds_preview_body',
			'filter' => 'MailPreviewSecurityTest::testCompanyFeedContractDoesNotEmbedPreviewBody|ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'pre.textContent = bodyText !== \'\'',
			'to' => 'pre.innerHTML = bodyText !== \'\'',
			'label' => 'review_preview_uses_innerHTML',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'aria-expanded="false"',
			'to' => 'data-expanded="false"',
			'label' => 'preview_loses_aria_expanded',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxService.php',
			'from' => 'Someone else already handled this message. Reload Review and try again.',
			'to' => 'This message is not open for triage in the current workspace.',
			'label' => 'triage_conflict_loses_reload_cue',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Support/MailPreviewText.php',
			'from' => '$text = html_entity_decode(strip_tags($scrubbed), ENT_QUOTES | ENT_HTML5, \'UTF-8\');',
			'to' => '$text = $raw;',
			'label' => 'html_strip_disabled',
			'filter' => 'MailPreviewTextTest::testStripsHtmlAndDecodesEntities|MailPreviewTextTest::testScriptTagsNeverSurvive',
		],
		[
			'file' => $appRoot . '/lib/Support/MailPreviewText.php',
			'from' => 'if (is_string($plain) && trim($plain) !== \'\') {' . "\n\t\t\treturn \$plain;\n\t\t}",
			'to' => 'if (false && is_string($plain) && trim($plain) !== \'\') {' . "\n\t\t\treturn \$plain;\n\t\t}",
			'label' => 'multipart_skips_plain_prefers_html_or_raw',
			'filter' => 'MailPreviewTextTest::testMultipartPrefersQuotedPrintablePlainText',
		],
		[
			'file' => $appRoot . '/lib/Support/MailPreviewText.php',
			'from' => "public static function stripMimeDebris(string \$raw): string\n\t{\n\t\tif (\$raw === '') {\n\t\t\treturn '';\n\t\t}",
			'to' => "public static function stripMimeDebris(string \$raw): string\n\t{\n\t\treturn \$raw;\n\t\tif (\$raw === '') {\n\t\t\treturn '';\n\t\t}",
			'label' => 'strip_mime_debris_noop',
			'filter' => 'MailPreviewTextTest::testStripMimeDebrisCutsTrailingMultipartRelated|MailPreviewTextTest::testStripMimeDebrisHandlesQuotePrefixedMimeTail',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "\$payload['preview'] = MailPreviewText::toPlainPreview((string)(\$payload['preview'] ?? ''));",
			'to' => "\$payload['preview'] = (string)(\$payload['preview'] ?? '');",
			'label' => 'with_reply_pack_skips_preview_resanitize',
			'filter' => 'MailPreviewSecurityTest::testCachedPreviewWithInvalidUtf8StillJsonEncodes|MailPreviewSecurityTest::testApiResponsesUseUtf8SubstituteEncodeFlag',
		],
		[
			'file' => $appRoot . '/css/app.css',
			'from' => "\t-webkit-user-select: text;\n\t-moz-user-select: text;\n\t-ms-user-select: text;\n\tuser-select: text;\n\tcursor: text;",
			'to' => "\t-webkit-user-select: none;\n\t-moz-user-select: none;\n\t-ms-user-select: none;\n\tuser-select: none;\n\tcursor: default;",
			'label' => 'mail_preview_blocks_text_selection',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'data-crm-mail-copy',
			'to' => 'data-crm-mail-nocopy',
			'label' => 'review_loses_copy_message_button',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Support/MailPreviewText.php',
			'from' => "if (mb_check_encoding(\$value, 'UTF-8')) {\n\t\t\treturn \$value;\n\t\t}",
			'to' => "if (false && mb_check_encoding(\$value, 'UTF-8')) {\n\t\t\treturn \$value;\n\t\t}\n\t\treturn \$value;",
			'label' => 'ensure_utf8_returns_raw_invalid',
			'filter' => 'MailPreviewTextTest::testInvalidUtf8BytesBecomeJsonSafe|MailPreviewTextTest::testEnsureUtf8DropsOrReinterpretsInvalidSequences|MailPreviewSecurityTest::testCachedPreviewWithInvalidUtf8StillJsonEncodes',
		],
		[
			'file' => $appRoot . '/lib/Controller/ApiControllerTrait.php',
			'from' => 'return new JSONResponse($payload, $status, [], JSON_INVALID_UTF8_SUBSTITUTE);',
			'to' => 'return new JSONResponse($payload, $status);',
			'label' => 'ok_drops_utf8_substitute_flag',
			'filter' => 'MailPreviewSecurityTest::testApiResponsesUseUtf8SubstituteEncodeFlag',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => 'data-crm-mail-reply',
			'to' => 'data-crm-mail-noreply',
			'label' => 'review_loses_answer_button',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Support/MailtoReplyBuilder.php',
			'from' => "if (filter_var(\$email, FILTER_VALIDATE_EMAIL) === false) {\n\t\t\treturn null;\n\t\t}",
			'to' => "if (false && filter_var(\$email, FILTER_VALIDATE_EMAIL) === false) {\n\t\t\treturn null;\n\t\t}",
			'label' => 'mailto_skips_email_filter_validate',
			'filter' => 'MailtoReplyBuilderTest::testRejectsHeaderInjectionAndInvalidEmail',
		],
		[
			'file' => $appRoot . '/appinfo/routes.php',
			'from' => "['name' => 'persona_api#previewMail', 'url' => '/api/v1/workspaces/{workspaceId}/mail/{id}/preview', 'verb' => 'POST'",
			'to' => "['name' => 'persona_api#previewMail', 'url' => '/api/v1/workspaces/{workspaceId}/mail/{id}/preview', 'verb' => 'GET'",
			'label' => 'preview_reverts_to_side_effect_get',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "apiPost('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/preview', {})",
			'to' => "apiGet('/api/v1/workspaces/' + ws + '/mail/' + mailId + '/preview')",
			'label' => 'review_preview_uses_get_again',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/templates/settings/workspace-mailbox.php',
			'from' => 'short plain-text previews cached in CustomerCheck until retention deletes them.',
			'to' => 'messages only.',
			'label' => 'dsgvo_ack_drops_preview_cache_notice',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/SECURITY.md',
			'from' => 'Rotate the shared IMAP/SMTP password within **1 hour**',
			'to' => 'Rotate the shared IMAP/SMTP password within **1 week**',
			'label' => 'ir_sla_weakened_to_one_week',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => 'Part 1 only — never imap_body()',
			'to' => 'Part 1 only — may use imap_body()',
			'label' => 'php_imap_allows_unbounded_body_comment',
			'filter' => 'MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey|ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "if (ImapConnection::isBlockedMailHost(\$host)) {\n\t\t\treturn [\n\t\t\t\t'ok' => false,\n\t\t\t\t'message' => \$this->l10n->t('Use a public mail host — localhost and private network addresses are not allowed.'),\n\t\t\t\t'reason' => 'validation',\n\t\t\t];\n\t\t}",
			'to' => "// mutated: testConnection skips SSRF host block\n\t\t",
			'label' => 'test_connection_skips_ssrf_block',
			'filter' => 'MailPreviewSecurityTest::testCompanyFeedContractDoesNotEmbedPreviewBody',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => "->andWhere(\$qb->expr()->eq('workspace_id', \$qb->createNamedParameter(\$workspaceId)))\n\t\t\t->setMaxResults(1);",
			'to' => "->setMaxResults(1);",
			'label' => 'sync_drops_workspace_bind',
			'filter' => 'MailPreviewSecurityTest::testCompanyFeedContractDoesNotEmbedPreviewBody',
		],
		[
			'file' => $appRoot . '/lib/Support/SharedMailboxSmtpClient.php',
			'from' => "if (ImapConnection::isBlockedMailHost(\$host)) {\n\t\t\tthrow ValidationException::field(\n\t\t\t\t'VALIDATION',\n\t\t\t\t'smtp',\n\t\t\t\t'Use a public mail host — localhost and private network addresses are not allowed.',\n\t\t\t);\n\t\t}",
			'to' => "// mutated: SMTP dialer skips SSRF host block\n\t\t",
			'label' => 'smtp_dialer_skips_ssrf_block',
			'filter' => 'MailPreviewSecurityTest::testCompanyFeedContractDoesNotEmbedPreviewBody',
		],
		[
			'file' => $appRoot . '/lib/Support/ImapConnection.php',
			'from' => "if (preg_match('/[\\x00-\\x1f\\x7f\"\\\\\\\\{}]/u', \$utf8) === 1) {\n\t\t\treturn false;\n\t\t}",
			'to' => "if (preg_match('/[\\x00-\\x1f\\x7f]/u', \$utf8) === 1) {\n\t\t\treturn false;\n\t\t}",
			'label' => 'folder_allows_quote_brace_breakout',
			'filter' => 'ImapConnectionTest::testIsSafeImapFolderRejectsControlChars',
		],
		[
			'file' => $appRoot . '/lib/Support/MailPreviewText.php',
			'from' => "\$hasTag = str_starts_with(\$trimmed, '<')\n\t\t\t|| preg_match('/<[a-z!\\/?][\\s\\S]*>/i', \$sample) === 1;\n\t\tif (!\$hasTag) {\n\t\t\treturn \$raw;\n\t\t}",
			'to' => "\$hasTag = str_contains(strtolower(substr(\$trimmed, 0, 200)), '<html');\n\t\tif (!\$hasTag) {\n\t\t\treturn \$raw;\n\t\t}",
			'label' => 'html_strip_only_full_documents',
			'filter' => 'MailPreviewTextTest::testStripHtmlPublicHelperRejectsMarkup',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxSyncService.php',
			'from' => 'Peek only — charge after a successful fetch so flaky IMAP does not burn quota.',
			'to' => 'Charge immediately before IMAP so flaky IMAP still burns quota.',
			'label' => 'rate_peek_comment_regress',
			'filter' => 'MailPreviewSecurityTest::testArchitecturePreviewSharesSyncLockKey',
		],
		[
			'file' => $appRoot . '/lib/Service/MailboxService.php',
			'from' => 'MailPreviewText::toPlainPreview($preview, Constants::MAIL_PREVIEW_MAX_BYTES)',
			'to' => 'mb_substr($preview, 0, Constants::MAIL_PREVIEW_MAX_BYTES)',
			'label' => 'cache_preview_skips_html_strip',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
		[
			'file' => $appRoot . '/js/pages/persona.js',
			'from' => "    bindMailPreviewActions(body, ws, mount);\n",
			'to' => "    /* mutated: unbound mail preview */\n",
			'label' => 'triage_and_review_lose_preview_bind',
			'filter' => 'ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired',
		],
	];
}

function run_phpunit(string $phpunit, string $appRoot, string $filter): int
{
	$cmd = escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter)
		. ' 2>&1';
	passthru($cmd, $code);

	return (int)$code;
}

function apply_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	if (!str_contains($src, $m['from'])) {
		throw new RuntimeException('mutation needle missing: ' . $m['label']);
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $src));
}

function revert_mutation(array $m): void
{
	$src = (string)file_get_contents($m['file']);
	file_put_contents($m['file'], str_replace($m['to'], $m['from'], $src));
}

$baselineFilter = 'MailPreviewSecurityTest|ReviewInboxUxContractTest::testSharedMailPreviewAndReviewSearchWired|MailPreviewTextTest|MailtoReplyBuilderTest';
fwrite(STDOUT, "BASELINE {$baselineFilter}\n");
if (run_phpunit($phpunit, $appRoot, $baselineFilter) !== 0) {
	fwrite(STDERR, "BASELINE FAIL — refusing to run mutations while mail-preview suite is red\n");
	exit(1);
}

$killed = 0;
$failed = 0;
foreach (mail_preview_mutations($appRoot) as $m) {
	apply_mutation($m);
	$code = run_phpunit($phpunit, $appRoot, $m['filter']);
	revert_mutation($m);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED mutation {$m['label']} — tests did not catch it\n");
		$failed++;
	} else {
		fwrite(STDOUT, "killed {$m['label']}\n");
		$killed++;
	}
}

$total = $killed + $failed;
$msi = $total > 0 ? (int)round(100 * $killed / $total) : 0;
fwrite(STDOUT, "MSI (mail-preview): {$msi}% killed={$killed} survived={$failed}\n");
exit($failed > 0 ? 1 : 0);
