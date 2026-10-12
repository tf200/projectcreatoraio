// The disciplines a project can cover, in the order the server stores them
// (Project::DISCIPLINES in lib/Db/Project.php; a test keeps the two equal).
export const DISCIPLINES = [
	{ value: 'electricity', label: 'Electricity' },
	{ value: 'water', label: 'Water' },
	{ value: 'gas', label: 'Gas' },
	{ value: 'coax', label: 'Coax' },
	{ value: 'fiber_optic', label: 'Fiber optic' },
	{ value: 'sewerage', label: 'Sewerage' },
]

// Known keys only, once each, in the fixed order: what the server would store.
export function disciplinesOf(values) {
	const chosen = Array.isArray(values) ? values : []
	return DISCIPLINES.map(d => d.value).filter(value => chosen.includes(value))
}
