<script setup>
/**
 * Form for one domain mapping
 *
 * The mapping uses the stored array form of lib/Model/DomainMapping.php
 * (snake_case keys). Changes are emitted as a new object via
 * update:mapping, the prop itself is never mutated.
 */
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed } from 'vue'

const props = defineProps({
	/** The mapping to edit */
	mapping: {
		type: Object,
		required: true,
	},
	/** Existing group IDs offered as suggestions */
	existingGroups: {
		type: Array,
		default: () => [],
	},
	/** Whether another mapping already uses user IDs without domain */
	stripDomainTaken: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits(['update:mapping', 'remove'])

const APP_ID = 'user_imap_domains'

/** Same as SslMode::defaultPort() */
const DEFAULT_PORTS = { none: 143, ssl: 993, tls: 143 }

const sslModeOptions = [
	{ id: 'ssl', label: t(APP_ID, 'SSL/TLS (IMAPS)') },
	{ id: 'tls', label: t(APP_ID, 'STARTTLS') },
	{ id: 'none', label: t(APP_ID, 'None (unencrypted)') },
]

const selectedSslMode = computed(() => sslModeOptions.find((option) => option.id === props.mapping.imap_sslmode) ?? sslModeOptions[0])

/**
 * Emit the mapping with one field changed
 *
 * @param {string} field Key in the stored array form
 * @param {unknown} value New value
 */
function update(field, value) {
	emit('update:mapping', { ...props.mapping, [field]: value })
}

/**
 * Change the SSL mode; switch the port too if it still is the default of the old mode
 *
 * @param {{id: string}|null} option Selected option
 */
function updateSslMode(option) {
	const sslMode = option?.id ?? 'ssl'
	const changes = { imap_sslmode: sslMode }
	if (Number(props.mapping.imap_port) === DEFAULT_PORTS[props.mapping.imap_sslmode]) {
		changes.imap_port = DEFAULT_PORTS[sslMode]
	}
	emit('update:mapping', { ...props.mapping, ...changes })
}

/**
 * Example login with the resulting IMAP login and Nextcloud user ID,
 * same logic as DomainMapping::imapLogin() and ::userId()
 */
const example = computed(() => {
	const emailDomain = props.mapping.email_domain.trim().toLowerCase() || 'example.org'
	const loginDomain = props.mapping.imap_user_domain.trim().toLowerCase() || emailDomain
	const prefix = props.mapping.imap_user_prefix.trim()
	const imapLogin = `${prefix}max.mustermann@${loginDomain}`
	return {
		login: props.mapping.strip_domain ? 'max.mustermann' : `max.mustermann@${emailDomain}`,
		imapLogin,
		userId: props.mapping.strip_domain ? `${prefix}max.mustermann` : imapLogin,
	}
})
</script>

<template>
	<fieldset class="domain-mapping">
		<legend class="domain-mapping__title">
			{{ mapping.email_domain || t(APP_ID, 'New domain') }}
		</legend>

		<div class="domain-mapping__fields">
			<NcTextField
				:model-value="mapping.email_domain"
				:label="t(APP_ID, 'Email domain')"
				placeholder="example.org"
				required
				@update:model-value="update('email_domain', $event)" />
			<NcTextField
				:model-value="mapping.imap_server"
				:label="t(APP_ID, 'IMAP server')"
				placeholder="imap.example.org"
				required
				@update:model-value="update('imap_server', $event)" />
			<NcSelect
				:model-value="selectedSslMode"
				:options="sslModeOptions"
				:input-label="t(APP_ID, 'Encryption')"
				:clearable="false"
				@update:model-value="updateSslMode" />
			<NcTextField
				:model-value="String(mapping.imap_port)"
				:label="t(APP_ID, 'IMAP port')"
				type="number"
				min="1"
				max="65535"
				@update:model-value="update('imap_port', Number($event) || DEFAULT_PORTS[mapping.imap_sslmode])" />
			<NcTextField
				:model-value="mapping.imap_user_prefix"
				:label="t(APP_ID, 'IMAP login prefix (optional)')"
				:helper-text="t(APP_ID, 'Put in front of the local part for the IMAP login')"
				@update:model-value="update('imap_user_prefix', $event)" />
			<NcTextField
				:model-value="mapping.imap_user_domain"
				:label="t(APP_ID, 'IMAP login domain (optional)')"
				:helper-text="t(APP_ID, 'Only if the IMAP login uses another domain than the email domain')"
				@update:model-value="update('imap_user_domain', $event)" />
			<NcSelect
				:model-value="mapping.groups"
				:options="existingGroups"
				:input-label="t(APP_ID, 'Groups for new users')"
				:placeholder="t(APP_ID, 'Select or type a group')"
				multiple
				taggable
				@update:model-value="update('groups', $event)" />
			<NcTextField
				:model-value="mapping.quota"
				:label="t(APP_ID, 'Quota for new users (optional)')"
				:helper-text="t(APP_ID, 'Overrides the default quota, e.g. 5 GB')"
				@update:model-value="update('quota', $event)" />
		</div>

		<NcCheckboxRadioSwitch
			:model-value="mapping.strip_domain"
			:disabled="stripDomainTaken && !mapping.strip_domain"
			type="switch"
			@update:model-value="update('strip_domain', $event)">
			{{ t(APP_ID, 'User IDs without domain; users can log in without @domain') }}
		</NcCheckboxRadioSwitch>
		<p v-if="stripDomainTaken && !mapping.strip_domain" class="domain-mapping__hint">
			{{ t(APP_ID, 'Only one domain can use user IDs without domain.') }}
		</p>

		<NcNoteCard v-if="mapping.imap_sslmode === 'none'" type="warning">
			{{ t(APP_ID, 'Passwords are sent unencrypted to this IMAP server.') }}
		</NcNoteCard>

		<div class="domain-mapping__example">
			<span class="domain-mapping__example-label">{{ t(APP_ID, 'Example – entered at login') }}</span>
			<code class="domain-mapping__example-value">{{ example.login }}</code>
			<span class="domain-mapping__example-label">{{ t(APP_ID, 'IMAP login (email address)') }}</span>
			<code class="domain-mapping__example-value">{{ example.imapLogin }}</code>
			<span class="domain-mapping__example-label">{{ t(APP_ID, 'Nextcloud login (user ID)') }}</span>
			<code class="domain-mapping__example-value">{{ example.userId }}</code>
		</div>

		<NcButton variant="tertiary" @click="emit('remove')">
			{{ t(APP_ID, 'Remove domain') }}
		</NcButton>
	</fieldset>
</template>

<style scoped lang="scss">
.domain-mapping {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(2 * var(--default-grid-baseline));
	margin-block-end: calc(3 * var(--default-grid-baseline));
	max-width: 900px;

	&__title {
		font-weight: bold;
		padding-inline: var(--default-grid-baseline);
	}

	&__fields {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
		gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
		align-items: start;
		margin-block-end: calc(2 * var(--default-grid-baseline));
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin-block: var(--default-grid-baseline);
	}

	&__example {
		display: grid;
		// Label column as wide as the longest label, values in the rest
		grid-template-columns: max-content minmax(0, 1fr);
		column-gap: calc(3 * var(--default-grid-baseline));
		row-gap: calc(2 * var(--default-grid-baseline));
		align-items: baseline;
		margin-block: calc(2 * var(--default-grid-baseline));
	}

	// Explicit resets: global Nextcloud styles must not change the layout
	&__example-label,
	&__example-value {
		display: block;
		width: auto;
		margin: 0;
		padding: 0;
		float: none;
	}

	&__example-label {
		color: var(--color-text-maxcontrast);
	}

	&__example-value {
		font-family: var(--font-face-monospace, monospace);
		background: none;
		overflow-wrap: anywhere;
	}

	// Narrow screens: label above its value
	@media (max-width: 500px) {
		&__example {
			grid-template-columns: minmax(0, 1fr);
			row-gap: 0;
		}

		&__example-value {
			margin-block-end: calc(2 * var(--default-grid-baseline));
		}
	}
}
</style>
