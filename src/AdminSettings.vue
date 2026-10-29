<script setup>
/**
 * Admin settings of IMAP Multi-Domain Login
 *
 * Initial data comes from lib/Settings/AdminSettings.php ("settings"),
 * saving goes through src/services/settingsApi.js.
 */
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'
import DomainMappingCard from './components/DomainMappingCard.vue'
import {
	analyzeMigration,
	errorMessages,
	migrateBackend,
	saveDomainMappings,
	saveGlobalSettings,
} from './services/settingsApi.js'

import '@nextcloud/dialogs/style.css'

const APP_ID = 'user_imap_domains'

const settings = loadState(APP_ID, 'settings')

/** Stable keys for v-for, the mappings themselves have no ID */
let nextKey = 0

/**
 * @param {object} mapping Mapping in its stored array form
 * @return {{key: number, mapping: object}}
 */
function toRow(mapping) {
	return { key: nextKey++, mapping }
}

/** Defaults of a new mapping, same as DomainMapping::fromArray([]) */
function emptyMapping() {
	return {
		email_domain: '',
		imap_server: '',
		imap_port: 993,
		imap_sslmode: 'ssl',
		imap_user_prefix: '',
		imap_user_domain: '',
		groups: [],
		quota: '',
		strip_domain: false,
	}
}

// General settings
const defaultQuota = ref(settings.defaultQuota)
const globalGroups = ref(settings.globalGroups)
const savingGlobal = ref(false)

// Domain mappings
const rows = ref(settings.mappings.map(toRow))
const savingDomains = ref(false)
const stripDomainKey = computed(() => rows.value.find((row) => row.mapping.strip_domain)?.key ?? null)

// Migration
const legacyBackendId = ref(settings.legacyBackendId)
const analysis = ref(null)
const analyzing = ref(false)
const migrating = ref(false)

/**
 * Show the error messages of a failed request, nothing if the user cancelled
 *
 * @param {unknown} error Error of a settingsApi call
 * @param {string} fallback Message if the server sent none
 */
function reportError(error, fallback) {
	const messages = errorMessages(error, fallback)
	messages?.forEach((message) => showError(message))
}

async function saveGlobal() {
	savingGlobal.value = true
	try {
		const saved = await saveGlobalSettings(defaultQuota.value.trim(), globalGroups.value)
		defaultQuota.value = saved.defaultQuota
		globalGroups.value = saved.globalGroups
		showSuccess(t(APP_ID, 'General settings saved'))
	} catch (error) {
		reportError(error, t(APP_ID, 'Could not save the general settings'))
	} finally {
		savingGlobal.value = false
	}
}

function addDomain() {
	rows.value.push(toRow(emptyMapping()))
}

/**
 * @param {number} key Key of the row to remove
 */
function removeDomain(key) {
	rows.value = rows.value.filter((row) => row.key !== key)
}

async function saveDomains() {
	savingDomains.value = true
	try {
		const saved = await saveDomainMappings(rows.value.map((row) => row.mapping))
		rows.value = saved.map(toRow)
		// Results of an earlier migration check depend on the mappings
		analysis.value = null
		showSuccess(t(APP_ID, 'Domain mappings saved'))
	} catch (error) {
		reportError(error, t(APP_ID, 'Could not save the domain mappings'))
	} finally {
		savingDomains.value = false
	}
}

async function analyze() {
	analyzing.value = true
	try {
		analysis.value = await analyzeMigration(legacyBackendId.value.trim())
	} catch (error) {
		reportError(error, t(APP_ID, 'Could not check the import'))
	} finally {
		analyzing.value = false
	}
}

async function migrate() {
	migrating.value = true
	try {
		const count = await migrateBackend(legacyBackendId.value.trim())
		showSuccess(n(APP_ID, '%n user moved to this app', '%n users moved to this app', count))
		if (analysis.value?.missingDefaultDomain) {
			showWarning(t(APP_ID, 'Imported user IDs without domain need a domain with "User IDs without domain" enabled.'))
		}
		analysis.value = null
	} catch (error) {
		reportError(error, t(APP_ID, 'Could not import the users'))
	} finally {
		migrating.value = false
	}
}
</script>

