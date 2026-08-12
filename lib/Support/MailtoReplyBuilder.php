<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Support;

/**
 * Build a safe mailto / Mail-app reply pack from indexed shared-mail fields.
 *
 * CustomerCheck is not a mail client: this never sends mail. It only prepares
 * the operator's local mail program (or Nextcloud Mail compose) with To + Re: subject
 * and a short quoted snippet. Full MIME threading (In-Reply-To) is not available via mailto.
 */
final class MailtoReplyBuilder
{
	/** Keep mailto URLs under common client/OS limits. */
	public const BODY_MAX_CHARS = 1200;

	public const SUBJECT_MAX_CHARS = 200;

	/**
	 * Extract a single email from a From header / display string.
	 * Rejects newlines and credential-like junk that could break mailto.
	 */
	public static function extractEmail(string $fromHeader): ?string
	{
		$fromHeader = trim($fromHeader);
		if ($fromHeader === '' || preg_match('/[\x00-\x1F\x7F]/', $fromHeader) === 1) {
			return null;
		}
		$email = $fromHeader;
		if (preg_match('/<([^>]+)>/', $fromHeader, $m) === 1) {
			$email = trim($m[1]);
		}
		$email = trim($email);
		// Strip optional mailto: prefix from calendar-style values.
		if (str_starts_with(strtolower($email), 'mailto:')) {
			$email = substr($email, 7);
		}
		$email = trim($email, " \t\"'");
		if ($email === '' || strlen($email) > 254) {
			return null;
		}
		if (preg_match('/[\s<>"\',;?&=#]/', $email) === 1) {
			return null;
		}
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		return $email;
	}

	public static function replySubject(string $subject): string
	{
		$subject = MimeHeader::decode(trim($subject));
		$subject = preg_replace('/[\x00-\x1F\x7F]/', '', $subject) ?? '';
		$subject = trim($subject);
		if ($subject === '') {
			$subject = 'Your message';
		}
		if (!preg_match('/^\s*re\s*:/i', $subject)) {
			$subject = 'Re: ' . $subject;
		}
		if (mb_strlen($subject) > self::SUBJECT_MAX_CHARS) {
			$subject = mb_substr($subject, 0, self::SUBJECT_MAX_CHARS - 1) . '…';
		}

		return $subject;
	}

	/**
	 * Short plain-text quote for the compose body (never HTML).
	 */
	public static function replyBody(string $fromHeader, string $subject, string $snippetOrPreview): string
	{
		$fromHeader = preg_replace('/[\x00-\x1F\x7F]/', '', trim($fromHeader)) ?? '';
		$subject = MimeHeader::decode(trim($subject));
		$subject = preg_replace('/[\x00-\x1F\x7F]/', '', $subject) ?? '';
		$quote = MailPreviewText::toPlainPreview($snippetOrPreview, self::BODY_MAX_CHARS);
		$quote = trim($quote);
		if ($quote !== '') {
			$lines = preg_split("/\n/", $quote) ?: [];
			$quoted = [];
			foreach ($lines as $line) {
				$quoted[] = '> ' . $line;
			}
			$quote = implode("\n", $quoted);
		}

		$parts = [
			'',
			'---',
		];
		if ($fromHeader !== '') {
			$parts[] = 'From: ' . $fromHeader;
		}
		if ($subject !== '') {
			$parts[] = 'Subject: ' . $subject;
		}
		$parts[] = '';
		if ($quote !== '') {
			$parts[] = $quote;
		}

		$body = implode("\n", $parts);
		if (mb_strlen($body) > self::BODY_MAX_CHARS) {
			$body = mb_substr($body, 0, self::BODY_MAX_CHARS - 1) . '…';
		}

		return $body;
	}

	/**
	 * @return array{to:string,subject:string,bodyText:string,mailtoUrl:string}|null null when To is unusable
	 */
	public static function build(string $fromHeader, string $subject, string $snippetOrPreview = ''): ?array
	{
		$to = self::extractEmail($fromHeader);
		if ($to === null) {
			return null;
		}
		$reSubject = self::replySubject($subject);
		$body = self::replyBody($fromHeader, $subject, $snippetOrPreview);
		$mailto = 'mailto:' . rawurlencode($to)
			. '?subject=' . rawurlencode($reSubject)
			. '&body=' . rawurlencode($body);

		return [
			'to' => $to,
			'subject' => $reSubject,
			'bodyText' => $body,
			'mailtoUrl' => $mailto,
		];
	}
}
