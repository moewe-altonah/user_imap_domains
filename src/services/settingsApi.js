/**
 * Calls of the endpoints in lib/Controller/SettingsController.php
 */
import axios from '@nextcloud/axios'
import { confirmPassword, PasswordConfirmationCancelledError } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'

const APP_ID = 'user_imap_domains'

const url = (path) => generateUrl(`/apps/${APP_ID}/settings/${path}`)

/**
 * @param {object[]} mappings Mappings in their stored array form
 * @return {Promise<object[]>} The normalized mappings as saved by the server
 */
export async function saveDomainMappings(mappings) {
	await confirmPassword()
	const { data } = await axios.put(url('domains'), { mappings })
	return data.mappings
}

/**
 * @param {string} defaultQuota Quota like "2 GB", '' for the Nextcloud default
 * @param {string[]} globalGroups Groups every new user is added to
 * @return {Promise<{defaultQuota: string, globalGroups: string[]}>}
 */
export async function saveGlobalSettings(defaultQuota, globalGroups) {
	await confirmPassword()
	const { data } = await axios.put(url('global'), { defaultQuota, globalGroups })
	return data
}

/**
 * @param {string} legacyBackendId Previous backend identifier, e.g. "127.0.0.1"
 * @return {Promise<{total: number, conflicts: string[], unmatched: string[]}>}
 */
export async function analyzeMigration(legacyBackendId) {
	const { data } = await axios.get(url('migration'), { params: { legacyBackendId } })
	return data
}

/**
 * @param {string} legacyBackendId Previous backend identifier, e.g. "127.0.0.1"
 * @return {Promise<number>} Number of migrated users
 */
export async function migrateBackend(legacyBackendId) {
	await confirmPassword()
	const { data } = await axios.post(url('migration'), { legacyBackendId })
	return data.migrated
}

/**
 * Messages of a failed request: validation errors of the server,
 * or null if the user cancelled the password confirmation
 *
 * @param {unknown} error Error thrown by one of the functions above
 * @param {string} fallback Message if the server sent none
 * @return {string[]|null}
 */
export function errorMessages(error, fallback) {
	if (error instanceof PasswordConfirmationCancelledError) {
		return null
	}
	const errors = error?.response?.data?.errors
	return Array.isArray(errors) && errors.length > 0 ? errors : [fallback]
}
