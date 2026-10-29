<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Db;

use OCP\IDBConnection;

/**
 * Read and remove users in the table users_external of the app user_external
 *
 * Only used to import users; the app user_external itself is not needed.
 */
class LegacyUserStore {
	public const TABLE = 'users_external';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Whether the table exists (user_external is or was installed)
	 */
	public function isAvailable(): bool {
		return $this->db->tableExists(self::TABLE);
	}

	/**
	 * Users of a backend identifier
	 *
	 * Note: PHP turns numeric user IDs like "12345" into integer keys.
	 *
	 * @return array<array-key, string> Display name by user ID
	 */
	public function getUsers(string $backendId): array {
		if ($backendId === '' || !$this->isAvailable()) {
			return [];
		}

		$query = $this->db->getQueryBuilder();
		$query->select('uid', 'displayname')
			->from(self::TABLE)
			->where($query->expr()->eq('backend', $query->createNamedParameter($backendId)));
		$result = $query->executeQuery();

		$users = [];
		while (is_array($row = $result->fetch())) {
			$users[(string)$row['uid']] = (string)($row['displayname'] ?? '');
		}
		$result->closeCursor();

		return $users;
	}

	/**
	 * Remove a user of a backend identifier, so that backend no longer claims it
	 */
	public function delete(string $backendId, string $uid): void {
		$query = $this->db->getQueryBuilder();
		$query->delete(self::TABLE)
			->where($query->expr()->eq('backend', $query->createNamedParameter($backendId)))
			->andWhere($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();
	}
}
