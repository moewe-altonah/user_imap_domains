<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Backend;

use OCA\UserImapDomains\Db\UserStore;
use OCA\UserImapDomains\AppInfo\Application;
use OCA\UserImapDomains\Model\DomainMapping;
use OCA\UserImapDomains\Model\SslMode;
use OCA\UserImapDomains\Service\DomainConfigService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IGetRealUIDBackend;
use OCP\User\Backend\ILimitAwareCountUsersBackend;
use OCP\User\Backend\ISetDisplayNameBackend;
use Psr\Log\LoggerInterface;

/**
 * User backend that authenticates users against the IMAP server of their email domain
 *
 * Users are stored in the app's own table ({@see UserStore}). On the first
 * successful login a user is created, added to the configured groups and
 * gets display name, email address and quota.
 *
 * @psalm-import-type StoredUser from UserStore
 */
class ImapUserBackend extends ABackend implements
	ICheckPasswordBackend,
	IGetDisplayNameBackend,
	ISetDisplayNameBackend,
	ILimitAwareCountUsersBackend,
	IGetRealUIDBackend {

	/** Seconds to wait for the TCP/TLS connection to the IMAP server */
	private const CONNECT_TIMEOUT = 10;
	/** Seconds the whole IMAP login may take */
	private const REQUEST_TIMEOUT = 20;

	/** curl error codes for connection problems (not all have PHP constants) */
	private const CURL_CONNECTION_ERRORS = [
		6,  // CURLE_COULDNT_RESOLVE_HOST
		7,  // CURLE_COULDNT_CONNECT
		28, // CURLE_OPERATION_TIMEDOUT
		35, // CURLE_SSL_CONNECT_ERROR
		60, // CURLE_PEER_FAILED_VERIFICATION
		64, // CURLE_USE_SSL_FAILED (server does not offer STARTTLS)
	];
	/** curl error codes for rejected credentials */
	private const CURL_LOGIN_ERRORS = [
		9,  // CURLE_REMOTE_ACCESS_DENIED
		67, // CURLE_LOGIN_DENIED
		94, // CURLE_AUTH_ERROR
	];

	/** Characters allowed in the local part, a subset of what Nextcloud allows in user IDs */
	private const LOCAL_PART_PATTERN = '/^[a-z0-9_.\'-]+$/';
	/** Characters Nextcloud allows in user IDs, see \OC\User\Manager::validateUserId() */
	private const USER_ID_PATTERN = '/^[a-zA-Z0-9 _.@\-\']+$/';

	public function __construct(
		private readonly UserStore $userStore,
		private readonly DomainConfigService $domainConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Name shown as backend in the user management: the full class name,
	 * like for backends configured in config.php
	 */
	public function getBackendName(): string {
		return self::class;
	}

	// User listing and display names

	public function userExists($uid): bool {
		return $this->userStore->exists((string)$uid);
	}

	public function getRealUID(string $uid): string {
		return $this->userStore->find($uid)['uid'] ?? $uid;
	}

	public function getDisplayName($uid): string {
		$user = $this->userStore->find((string)$uid);
		if ($user === null) {
			return (string)$uid;
		}
		return trim($user['display_name']) !== '' ? $user['display_name'] : $user['uid'];
	}

	public function setDisplayName(string $uid, string $displayName): bool {
		return $this->userStore->exists($uid)
			&& $this->userStore->setDisplayName($this->getRealUID($uid), $displayName);
	}

	/**
	 * @return array<array-key, string> Display name by user ID (numeric IDs become integer keys)
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		$users = $this->userStore->search((string)$search, $limit, $offset);
		foreach ($users as $uid => $displayName) {
			$users[$uid] = trim($displayName) !== '' ? $displayName : (string)$uid;
		}
		return $users;
	}

	/**
	 * @return list<string>
	 */
	public function getUsers($search = '', $limit = null, $offset = null): array {
		return array_map(strval(...), array_keys($this->userStore->search((string)$search, $limit, $offset)));
	}

	public function hasUserListings(): bool {
		return true;
	}

	public function countUsers(int $limit = 0): int {
		return $this->userStore->count($limit);
	}

	/**
	 * Remove the user from this backend; Nextcloud deletes the user's data
	 */
	public function deleteUser($uid): bool {
		return $this->userStore->delete($this->getRealUID((string)$uid));
	}

	/**
	 * Check the password against the IMAP server without logging in the user
	 *
	 * Accepted login names:
	 * - the email address "local@email_domain" of a configured domain
	 * - only "local" if one domain has strip_domain enabled
	 * - the user ID or the stored email address of an existing user of this backend
	 *
	 * @param string $loginName The name entered at login (Nextcloud passes it as $uid)
	 * @param string $password The password
	 *
	 * @return false|string The Nextcloud user ID on success, false otherwise
	 */
	public function checkPassword(string $loginName, string $password): false|string {
		if ($password === '') {
			return false;
		}

		// Some clients send the @ of the email address URL-encoded
		if (!str_contains($loginName, '@') && str_contains($loginName, '%40')) {
			$loginName = str_replace('%40', '@', $loginName);
		}
		$loginName = mb_strtolower(trim($loginName));

		$target = $this->resolveExistingUser($loginName) ?? $this->resolveLoginAddress($loginName);
		if ($target === null) {
			return false;
		}
		[$mapping, $localPart, $storedUser] = $target;

		$userId = $storedUser['uid'] ?? $mapping->userId($localPart);
		if ($storedUser === null) {
			if (!$this->isValidUserId($userId)) {
				$this->logger->info('Login {login} results in the invalid user ID {user}', [
					'app' => Application::APP_ID,
					'login' => $loginName,
					'user' => $userId,
				]);
				return false;
			}
			if ($this->userManager->userExists($userId)) {
				// Never hand out an existing account (e.g. a local admin, or a user
				// created from another domain) to whoever owns a mailbox with the same name
				$this->logger->error('User ID {user} is already used by another account, IMAP login refused', [
					'app' => Application::APP_ID,
					'user' => $userId,
				]);
				return false;
			}
		}

		if (!$this->authenticateImap($mapping, $mapping->imapLogin($localPart), $password)) {
			return false;
		}

		if ($storedUser === null) {
			$this->createUser($userId, $localPart, $mapping);
			return $userId;
		}

		if ($storedUser['email_domain'] === null) {
			// Imported from user_external: remember the origin for the next logins
			$this->userStore->setOrigin($userId, $mapping->emailDomain, $localPart);
		}
		return $userId;
	}

	/**
	 * Interpret the login name as user ID or email address of an existing user
	 *
	 * Uses the domain and local part stored for the user; users imported from
	 * user_external have none, their ID is parsed instead. This avoids
	 * applying the IMAP user prefix twice when a user logs in with the user
	 * ID or (since Nextcloud 32) the email address stored in Nextcloud.
	 *
	 * @return array{0: DomainMapping, 1: string, 2: StoredUser}|null Mapping, local part and user, null if no existing user matches
	 */
	private function resolveExistingUser(string $loginName): ?array {
		$user = $this->userStore->find($loginName) ?? $this->findUserByEmail($loginName);
		if ($user === null) {
			return null;
		}

		if ($user['email_domain'] !== null && $user['local_part'] !== null) {
			$mapping = $this->domainConfig->findMappingByEmailDomain($user['email_domain']);
			return $mapping !== null ? [$mapping, $user['local_part'], $user] : null;
		}

		foreach ($this->domainConfig->getDomainMappings() as $mapping) {
			$localPart = $mapping->localPartFromUserId($user['uid']);
			if ($localPart !== null) {
				return [$mapping, $localPart, $user];
			}
		}
		return null;
	}

	/**
	 * Since Nextcloud 32 users can log in with their email address, which is
	 * then passed instead of the user ID
	 *
	 * @return StoredUser|null
	 */
	private function findUserByEmail(string $loginName): ?array {
		if (!str_contains($loginName, '@')) {
			return null;
		}
		foreach ($this->userManager->getByEmail($loginName) as $user) {
			$storedUser = $this->userStore->find($user->getUID());
			if ($storedUser !== null) {
				return $storedUser;
			}
		}
		return null;
	}

	/**
	 * Interpret the login name as "local@email_domain" or, for the domain
	 * with strip_domain enabled, as "local"
	 *
	 * The user is null for a new user. Existing users are found by their
	 * origin, so they keep their user ID if the domain settings change.
	 *
	 * @return array{0: DomainMapping, 1: string, 2: StoredUser|null}|null Mapping, local part and user, null if no mapping matches
	 */
	private function resolveLoginAddress(string $loginName): ?array {
		$atPosition = strrpos($loginName, '@');
		if ($atPosition !== false) {
			$localPart = substr($loginName, 0, $atPosition);
			$mapping = $this->domainConfig->findMappingByEmailDomain(substr($loginName, $atPosition + 1));
		} else {
			$localPart = $loginName;
			$mapping = $this->domainConfig->findDefaultMapping();
		}

		if ($mapping === null || !$mapping->isComplete() || preg_match(self::LOCAL_PART_PATTERN, $localPart) !== 1) {
			return null;
		}
		return [$mapping, $localPart, $this->findStoredUser($mapping, $localPart)];
	}

	/**
	 * The stored user for a domain mapping and local part, null for a new user
	 *
	 * Users are found by their origin, so they keep their ID if the domain
	 * settings change later. Users imported from user_external have no
	 * origin yet and are found by their ID.
	 *
	 * @return StoredUser|null
	 */
	private function findStoredUser(DomainMapping $mapping, string $localPart): ?array {
		$user = $this->userStore->findByOrigin($mapping->emailDomain, $localPart);
		if ($user !== null) {
			return $user;
		}

		$user = $this->userStore->find($mapping->userId($localPart));
		if ($user !== null && $user['email_domain'] === null) {
			return $user;
		}
		// No user, or the ID belongs to someone created from another domain or local part
		return null;
	}

	/**
	 * Same rules as \OC\User\Manager::validateUserId(), which user_external does not apply
	 */
	private function isValidUserId(string $userId): bool {
		return preg_match(self::USER_ID_PATTERN, $userId) === 1
			&& trim($userId) === $userId
			&& trim($userId, '.') !== ''
			&& strlen($userId) <= IUser::MAX_USERID_LENGTH;
	}

	/**
	 * Log in to the IMAP server of the mapping; curl closes the connection
	 * after the CAPABILITY command
	 */
	private function authenticateImap(DomainMapping $mapping, string $imapLogin, string $password): bool {
		$curl = curl_init();
		if ($curl === false) {
			$this->logger->error('Could not initialize curl', ['app' => Application::APP_ID]);
			return false;
		}

		$options = [
			CURLOPT_URL => $mapping->serverUrl(),
			CURLOPT_RETURNTRANSFER => true,
			// Separate options, so a ':' in the username cannot break the credentials
			CURLOPT_USERNAME => $imapLogin,
			CURLOPT_PASSWORD => $password,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
			CURLOPT_CUSTOMREQUEST => 'CAPABILITY',
		];
		if ($mapping->sslMode === SslMode::StartTls) {
			$options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
		}
		curl_setopt_array($curl, $options);

		curl_exec($curl);
		$errorCode = curl_errno($curl);
		if ($errorCode === 0) {
			return true;
		}

		$context = [
			'app' => Application::APP_ID,
			'server' => $mapping->serverUrl(),
			'user' => $imapLogin,
			'error' => curl_error($curl),
			'code' => $errorCode,
		];
		if (in_array($errorCode, self::CURL_LOGIN_ERRORS, true)) {
			// Wrong passwords are normal; brute force protection is done by Nextcloud
			$this->logger->info('IMAP login of {user} at {server} was rejected: {error}', $context);
		} elseif (in_array($errorCode, self::CURL_CONNECTION_ERRORS, true)) {
			$this->logger->error('Could not connect to IMAP server {server}: {error} (curl error {code})', $context);
		} else {
			$this->logger->error('IMAP server {server} returned an error for {user}: {error} (curl error {code})', $context);
		}
		return false;
	}

	/**
	 * Create the user on the first login: store it, add it to its groups,
	 * then set email address and quota
	 */
	private function createUser(string $userId, string $localPart, DomainMapping $mapping): void {
		// "max.mustermann" -> "Max Mustermann"
		$displayName = mb_convert_case(str_replace('.', ' ', $localPart), MB_CASE_TITLE);
		$this->userStore->insert($userId, $displayName, $mapping->emailDomain, $localPart);

		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->error('User {user} could not be loaded after creation', [
				'app' => Application::APP_ID,
				'user' => $userId,
			]);
			return;
		}

		$groups = array_values(array_unique(array_merge($mapping->groups, $this->domainConfig->getGlobalGroups())));
		foreach ($groups as $groupId) {
			$group = $this->groupManager->createGroup($groupId);
			if ($group === null) {
				$this->logger->warning('Group {group} could not be created for user {user}', [
					'app' => Application::APP_ID,
					'group' => $groupId,
					'user' => $userId,
				]);
				continue;
			}
			$group->addUser($user);
		}

		$user->setSystemEMailAddress($mapping->imapLogin($localPart));

		$quota = $mapping->quota !== '' ? $mapping->quota : $this->domainConfig->getDefaultQuota();
		if ($quota !== '') {
			$user->setQuota($quota);
		}
	}
}
