<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

use OCA\CustomerCheck\Constants;
use OCA\CustomerCheck\Support\ImapLiveProbe;

/**
 * Detect original sender hints in forwarded / resent mail.
 *
 * Security: body “From:” lines are only trusted inside known forward markers or
 * Outlook-style Von/Gesendet/An blocks — never bare signatures, never team/shared
 * addresses, and only as a fallback after envelope From/To matching.
 */
final class MailForwardLogic
{
	/** @var list<string> */
	private const FORWARD_MARKERS = [
		'begin forwarded message',
		'---------- forwarded message',
		'-------- forwarded message --------',
		'-----original message-----',
		'-----weitergeleitete nachricht-----',
		'----- ursprüngliche nachricht -----',
		'-----ursprüngliche nachricht-----',
		'---------- weitergeleitete nachricht',
		'-------- weitergeleitete nachricht --------',
		'weitergeleitete nachricht ----------',
		'forwarded message:',
		'weitergeleitete nachricht:',
	];

	/** IMAP HEADER.FIELDS list — keep parseRfc822Headers and FETCH in sync. */
	public const REDIRECT_HEADER_FIELDS = 'FROM TO CC REPLY-TO RESENT-FROM X-ORIGINAL-FROM SUBJECT DATE MESSAGE-ID';

	public static function subjectLooksForwarded(string $subject): bool
	{
		$s = trim($subject);
		if ($s === '') {
			return false;
		}
		// EN/DE/FR/IT/NL/ES/PT common forward prefixes (incl. nested Fwd: Fwd:).
		return preg_match(
			'/^(?:(?:fwd?|wg|tr|rv|enc|i)\s*:\s*)+/iu',
			$s,
		) === 1;
	}

	/**
	 * @param list<string> $to
	 * @param list<string> $cc
	 * @param list<string> $excludeEmails
	 * @param list<string> $headerHints Actionable redirect hints (X-Original-From, Reply-To) — not Resent-From
	 */
	public static function needsBodyProbe(
		string $fromAddress,
		array $to,
		array $cc,
		string $subject,
		array $excludeEmails,
		array $headerHints = [],
	): bool {
		if (self::mergeHints($headerHints, [], $excludeEmails) !== []) {
			return false;
		}
		$exclude = MailMatchLogic::emailLookup($excludeEmails);
		$from = strtolower(trim($fromAddress));
		$fromIsTeam = $from !== '' && isset($exclude[$from]);
		$fwdSubject = self::subjectLooksForwarded($subject);
		if (!$fromIsTeam && !$fwdSubject) {
			return false;
		}
		$customerRecipients = 0;
		foreach (array_merge($to, $cc) as $raw) {
			$email = strtolower(trim((string)$raw));
			if ($email === '' || !str_contains($email, '@') || isset($exclude[$email])) {
				continue;
			}
			$customerRecipients++;
		}
		// Classic forward: team → shared only (no customer on To/CC).
		if ($customerRecipients === 0) {
			return true;
		}
		// Nested forward still worth probing when subject says Fwd:.
		return $fwdSubject && $fromIsTeam;
	}

	/**
	 * Redirect headers that may identify the customer when envelope From is team.
	 *
	 * RFC 5322 Resent-From is the redirector (team) — never treated as customer hint.
	 * Thunderbird 91+ redirect often sets Reply-To to the original author.
	 * Some gateways set X-Original-From.
	 *
	 * @return list<string>
	 */
	public static function hintsFromRedirectHeaders(?string $xOriginalFrom, ?string $replyTo): array
	{
		$out = [];
		foreach ([$xOriginalFrom, $replyTo] as $raw) {
			if ($raw === null || trim($raw) === '') {
				continue;
			}
			foreach (ImapLiveProbe::extractEmailAddresses($raw) as $email) {
				$out[$email] = true;
			}
			$single = ImapLiveProbe::extractEmailAddress($raw);
			if ($single !== '' && str_contains($single, '@')) {
				$out[strtolower($single)] = true;
			}
		}

		return array_keys($out);
	}

	/**
	 * @deprecated Resent-From is the redirector per RFC 5322 — use hintsFromRedirectHeaders().
	 * @return list<string>
	 */
	public static function hintsFromResentHeaders(?string $resentFrom, ?string $xOriginalFrom): array
	{
		unset($resentFrom);

		return self::hintsFromRedirectHeaders($xOriginalFrom, null);
	}

