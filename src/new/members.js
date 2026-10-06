// The eight DRASCIVS responsibilities, in the order the current interface lists
// them; the letter heads the member matrix's column (S twice, so the name goes under it).
export const DRASCIVS = [
	{ value: 'driver', label: 'Driver', letter: 'D' },
	{ value: 'responsible', label: 'Responsible', letter: 'R' },
	{ value: 'accountable', label: 'Accountable', letter: 'A' },
	{ value: 'supportive', label: 'Supportive', letter: 'S' },
	{ value: 'consulted', label: 'Consulted', letter: 'C' },
	{ value: 'informed', label: 'Informed', letter: 'I' },
	{ value: 'verifier', label: 'Verifier', letter: 'V' },
	{ value: 'signer', label: 'Signer', letter: 'S' },
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

// An external collaborator needs an email address, a name and a DRASCIVS
// role; a project role is optional for them.
export function inviteProblem({ email = '', name = '', drascivs = [] }) {
	if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) return 'Enter the email address of the person to invite.'
	if (!name.trim()) return 'Enter their name.'
	if (!drascivs.length) return 'Choose at least one DRASCIVS role.'
	return ''
}

export function externalLabel(external) {
	const company = String(external?.company || '').trim()
	return company ? 'External · ' + company : 'External'
}

// "12 Nov", with the year when it is not this year.
export function shortDate(value, now = new Date()) {
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) return ''
	const options = { day: 'numeric', month: 'short' }
	if (date.getFullYear() !== now.getFullYear()) options.year = 'numeric'
	return date.toLocaleDateString(undefined, options)
}

// Access ends within the week the warning email covers.
export function endsSoon(external, now = Date.now()) {
	if (!external?.expiresAt) return false
	const end = new Date(external.expiresAt).getTime()
	return Number.isFinite(end) && end - now < 7 * 86400000
}

export function isoDay(date) {
	const pad = value => String(value).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
