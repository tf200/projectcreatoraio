import { t } from '../new/l10n.mjs'

/**
 * The project type's display name for its stored id, or '' for an unknown id.
 * Translated at call time, never at module load.
 */
export function projectTypeLabel(id) {
	switch (Number(id)) {
	case 0: return t('Combi')
	case 1: return t('Solo Elektra')
	case 2: return t('Solo Water')
	case 3: return t('Custom')
	default: return ''
	}
}

// `label` is a getter so each read is translated in the user's language.
export const PROJECT_TYPES = [0, 1, 2, 3].map((id) => ({
	id,
	get label() {
		return projectTypeLabel(id)
	},
}))
