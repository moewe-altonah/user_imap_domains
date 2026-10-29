<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Service;

use OCA\UserImapDomains\Db\LegacyUserStore;
use OCA\UserImapDomains\Db\UserStore;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Move users from the table users_external of the app user_external to this app
 *
 * Used for users created by a user_backends entry in config.php (backend
 * identifier e.g. "127.0.0.1").
 *
 * User IDs are kept exactly as they are; no domain is added. The users'
 * rows in users_external are removed, so the old backend no longer claims
 * them and the user management shows this app as their backend.
 */
class BackendMigrationService {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly UserStore $userStore,
		private readonly LegacyUserStore $legacyUserStore,
		private readonly DomainConfigService $domainConfig,
		private readonly IConfig $config,
	) {
	}

	/**
	 * Whether the table users_external exists (user_external is or was installed)
	 */
	public function isLegacyTableAvailable(): bool {
		return $this->legacyUserStore->isAvailable();
	}

	/**
	 * Check what an import would do, without changing anything
	 *
	 * - total: users stored for the legacy backend
	 * - conflicts: user IDs that already exist in this app; their old entry is removed
	 * - unmatched: user IDs that fit no domain mapping and would get a new account on their next login
	 * - withoutDomain: user IDs without a domain
	 * - missingDefaultDomain: some user IDs have no domain, but no domain mapping has
	 *   "User IDs without domain" enabled
	 * - configuredBackends: classes still configured in user_backends in config.php
	 *
	 * @return array{available: bool, total: int, conflicts: list<string>, unmatched: list<string>, withoutDomain: int, missingDefaultDomain: bool, configuredBackends: list<string>}
	 */
	public function analyze(string $legacyBackendId): array {
		$result = [
			'available' => $this->isLegacyTableAvailable(),
			'total' => 0,
			'conflicts' => [],
			'unmatched' => [],
			'withoutDomain' => 0,
			'missingDefaultDomain' => false,
			'configuredBackends' => $this->getConfiguredBackends(),
		];
		if (!$result['available']) {
			return $result;
		}

		$legacyUsers = $this->legacyUserStore->getUsers(trim($legacyBackendId));
		$result['total'] = count($legacyUsers);
		foreach (array_keys($legacyUsers) as $userId) {
			$userId = (string)$userId;
			if (!str_contains($userId, '@')) {
				$result['withoutDomain']++;
			}
			if ($this->userStore->exists($userId)) {
				$result['conflicts'][] = $userId;
			} elseif (!$this->matchesAnyMapping($userId)) {
				$result['unmatched'][] = $userId;
			}
		}
		$result['missingDefaultDomain'] = $result['withoutDomain'] > 0
			&& $this->domainConfig->findDefaultMapping() === null;

		return $result;
	}

	/**
	 * Move all users of the legacy backend to this app
	 *
	 * Users that do not exist in this app yet are copied with their user ID
	 * and display name. The rows of all users of the legacy backend are then
	 * removed from users_external, including those that already existed here.
	 *
	 * @return int The number of users added to this app
	 */
	public function import(string $legacyBackendId): int {
		$legacyBackendId = trim($legacyBackendId);
		if ($legacyBackendId === '' || !$this->isLegacyTableAvailable()) {
			return 0;
		}

		$imported = 0;
		$this->db->beginTransaction();
		try {
			foreach ($this->legacyUserStore->getUsers($legacyBackendId) as $userId => $displayName) {
				$userId = (string)$userId;
				if (!$this->userStore->exists($userId)) {
					// No origin: it is filled in on the user's next login
					$this->userStore->insert($userId, $displayName, null, null);
					$imported++;
				}
				$this->legacyUserStore->delete($legacyBackendId, $userId);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $imported;
	}

	/**
	 * Classes of user backends still configured in config.php
	 *
	 * @return list<string>
	 */
	public function getConfiguredBackends(): array {
		$backends = $this->config->getSystemValue('user_backends', []);
		if (!is_array($backends)) {
			return [];
		}

		$classes = [];
		foreach ($backends as $backend) {
			if (is_array($backend) && isset($backend['class']) && is_string($backend['class'])) {
				$classes[] = $backend['class'];
			}
		}
		return $classes;
	}

	private function matchesAnyMapping(string $userId): bool {
		foreach ($this->domainConfig->getDomainMappings() as $mapping) {
			if ($mapping->localPartFromUserId($userId) !== null) {
				return true;
			}
		}
		return false;
	}
}
