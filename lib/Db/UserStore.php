<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Db;

use OCP\IDBConnection;

/**
 * Users of this backend, stored in the table imap_domain_users
 *
 * User IDs are looked up case-insensitively (like user_external did);
 * {@see self::find()} returns the stored spelling.
 *
 * @psalm-type StoredUser = array{uid: string, display_name: string, email_domain: ?string, local_part: ?string}
 */
class UserStore {
	public const TABLE = 'imap_domain_users';

	/** @var array<string, StoredUser|false> Lookups of this request, keyed by lowercase user ID */
	private array $cache = [];

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Find a user by ID (case-insensitive)
	 *
	 * email_domain and local_part are null for users imported from
	 * user_external, they are filled on their next login.
	 *
	 * @return StoredUser|null
	 */
	public function find(string $uid): ?array {
		$key = mb_strtolower($uid);
		if (!array_key_exists($key, $this->cache)) {
			$query = $this->db->getQueryBuilder();
			$query->select('uid', 'display_name', 'email_domain', 'local_part')
				->from(self::TABLE)
				->where($query->expr()->iLike('uid', $query->createNamedParameter($this->db->escapeLikeParameter($uid))))
				->setMaxResults(1);
			$result = $query->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			$this->cache[$key] = is_array($row) ? [
				'uid' => (string)$row['uid'],
				'display_name' => (string)($row['display_name'] ?? ''),
				'email_domain' => $row['email_domain'] !== null ? (string)$row['email_domain'] : null,
				'local_part' => $row['local_part'] !== null ? (string)$row['local_part'] : null,
			] : false;
		}
		return $this->cache[$key] ?: null;
	}

	/**
	 * Find the user created from an email domain and local part
	 *
	 * Lets users keep their account when the prefix or the strip_domain
	 * setting of their domain changes later.
	 *
	 * @return StoredUser|null
	 */
	public function findByOrigin(string $emailDomain, string $localPart): ?array {
		$query = $this->db->getQueryBuilder();
		$query->select('uid')
			->from(self::TABLE)
			->where($query->expr()->eq('email_domain', $query->createNamedParameter($emailDomain)))
			->andWhere($query->expr()->eq('local_part', $query->createNamedParameter($localPart)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$uid = $result->fetchOne();
		$result->closeCursor();

		return $uid !== false ? $this->find((string)$uid) : null;
	}

	public function exists(string $uid): bool {
		return $this->find($uid) !== null;
	}

	public function insert(string $uid, string $displayName, ?string $emailDomain, ?string $localPart): void {
		$query = $this->db->getQueryBuilder();
		$query->insert(self::TABLE)
			->values([
				'uid' => $query->createNamedParameter($uid),
				'display_name' => $query->createNamedParameter($displayName),
				'email_domain' => $query->createNamedParameter($emailDomain),
				'local_part' => $query->createNamedParameter($localPart),
			])
			->executeStatement();
		unset($this->cache[mb_strtolower($uid)]);
	}

	public function setDisplayName(string $uid, string $displayName): bool {
		$query = $this->db->getQueryBuilder();
		$changed = $query->update(self::TABLE)
			->set('display_name', $query->createNamedParameter($displayName))
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();
		unset($this->cache[mb_strtolower($uid)]);
		return $changed > 0;
	}

	/**
	 * Remember which domain mapping and local part a user belongs to
	 */
	public function setOrigin(string $uid, string $emailDomain, string $localPart): void {
		$query = $this->db->getQueryBuilder();
		$query->update(self::TABLE)
			->set('email_domain', $query->createNamedParameter($emailDomain))
			->set('local_part', $query->createNamedParameter($localPart))
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();
		unset($this->cache[mb_strtolower($uid)]);
	}

	public function delete(string $uid): bool {
		$query = $this->db->getQueryBuilder();
		$deleted = $query->delete(self::TABLE)
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
			->executeStatement();
		unset($this->cache[mb_strtolower($uid)]);
		return $deleted > 0;
	}

	/**
	 * Users whose ID or display name contains the search term
	 *
	 * Note: PHP turns numeric user IDs like "12345" into integer keys.
	 *
	 * @return array<array-key, string> Display name by user ID, sorted by user ID
	 */
	public function search(string $search, ?int $limit, ?int $offset): array {
		$query = $this->db->getQueryBuilder();
		$query->select('uid', 'display_name')
			->from(self::TABLE)
			->orderBy('uid', 'ASC');
		if ($search !== '') {
			$pattern = $query->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%');
			$query->where($query->expr()->orX(
				$query->expr()->iLike('uid', $pattern),
				$query->expr()->iLike('display_name', $pattern),
			));
		}
		if ($limit !== null && $limit > 0) {
			$query->setMaxResults($limit);
		}
		if ($offset !== null && $offset > 0) {
			$query->setFirstResult($offset);
		}

		$users = [];
		$result = $query->executeQuery();
		while (is_array($row = $result->fetch())) {
			$users[(string)$row['uid']] = (string)($row['display_name'] ?? '');
		}
		$result->closeCursor();
		return $users;
	}

	/**
	 * Number of imported users without domain in their ID that have not
	 * logged in since the import (no origin yet)
	 */
	public function countImportedWithoutDomain(): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_users'))
			->from(self::TABLE)
			->where($query->expr()->isNull('email_domain'))
			->andWhere($query->expr()->notLike('uid', $query->createNamedParameter('%@%')));
		$result = $query->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @param int $limit Maximum number to report, 0 for no limit
	 */
	public function count(int $limit = 0): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'num_users'))->from(self::TABLE);
		$result = $query->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $limit > 0 ? min($count, $limit) : $count;
	}
}
