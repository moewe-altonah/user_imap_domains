import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

// Builds js/user_imap_domains-admin.mjs, loaded by AdminSettings::getForm()
export default createAppConfig({
	admin: resolve(join('src', 'admin.js')),
}, {
	// One file only: the styles are injected by the script
	inlineCSS: true,
})
