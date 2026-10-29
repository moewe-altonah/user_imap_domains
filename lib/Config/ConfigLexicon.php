<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Config;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * Declares the app config keys, shown by `occ config:app:get --details`
 *
 * All values are only needed at login or on the settings page, so they are lazy.
 */
class ConfigLexicon implements ILexicon {
	public const DOMAIN_MAPPINGS = 'domain_mappings';
	public const GLOBAL_GROUPS = 'global_groups';
	public const DEFAULT_QUOTA = 'default_quota';
	public const LEGACY_BACKEND_ID = 'legacy_backend_id';

	public function getStrictness(): Strictness {
		return Strictness::IGNORE;
	}

	public function getAppConfigs(): array {
		return [
			new Entry(
				key: self::DOMAIN_MAPPINGS,
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON list of domain mappings, see README',
				lazy: true,
			),
			new Entry(
				key: self::GLOBAL_GROUPS,
				type: ValueType::STRING,
				defaultRaw: '[]',
				definition: 'JSON list of groups every new user is added to',
				lazy: true,
			),
			new Entry(
				key: self::DEFAULT_QUOTA,
				type: ValueType::STRING,
				defaultRaw: '',
				definition: 'Quota for new users, e.g. "2 GB"; empty for the Nextcloud default',
				lazy: true,
			),
			new Entry(
				key: self::LEGACY_BACKEND_ID,
				type: ValueType::STRING,
				defaultRaw: '',
				definition: 'Backend identifier used before this app (e.g. 127.0.0.1), for reference',
				lazy: true,
			),
		];
	}

	public function getUserConfigs(): array {
		return [];
	}
}
