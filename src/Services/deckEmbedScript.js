import { generateFilePath } from '@nextcloud/router'

// Deck's version from the capabilities Nextcloud puts on every page, or '' when unknown.
export function deckVersion() {
	try {
		const state = document.getElementById('initial-state-core-capabilities')?.value
		return state ? String(JSON.parse(atob(state))?.deck?.version || '') : ''
	} catch (e) {
		return ''
	}
}

// Deck's embed bundle (tasks and analytics). It must be a static asset URL (no
// index.php front controller), otherwise Nextcloud returns HTML. Deck's version
// in the URL makes browsers fetch it again after a Deck update; without it they
// keep their copy for months (the server's max-age).
export function deckEmbedScriptUrl() {
	const version = deckVersion()
	return generateFilePath('deck', '', 'js/deck-embedded-tasks.js') + (version ? '?v=' + encodeURIComponent(version) : '')
}