<template>
	<div class="imap-domains">
		<NcSettingsSection
			:name="t(APP_ID, 'IMAP Multi-Domain Login')"
			:description="t(APP_ID, 'Users authenticate against the IMAP server of their email domain. On their first login they are created, added to the configured groups and get a quota.')" />

		<NcSettingsSection
			:name="t(APP_ID, 'General')"
			:description="t(APP_ID, 'Defaults for new users of all domains.')">
			<div class="imap-domains__narrow">
				<NcTextField
					v-model="defaultQuota"
					:label="t(APP_ID, 'Default quota for new users')"
					:helper-text="t(APP_ID, 'e.g. 2 GB, none for unlimited, empty for the Nextcloud default')" />
				<NcSelect
					v-model="globalGroups"
					:options="settings.existingGroups"
					:input-label="t(APP_ID, 'Groups for all new users')"
					:placeholder="t(APP_ID, 'Select or type a group')"
					multiple
					taggable />
			</div>
			<NcButton variant="primary" :disabled="savingGlobal" @click="saveGlobal">
				{{ t(APP_ID, 'Save general settings') }}
			</NcButton>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t(APP_ID, 'Domain mappings')"
			:description="t(APP_ID, 'Groups that do not exist yet are created on the first login of a user. Changes take effect after saving.')">
			<p v-if="rows.length === 0" class="imap-domains__hint">
				{{ t(APP_ID, 'No domain is configured yet, nobody can log in via IMAP.') }}
			</p>
			<DomainMappingCard
				v-for="row in rows"
				:key="row.key"
				v-model:mapping="row.mapping"
				:existing-groups="settings.existingGroups"
				:strip-domain-taken="stripDomainKey !== null && stripDomainKey !== row.key"
				@remove="removeDomain(row.key)" />
			<div class="imap-domains__actions">
				<NcButton @click="addDomain">
					{{ t(APP_ID, 'Add domain') }}
				</NcButton>
				<NcButton variant="primary" :disabled="savingDomains" @click="saveDomains">
					{{ t(APP_ID, 'Save domain mappings') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t(APP_ID, 'Import users from user_external')"
			:description="t(APP_ID, 'Users created before by the app External user authentication (e.g. via a user_backends entry in config.php) are stored under a backend identifier like 127.0.0.1. Check and import them: they are moved to this app with their user ID unchanged, the app user_external is not needed. Also available as occ user_imap_domains:migrate-backend.')">
			<NcNoteCard v-if="!settings.legacyTableAvailable" type="info">
				{{ t(APP_ID, 'The app user_external was never installed on this server, there is nothing to import.') }}
			</NcNoteCard>
			<template v-else>
				<div class="imap-domains__narrow">
					<NcTextField
						v-model="legacyBackendId"
						:label="t(APP_ID, 'Old backend identifier')"
						placeholder="127.0.0.1"
						@update:model-value="analysis = null" />
				</div>
				<div class="imap-domains__actions">
					<NcButton :disabled="analyzing || legacyBackendId.trim() === ''" @click="analyze">
						{{ t(APP_ID, 'Check') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="migrating || analysis === null || analysis.total === 0"
						@click="migrate">
						{{ t(APP_ID, 'Move users') }}
					</NcButton>
				</div>

				<div v-if="analysis" class="imap-domains__analysis">
					<p>{{ n(APP_ID, '%n user found for this identifier.', '%n users found for this identifier.', analysis.total) }}</p>
					<NcNoteCard v-if="analysis.missingDefaultDomain" type="warning">
						{{ n(APP_ID, '%n user ID has no domain. Enable "User IDs without domain" for one domain in the domain mappings, otherwise this user gets a new account on the next login.', '%n user IDs have no domain. Enable "User IDs without domain" for one domain in the domain mappings, otherwise these users get new accounts on their next login.', analysis.withoutDomain) }}
					</NcNoteCard>
					<NcNoteCard v-if="analysis.configuredBackends.length > 0" type="warning">
						<p>{{ t(APP_ID, 'config.php still contains user_backends: {backends}. Remove it, otherwise the old backend keeps authenticating users:', { backends: analysis.configuredBackends.join(', ') }, { escape: false }) }}</p>
						<p class="imap-domains__user-list">
							occ config:system:delete user_backends
						</p>
					</NcNoteCard>
					<NcNoteCard v-if="analysis.conflicts.length > 0" type="info">
						<p>{{ n(APP_ID, '%n user already exists in this app, only the old entry is removed:', '%n users already exist in this app, only their old entries are removed:', analysis.conflicts.length) }}</p>
						<p class="imap-domains__user-list">
							{{ analysis.conflicts.join(', ') }}
						</p>
					</NcNoteCard>
					<NcNoteCard v-if="analysis.unmatched.length > 0" type="warning">
						<p>{{ n(APP_ID, '%n user ID matches no domain mapping. This user would get a new account on the next login:', '%n user IDs match no domain mapping. These users would get a new account on their next login:', analysis.unmatched.length) }}</p>
						<p class="imap-domains__user-list">
							{{ analysis.unmatched.join(', ') }}
						</p>
					</NcNoteCard>
				</div>
			</template>
		</NcSettingsSection>
	</div>
</template>

<style scoped lang="scss">
.imap-domains {
	&__narrow {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
		max-width: 400px;
		margin-block-end: calc(2 * var(--default-grid-baseline));
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__analysis {
		margin-block-start: calc(2 * var(--default-grid-baseline));
		max-width: 900px;
	}

	&__user-list {
		font-family: var(--font-face-monospace, monospace);
		max-height: 10em;
		overflow-y: auto;
		overflow-wrap: anywhere;
	}
}
</style>
