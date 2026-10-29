<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Settings;

use OCA\UserImapDomains\AppInfo\Application;
use OCA\UserImapDomains\Model\DomainMapping;
use OCA\UserImapDomains\Service\BackendMigrationService;
use OCA\UserImapDomains\Service\DomainConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin settings page; the form itself is the Vue app in src/
 */
class AdminSettings implements ISettings {
	/** Maximum number of existing groups offered as suggestions */
	private const GROUP_SUGGESTION_LIMIT = 500;

	public function __construct(
		private readonly DomainConfigService $domainConfig,
		private readonly BackendMigrationService $migrationService,
		private readonly IGroupManager $groupManager,
		private readonly IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('settings', [
			'mappings' => array_map(
				static fn (DomainMapping $mapping): array => $mapping->toArray(),
				$this->domainConfig->getDomainMappings(),
			),
			'globalGroups' => $this->domainConfig->getGlobalGroups(),
			'defaultQuota' => $this->domainConfig->getDefaultQuota(),
			'legacyBackendId' => $this->domainConfig->getLegacyBackendId(),
			'legacyTableAvailable' => $this->migrationService->isLegacyTableAvailable(),
			'existingGroups' => array_map(
				static fn (IGroup $group): string => $group->getGID(),
				$this->groupManager->search('', self::GROUP_SUGGESTION_LIMIT),
			),
		]);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	/**
	 * Position inside the section, lower values are shown first
	 */
	public function getPriority(): int {
		return 50;
	}
}
