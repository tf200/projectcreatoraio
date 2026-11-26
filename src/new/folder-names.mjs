/**
 * The name a project folder is shown under in the Documents tab.
 *
 * Two folders are made by the app itself and keep the name they got when the
 * project was created: "<project> - Shared Files" (or its translation then) and
 * the public notes folder ("Public Notes", "Openbare notities" or
 * "Gedeelde notities", depending on when the project was made). Those are shown
 * in the user's language. Every other folder name is the user's own and is
 * shown exactly as stored. Only the label changes; paths stay untouched.
 */
import { t } from './l10n.mjs'

const SHARED_SUFFIXES = [' - Shared Files', ' - Gedeelde bestanden']
const PUBLIC_NOTES_NAMES = ['Public Notes', 'Openbare notities', 'Gedeelde notities']

export function folderDisplayName(name) {
	const value = String(name ?? '')
	if (PUBLIC_NOTES_NAMES.includes(value)) {
		return t('Public Notes')
	}
	for (const suffix of SHARED_SUFFIXES) {
		if (value.length > suffix.length && value.endsWith(suffix)) {
			return t('{name} - Shared Files', { name: value.slice(0, -suffix.length) })
		}
	}
	return value
}
