<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Service;

use OCA\UserImapDomains\Model\DomainMapping;
use OCP\IL10N;
use OCP\Util;

/**
 * Validate settings submitted in the admin settings
 *
 * All methods return translated error messages; an empty list means valid.
 */
class DomainMappingValidator {
	/** Characters allowed in the IMAP user prefix (lowercase, part of user IDs) */
	private const PREFIX_PATTERN = '/^[a-z0-9_.\'-]*$/';
	/** Maximum length of a Nextcloud group ID */
	private const MAX_GROUP_ID_LENGTH = 64;

	public function __construct(
		private readonly IL10N $l,
	) {
	}

	/**
	 * @param list<DomainMapping> $mappings
	 *
	 * @return list<string>
	 */
	public function validateMappings(array $mappings): array {
		$errors = [];
		$seenDomains = [];
		$stripDomainMappings = [];

		foreach ($mappings as $index => $mapping) {
			$label = $mapping->emailDomain !== ''
				? $mapping->emailDomain
				: $this->l->t('Domain %d', [$index + 1]);

			if (!$mapping->isComplete()) {
				$errors[] = $this->l->t('%s: email domain and IMAP server are required', [$label]);
				continue;
			}
			if (!$this->isHostname($mapping->emailDomain)) {
				$errors[] = $this->l->t('%s: the email domain is not a valid domain name', [$label]);
			}
			if (!$this->isHostname($mapping->imapHost, requireDot: false) && filter_var($mapping->imapHost, FILTER_VALIDATE_IP) === false) {
				$errors[] = $this->l->t('%s: the IMAP server is not a valid hostname or IP address', [$label]);
			}
			if ($mapping->imapUserDomain !== '' && !$this->isHostname($mapping->imapUserDomain)) {
				$errors[] = $this->l->t('%s: the IMAP login domain is not a valid domain name', [$label]);
			}
			if (preg_match(self::PREFIX_PATTERN, $mapping->imapUserPrefix) !== 1) {
				$errors[] = $this->l->t('%s: the IMAP user prefix may only contain lowercase letters, digits and the characters _ . \' -', [$label]);
			}
			array_push($errors, ...$this->validateGroups($mapping->groups, $label));
			if (!$this->isValidQuota($mapping->quota)) {
				$errors[] = $this->l->t('%s: invalid quota "%s"', [$label, $mapping->quota]);
			}

			if (isset($seenDomains[$mapping->emailDomain])) {
				$errors[] = $this->l->t('The email domain %s is configured more than once', [$mapping->emailDomain]);
			}
			$seenDomains[$mapping->emailDomain] = true;

			if ($mapping->stripDomain) {
				$stripDomainMappings[] = $mapping->emailDomain;
			}
		}

		if (count($stripDomainMappings) > 1) {
			// Otherwise "max" of two domains would be the same Nextcloud user
			$errors[] = $this->l->t('Only one domain can use user IDs without domain, currently: %s', [implode(', ', $stripDomainMappings)]);
		}

		return $errors;
	}

	/**
	 * @param list<string> $groups
	 *
	 * @return list<string>
	 */
	public function validateGlobalSettings(string $defaultQuota, array $groups): array {
		$errors = $this->validateGroups($groups, $this->l->t('General settings'));
		if (!$this->isValidQuota($defaultQuota)) {
			$errors[] = $this->l->t('Invalid default quota "%s"', [$defaultQuota]);
		}
		return $errors;
	}

	/**
	 * @param list<string> $groups
	 *
	 * @return list<string>
	 */
	private function validateGroups(array $groups, string $label): array {
		$errors = [];
		foreach ($groups as $group) {
			if (mb_strlen($group) > self::MAX_GROUP_ID_LENGTH) {
				$errors[] = $this->l->t('%s: the group name "%s" is too long', [$label, $group]);
			}
		}
		return $errors;
	}

	/**
	 * Empty (use default), "none" (unlimited), "default" or a size like "2 GB"
	 */
	private function isValidQuota(string $quota): bool {
		return $quota === ''
			|| in_array($quota, ['none', 'default'], true)
			|| Util::computerFileSize($quota) !== false;
	}

	/**
	 * @param bool $requireDot false allows single-label names like "localhost" (IMAP servers in internal networks)
	 */
	private function isHostname(string $value, bool $requireDot = true): bool {
		return (!$requireDot || str_contains($value, '.'))
			&& filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
	}
}
