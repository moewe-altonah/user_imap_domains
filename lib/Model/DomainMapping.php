<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Model;

/**
 * Configuration of one email domain and the IMAP server it authenticates against
 *
 * This is the only place where stored mapping data is normalized and where
 * defaults are applied. The array form ({@see self::toArray()}) is the
 * format stored in the app config and accepted by `occ config:app:set`.
 */
final class DomainMapping {
	/**
	 * @param string $emailDomain Domain users enter after the @ when logging in (lowercase)
	 * @param string $imapHost Hostname or IP address of the IMAP server
	 * @param int $imapPort Port of the IMAP server
	 * @param SslMode $sslMode Transport security of the IMAP connection
	 * @param string $imapUserPrefix Prefix put in front of the local part for the IMAP login
	 * @param string $imapUserDomain Domain used for the IMAP login if it differs from the email domain, '' otherwise
	 * @param list<string> $groups Nextcloud groups new users of this domain are added to
	 * @param string $quota Quota for new users of this domain, '' to use the global default quota
	 * @param bool $stripDomain Whether Nextcloud user IDs omit the domain and users may log in without it
	 */
	public function __construct(
		public readonly string $emailDomain,
		public readonly string $imapHost,
		public readonly int $imapPort,
		public readonly SslMode $sslMode,
		public readonly string $imapUserPrefix = '',
		public readonly string $imapUserDomain = '',
		public readonly array $groups = [],
		public readonly string $quota = '',
		public readonly bool $stripDomain = false,
	) {
	}

	/**
	 * Create a mapping from its stored or submitted array form
	 *
	 * Unknown or invalid values fall back to their defaults; required
	 * fields that are missing result in empty strings, check them with
	 * {@see self::isComplete()}.
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function fromArray(array $data): self {
		$sslMode = SslMode::tryFrom(self::string($data, 'imap_sslmode')) ?? SslMode::Ssl;

		$port = (int)($data['imap_port'] ?? 0);
		if ($port < 1 || $port > 65535) {
			$port = $sslMode->defaultPort();
		}

		$stripDomain = filter_var($data['strip_domain'] ?? false, FILTER_VALIDATE_BOOLEAN);

		return new self(
			emailDomain: mb_strtolower(self::string($data, 'email_domain')),
			imapHost: self::string($data, 'imap_server'),
			imapPort: $port,
			sslMode: $sslMode,
			imapUserPrefix: self::string($data, 'imap_user_prefix'),
			imapUserDomain: mb_strtolower(self::string($data, 'imap_user_domain')),
			groups: self::parseGroupList($data['groups'] ?? []),
			quota: self::string($data, 'quota'),
			stripDomain: $stripDomain,
		);
	}

	/**
	 * Array form for storage and for the admin settings page
	 *
	 * @return array{email_domain: string, imap_server: string, imap_port: int, imap_sslmode: string, imap_user_prefix: string, imap_user_domain: string, groups: list<string>, quota: string, strip_domain: bool}
	 */
	public function toArray(): array {
		return [
			'email_domain' => $this->emailDomain,
			'imap_server' => $this->imapHost,
			'imap_port' => $this->imapPort,
			'imap_sslmode' => $this->sslMode->value,
			'imap_user_prefix' => $this->imapUserPrefix,
			'imap_user_domain' => $this->imapUserDomain,
			'groups' => $this->groups,
			'quota' => $this->quota,
			'strip_domain' => $this->stripDomain,
		];
	}

	/**
	 * Whether all required fields are set
	 */
	public function isComplete(): bool {
		return $this->emailDomain !== '' && $this->imapHost !== '';
	}

	/**
	 * Domain used for the IMAP login: the override if set, the email domain otherwise
	 */
	public function imapLoginDomain(): string {
		return $this->imapUserDomain !== '' ? $this->imapUserDomain : $this->emailDomain;
	}

	/**
	 * IMAP login name for a local part, e.g. "max.mustermann" -> "prefix-max.mustermann@imap.example.org"
	 *
	 * The same address is stored as email address of new users.
	 */
	public function imapLogin(string $localPart): string {
		return $this->imapUserPrefix . $localPart . '@' . $this->imapLoginDomain();
	}

	/**
	 * Nextcloud user ID for a local part
	 *
	 * With strip_domain the ID has no domain (prefix + local part),
	 * otherwise it equals the IMAP login.
	 */
	public function userId(string $localPart): string {
		return $this->stripDomain
			? $this->imapUserPrefix . $localPart
			: $this->imapLogin($localPart);
	}

	/**
	 * Inverse of {@see self::userId()}: the local part for a user ID of this
	 * mapping, or null if the user ID does not belong to this mapping
	 */
	public function localPartFromUserId(string $userId): ?string {
		$suffix = $this->stripDomain ? '' : '@' . $this->imapLoginDomain();
		if (!str_starts_with($userId, $this->imapUserPrefix)
			|| ($suffix !== '' && !str_ends_with($userId, $suffix))) {
			return null;
		}

		$localPart = substr($userId, strlen($this->imapUserPrefix), strlen($userId) - strlen($this->imapUserPrefix) - strlen($suffix));
		if ($localPart === '' || str_contains($localPart, '@')) {
			return null;
		}
		return $localPart;
	}

	/**
	 * Server URL for curl, e.g. "imaps://imap.example.org:993"
	 */
	public function serverUrl(): string {
		// IPv6 addresses need brackets in URLs
		$host = filter_var($this->imapHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
			? '[' . $this->imapHost . ']'
			: $this->imapHost;
		return $this->sslMode->urlScheme() . '://' . $host . ':' . $this->imapPort;
	}

	/**
	 * Parse a comma-separated group list or a list of group names
	 * into a trimmed list of unique, non-empty group names
	 *
	 * @return list<string>
	 */
	public static function parseGroupList(mixed $value): array {
		if (is_array($value)) {
			$groups = array_map(static fn (mixed $group): string => is_scalar($group) ? (string)$group : '', $value);
		} else {
			$groups = explode(',', is_scalar($value) ? (string)$value : '');
		}
		$groups = array_map(trim(...), $groups);
		$groups = array_filter($groups, static fn (string $group): bool => $group !== '');
		return array_values(array_unique($groups));
	}

	/**
	 * Read a trimmed string value from submitted or stored data
	 *
	 * @param array<array-key, mixed> $data
	 */
	private static function string(array $data, string $key): string {
		$value = $data[$key] ?? '';
		return is_scalar($value) ? trim((string)$value) : '';
	}
}
