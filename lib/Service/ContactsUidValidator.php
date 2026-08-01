<?php

declare(strict_types=1);

namespace OCA\CustomerCheck\Service;

/**
 * Contacts UID validation + Tasks deep-link builder — CC §9.5.6 / §16.2.
 */
class ContactsUidValidator
{
	/**
	 * @return string|null normalised uid, or null to clear
	 * @throws \OCA\CustomerCheck\Exception\ValidationException
	 */
	public function normalize(?string $uid, \OCP\IL10N $l10n): ?string
	{
		if ($uid === null) {
			return null;
		}
		$uid = trim($uid);
		if ($uid === '') {
			return null;
		}
		if (mb_strlen($uid) > 255) {
			throw \OCA\CustomerCheck\Exception\ValidationException::field(
				\OCA\CustomerCheck\Constants::ERR_CONTACTS_UID_INVALID,
				'contactsUid',
				$l10n->t('The Contacts link is too long.'),
			);
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $uid) === 1) {
			throw \OCA\CustomerCheck\Exception\ValidationException::field(
				\OCA\CustomerCheck\Constants::ERR_CONTACTS_UID_INVALID,
				'contactsUid',
				$l10n->t('The Contacts link contains invalid characters.'),
			);
		}

		return $uid;
	}
}