	/**
	 * Extract customer sender hints from a capped plain-text mail prefix.
	 *
	 * @param list<string> $excludeEmails Shared-mailbox + workspace-member addresses
	 * @return list<string>
	 */
	public static function hintsFromBodyPrefix(string $plainText, array $excludeEmails = []): array
	{
		$text = self::capPlainText($plainText);
		if ($text === '') {
			return [];
		}

		$outlook = self::hintsFromOutlookBlocks($text, $excludeEmails);
		if ($outlook !== []) {
			return $outlook;
		}

		return self::hintsFromForwardMarker($text, $excludeEmails);
	}

	/**
	 * Merge header + body hints, drop team/shared identities.
	 *
	 * @param list<string> $headerHints
	 * @param list<string> $bodyHints
	 * @param list<string> $excludeEmails
	 * @return list<string>
	 */
	public static function mergeHints(array $headerHints, array $bodyHints, array $excludeEmails): array
	{
		$exclude = MailMatchLogic::emailLookup($excludeEmails);
		$out = [];
		foreach (array_merge($headerHints, $bodyHints) as $raw) {
			$email = strtolower(trim((string)$raw));
			if ($email === '' || !str_contains($email, '@') || isset($exclude[$email])) {
				continue;
			}
			$out[$email] = true;
		}

		return array_keys($out);
	}

	/**
	 * Stored hints from ingest, or re-extract from preview for older rows.
	 *
	 * @param list<string> $excludeEmails
	 * @return list<string>
	 */
	public static function hintsForRematch(?string $storedHintsJson, ?string $preview, array $excludeEmails = []): array
	{
		$raw = trim((string)$storedHintsJson);
		if ($raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				$stored = array_values(array_filter(array_map('strval', $decoded)));
				if ($stored !== []) {
					return self::mergeHints($stored, [], $excludeEmails);
				}
			}
		}
		$plain = trim((string)$preview);
		if ($plain === '') {
			return [];
		}

