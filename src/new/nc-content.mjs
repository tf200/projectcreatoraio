/**
 * Nextcloud's NcContent with two of its built-in texts in the client's wording.
 *
 * NcContent renders the "skip to" links for keyboard users with the UI
 * library's own Dutch ("Naar hoofdinhoud gaan", "Hulp voor
 * toetsenbordnavigatie"). The client approved different wording, so those two
 * texts come from this app's translations instead; every other text of the
 * component keeps the library's translation. Vue prefers this component's
 * t() over the one it extends, and the template calls t() for each text.
 */
import NcContent from '@nextcloud/vue/components/NcContent'
import { t } from './l10n.mjs'

const OWN_TEXTS = new Set(['Skip to main content', 'Keyboard navigation help'])

export default {
	name: 'NcContent',
	extends: NcContent,
	methods: {
		t(text, ...rest) {
			return OWN_TEXTS.has(text) ? t(text) : NcContent.methods.t(text, ...rest)
		},
	},
}
