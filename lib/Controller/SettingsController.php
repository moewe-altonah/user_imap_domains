<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Controller;

use OCA\UserImapDomains\Model\DomainMapping;
use OCA\UserImapDomains\Service\BackendMigrationService;
use OCA\UserImapDomains\Service\DomainConfigService;
use OCA\UserImapDomains\Service\DomainMappingValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Endpoints of the admin settings page
 *
 * No #[NoAdminRequired] and no delegation attribute: only administrators
 * may change how users authenticate.
 */
class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DomainConfigService $domainConfig,
		private readonly DomainMappingValidator $validator,
		private readonly BackendMigrationService $migrationService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Replace all domain mappings
	 *
	 * @param array<array-key, mixed> $mappings Mappings in their stored array form
	 *
	 * @return DataResponse<Http::STATUS_OK, array{mappings: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{errors: list<string>}, array{}>
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/settings/domains')]
	public function saveDomains(array $mappings = []): DataResponse {
		$parsed = array_map(
			static fn (mixed $entry): DomainMapping => DomainMapping::fromArray(is_array($entry) ? $entry : []),
			array_values($mappings),
		);

		$errors = $this->validator->validateMappings($parsed);
		if ($errors !== []) {
			return new DataResponse(['errors' => $errors], Http::STATUS_BAD_REQUEST);
		}

		$this->domainConfig->saveDomainMappings($parsed);

		return new DataResponse([
			'mappings' => array_map(static fn (DomainMapping $mapping): array => $mapping->toArray(), $parsed),
		]);
	}

	/**
	 * Save the default quota and the groups every new user is added to
	 *
	 * @param string $defaultQuota Quota like "2 GB", '' for the Nextcloud default
	 * @param list<string> $globalGroups Group names
	 *
	 * @return DataResponse<Http::STATUS_OK, array{defaultQuota: string, globalGroups: list<string>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{errors: list<string>}, array{}>
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/settings/global')]
	public function saveGlobal(string $defaultQuota = '', array $globalGroups = []): DataResponse {
		$defaultQuota = trim($defaultQuota);
		$groups = DomainMapping::parseGroupList($globalGroups);

		$errors = $this->validator->validateGlobalSettings($defaultQuota, $groups);
		if ($errors !== []) {
			return new DataResponse(['errors' => $errors], Http::STATUS_BAD_REQUEST);
		}

		$this->domainConfig->saveDefaultQuota($defaultQuota);
		$this->domainConfig->saveGlobalGroups($groups);

		return new DataResponse(['defaultQuota' => $defaultQuota, 'globalGroups' => $groups]);
	}

	/**
	 * Show what moving the users of a legacy user_external backend would do
	 *
	 * @param string $legacyBackendId Previous backend identifier, e.g. "127.0.0.1"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{available: bool, total: int, conflicts: list<string>, unmatched: list<string>, withoutDomain: int, missingDefaultDomain: bool, configuredBackends: list<string>}, array{}>
	 */
	#[FrontpageRoute(verb: 'GET', url: '/settings/migration')]
	public function analyzeMigration(string $legacyBackendId = ''): DataResponse {
		return new DataResponse($this->migrationService->analyze($legacyBackendId));
	}

	/**
	 * Store the legacy backend identifier and move its users to this app
	 *
	 * @param string $legacyBackendId Previous backend identifier, e.g. "127.0.0.1"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{migrated: int}, array{}>
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/migration')]
	public function migrateBackend(string $legacyBackendId = ''): DataResponse {
		$legacyBackendId = trim($legacyBackendId);
		$this->domainConfig->saveLegacyBackendId($legacyBackendId);

		return new DataResponse(['migrated' => $this->migrationService->import($legacyBackendId)]);
	}
}