		return self::hintsFromBodyPrefix($plain, $excludeEmails);
	}

	/**
	 * Outlook / Exchange style blocks without an explicit “forwarded message” marker:
	 *   Von: … \n Gesendet: … \n An: …
	 *
	 * When Von is team, An may hold the customer (quoted outbound copy).
	 *
	 * @param list<string> $excludeEmails
	 * @return list<string>
	 */
	private static function hintsFromOutlookBlocks(string $text, array $excludeEmails): array
	{
		$exclude = MailMatchLogic::emailLookup($excludeEmails);
		$blocks = [];
		preg_match_all(
			'/(?:^|\n)[ \t]*(?:From|Von)\s*:\s*(.+?)[ \t]*\r?\n[ \t]*(?:Sent|Gesendet|Date|Datum)\s*:[^\r\n]*\r?\n[ \t]*(?:To|An)\s*:\s*(.+?)(?:\r?\n|$)/ius',
			$text,
			$blocks,
			PREG_SET_ORDER,
		);
		foreach ($blocks as $block) {
			$hit = self::outlookBlockHint($block[1], $block[2], $exclude);
			if ($hit !== null) {
				return [$hit];
			}
		}

		// Some Outlook builds omit Sent/Gesendet between Von and An.
		if (preg_match_all(
			'/(?:^|\n)[ \t]*(?:From|Von)\s*:\s*(.+?)[ \t]*\r?\n[ \t]*(?:To|An)\s*:\s*(.+?)(?:\r?\n|$)/ius',
			$text,
			$pairs,
			PREG_SET_ORDER,
		) !== false) {
			foreach ($pairs as $pair) {
				$hit = self::outlookBlockHint($pair[1], $pair[2], $exclude);
				if ($hit !== null) {
					return [$hit];
				}
			}
		}

		return [];
	}

	/**
	 * @param array<string, true> $exclude
	 */
	private static function outlookBlockHint(string $fromRaw, string $toRaw, array $exclude): ?string
	{
		$fromEmail = ImapLiveProbe::extractEmailAddress($fromRaw);
		$toEmail = ImapLiveProbe::extractEmailAddress($toRaw);
		if ($fromEmail !== '' && str_contains($fromEmail, '@') && !isset($exclude[$fromEmail])) {
			return $fromEmail;
		}
		if ($fromEmail !== '' && isset($exclude[$fromEmail]) && $toEmail !== '' && str_contains($toEmail, '@') && !isset($exclude[$toEmail])) {
			return $toEmail;
		}

		return null;
	}

	/**
	 * Gmail / Apple style: marker line, then From/Von. Skips team From lines and
	 * nested quoted blocks when an Outlook-style triple is present after the marker.
	 *
	 * @param list<string> $excludeEmails
	 * @return list<string>
	 */
	private static function hintsFromForwardMarker(string $text, array $excludeEmails): array
	{
		$lower = mb_strtolower($text);
		$markerPos = false;
		foreach (self::FORWARD_MARKERS as $marker) {
			$pos = mb_strpos($lower, $marker);
			if ($pos === false) {
				continue;
			}
			if ($markerPos === false || $pos < $markerPos) {
				$markerPos = $pos;
			}
		}
		if ($markerPos === false) {
			return [];
		}
		$after = mb_substr($text, (int)$markerPos);
		$outlook = self::hintsFromOutlookBlocks($after, $excludeEmails);
		if ($outlook !== []) {
			return $outlook;
		}

		$exclude = MailMatchLogic::emailLookup($excludeEmails);
		if (preg_match_all(
			'/(?:^|\n)[ \t]*(?:From|Von|De|Da|Afzender)\s*:\s*(.+)/iu',
			$after,
			$matches,
		) === false || !isset($matches[1])) {
			return [];
		}
		foreach ($matches[1] as $line) {
			$email = ImapLiveProbe::extractEmailAddress((string)$line);
			if ($email === '' || !str_contains($email, '@') || isset($exclude[$email])) {
				continue;
			}

			return [$email];
		}

		return self::hintsFromForwardSectionEmails($after, $excludeEmails);
	}

	/**
	 * When Thunderbird-style forwards use display names only on From/To lines,
	 * pick the first non-team mailbox in the forward section before nested quotes.
	 *
	 * @param list<string> $excludeEmails
	 * @return list<string>
	 */
	private static function hintsFromForwardSectionEmails(string $textAfterMarker, array $excludeEmails): array
	{
		$exclude = MailMatchLogic::emailLookup($excludeEmails);
		$section = self::forwardSectionBeforeNestedQuote($textAfterMarker);
		if ($section === '') {
			return [];
		}
		foreach (ImapLiveProbe::extractEmailAddresses($section) as $raw) {
			$email = strtolower(trim($raw));
			if ($email === '' || !str_contains($email, '@') || isset($exclude[$email])) {
				continue;
			}

			return [$email];
		}

		return [];
	}

	private static function forwardSectionBeforeNestedQuote(string $text): string
	{
		$cut = strlen($text);
		$patterns = [
			'/\n[ \t]*\*?(?:From|Von|De|Da|Afzender)\*?\s*:[^\n]*\n[ \t]*\*?(?:Sent|Gesendet|Date|Datum)\*?\s*:/iu',
			'/\n-{5,}/',
			'/\n[ \t]*(?:Begin forwarded message|-----original message-----)/iu',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
				$cut = min($cut, (int)$m[0][1]);
			}
		}

		return substr($text, 0, $cut);
	}

	/**
	 * Email address Assign should score against — embedded customer on forwards, not team envelope.
	 *
	 * @param list<string> $excludeEmails
	 */
	public static function customerEmailForAssignSuggest(
		string $envelopeFrom,
		?string $preview,
		?string $storedHintsJson,
		array $excludeEmails,
	): string {
		$hints = self::hintsForRematch($storedHintsJson, $preview, $excludeEmails);
		if ($hints !== []) {
			return $hints[0];
		}
		$from = MailMatchLogic::normalizeFromAddress($envelopeFrom);
		if ($from === '' || !str_contains($from, '@')) {
			return '';
		}
		$exclude = MailMatchLogic::emailLookup($excludeEmails);
		$teamDomains = MailMatchLogic::teamDomainsFromLookup($exclude);
		$domain = MailMatchLogic::domainOf($from);
		if (isset($exclude[$from]) || ($domain !== null && isset($teamDomains[$domain]))) {
			return '';
		}

		return $from;
	}

	private static function capPlainText(string $plainText): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $plainText);
		if (strlen($text) > Constants::MAIL_FORWARD_BODY_PEEK_BYTES) {
			$text = substr($text, 0, Constants::MAIL_FORWARD_BODY_PEEK_BYTES);
		}

		return $text;
	}
}
