<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Service;

use OCA\UserImapDomains\AppInfo\Application;
use OCA\UserImapDomains\Config\ConfigLexicon;
use OCA\UserImapDomains\Model\DomainMapping;
use OCP\IAppConfig;

/**
 * Read and write the app configuration
 *
 * All values are stored lazy (see {@see ConfigLexicon}). Lists are stored
 * as JSON strings to stay compatible with existing installations and with
 * `occ config:app:set`.
 */
class DomainConfigService {
	/** @var list<DomainMapping>|null Decoded mappings, cached for the request */
	private ?array $mappings = null;

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	/**
	 * Get all configured domain mappings
	 *
	 * @return list<DomainMapping>
	 */
	public function getDomainMappings(): array {
		if ($this->mappings !== null) {
			return $this->mappings;
		}

		$rows = array_values(array_filter($this->getJsonList(ConfigLexicon::DOMAIN_MAPPINGS), is_array(...)));
		$this->mappings = array_map(DomainMapping::fromArray(...), $rows);
		return $this->mappings;
	}

	/**
	 * Save all domain mappings
	 *
	 * @param list<DomainMapping> $mappings
	 */
	public function saveDomainMappings(array $mappings): void {
		$this->setJsonList(
			ConfigLexicon::DOMAIN_MAPPINGS,
			array_map(static fn (DomainMapping $mapping): array => $mapping->toArray(), $mappings),
		);
		$this->mappings = $mappings;
	}

	/**
	 * Find the mapping of an email domain (case-insensitive)
	 */
	public function findMappingByEmailDomain(string $emailDomain): ?DomainMapping {
		$emailDomain = mb_strtolower(trim($emailDomain));
		foreach ($this->getDomainMappings() as $mapping) {
			if ($mapping->emailDomain === $emailDomain) {
				return $mapping;
			}
		}
		return null;
	}

	/**
	 * Get the mapping used for logins without a domain: the only mapping
	 * with strip_domain enabled, or null if there is none
	 */
	public function findDefaultMapping(): ?DomainMapping {
		foreach ($this->getDomainMappings() as $mapping) {
			if ($mapping->stripDomain) {
				return $mapping;
			}
		}
		return null;
	}

	/**
	 * Get the groups every new user is added to
	 *
	 * @return list<string>
	 */
	public function getGlobalGroups(): array {
		return DomainMapping::parseGroupList($this->getJsonList(ConfigLexicon::GLOBAL_GROUPS));
	}

	/**
	 * Save the groups every new user is added to
	 *
	 * @param list<string> $groups
	 */
	public function saveGlobalGroups(array $groups): void {
		$this->setJsonList(ConfigLexicon::GLOBAL_GROUPS, DomainMapping::parseGroupList($groups));
	}

	/**
	 * Get the quota for new users, '' for the Nextcloud default
	 */
	public function getDefaultQuota(): string {
		return $this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::DEFAULT_QUOTA, '', lazy: true);
	}

	public function saveDefaultQuota(string $quota): void {
		$this->appConfig->setValueString(Application::APP_ID, ConfigLexicon::DEFAULT_QUOTA, $quota, lazy: true);
	}

	/**
	 * Get the backend identifier used before this app, e.g. '127.0.0.1'
	 * for a user_backends entry in config.php
	 */
	public function getLegacyBackendId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, ConfigLexicon::LEGACY_BACKEND_ID, '', lazy: true);
	}

	public function saveLegacyBackendId(string $legacyBackendId): void {
		$this->appConfig->setValueString(Application::APP_ID, ConfigLexicon::LEGACY_BACKEND_ID, $legacyBackendId, lazy: true);
	}

	/**
	 * @return list<mixed> The decoded list, [] if the value is missing or invalid
	 */
	private function getJsonList(string $key): array {
		$json = $this->appConfig->getValueString(Application::APP_ID, $key, '[]', lazy: true);
		$value = json_decode($json, true);
		return is_array($value) ? array_values($value) : [];
	}

	/**
	 * @param array<array-key, mixed> $list
	 */
	private function setJsonList(string $key, array $list): void {
		$json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->appConfig->setValueString(Application::APP_ID, $key, $json, lazy: true);
	}
}
