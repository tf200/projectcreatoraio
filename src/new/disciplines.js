import { t } from './l10n.mjs'

// The disciplines a project can cover, in the order the server stores them
// (Project::DISCIPLINES in lib/Db/Project.php; a test keeps the two equal).
// The value is the stored key; the label is read in the user's language each time.
export const DISCIPLINES = [
	{ value: 'electricity', get label() { return t('Electricity') } },
	{ value: 'water', get label() { return t('Water') } },
	{ value: 'gas', get label() { return t('Gas') } },
	{ value: 'coax', get label() { return t('Coax') } },
	{ value: 'fiber_optic', get label() { return t('Fiber optic') } },
	{ value: 'sewerage', get label() { return t('Sewerage') } },
]

// Known keys only, once each, in the fixed order: what the server would store.
export function disciplinesOf(values) {
	const chosen = Array.isArray(values) ? values : []
	return DISCIPLINES.map(d => d.value).filter(value => chosen.includes(value))
}
