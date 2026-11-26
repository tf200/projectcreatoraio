/**
 * The name a Deck step (stack) is shown under.
 *
 * A new project's board gets its six standard steps in the server default
 * language, but boards made earlier keep the English names they were created
 * with ("Next priority", "Approved", …). Those standard names are shown in the
 * user's language; any other step name is the team's own and is shown as is.
 * The stored title is never changed.
 */
import { t } from './l10n.mjs'

const STANDARD_STEPS = new Map([
	['process steps', 'Process steps'],
	['next priority', 'Next priority'],
	['in progress', 'In progress'],
	['to review', 'To review'],
	['approved', 'Approved'],
	['done', 'Done'],
	['approved/done', 'Approved/Done'],
])

export function stepDisplayName(title) {
	const value = String(title ?? '')
	const standard = STANDARD_STEPS.get(value.trim().toLowerCase())
	return standard ? t(standard) : value
}
