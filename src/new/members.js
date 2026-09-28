// The eight DRASCIVS responsibilities, in the order the current interface lists them.
export const DRASCIVS = [
	{ value: 'driver', label: 'Driver' },
	{ value: 'responsible', label: 'Responsible' },
	{ value: 'accountable', label: 'Accountable' },
	{ value: 'supportive', label: 'Supportive' },
	{ value: 'consulted', label: 'Consulted' },
	{ value: 'informed', label: 'Informed' },
	{ value: 'verifier', label: 'Verifier' },
	{ value: 'signer', label: 'Signer' },
]

// Older members carry their roles under earlier field names.
export function rolesOf(member) {
	const roles = member?.drascivsRoles || member?.drasciRoles || (member?.drasciRole ? [member.drasciRole] : [])
	return Array.isArray(roles) ? roles.filter(Boolean) : []
}

export function drascivsLabel(value) {
	return DRASCIVS.find(option => option.value === value)?.label || value
}

// Project roles take the theme's category colours in a fixed order, so a role
// keeps its colour for as long as the project's role list does.
const TONES = [3, 5, 4, 2, 1]
export function projectRoleOptions(functionalRoles) {
	return (functionalRoles || []).map((role, index) => ({ value: role.key, label: role.name || role.key, tone: TONES[index % TONES.length] }))
}

// Who holds each responsibility, worked out from the members' own roles.
export function coverage(members) {
	return DRASCIVS.map(option => ({
		...option,
		holders: (members || []).filter(member => rolesOf(member).includes(option.value)).map(member => member.displayName || member.id),
	}))
}

// The same rules the server enforces, said before the request is sent.
export function draftProblem({ needsPerson = false, person = null, drascivs = [], functional = [] }) {
	if (needsPerson && !person) return 'Choose a person to add.'
	if (!drascivs.length) return 'Choose at least one DRASCIVS role.'
	if (!functional.length) return 'Choose at least one project role.'
	return ''
}

export function toggled(list, value) {
	return list.includes(value) ? list.filter(item => item !== value) : [...list, value]
}
