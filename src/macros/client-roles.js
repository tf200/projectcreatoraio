export const CLIENT_ROLE_OPTIONS = [
	{ value: 'project_sponsor', label: 'Project Sponsor' },
	{ value: 'project_owner', label: 'Project Owner' },
	{ value: 'key_stakeholder', label: 'Key Stakeholder' },
	{ value: 'business_contact', label: 'Business Contact' },
]

export function normalizeClientRoles(value) {
	let roles = []
	if (Array.isArray(value)) {
		roles = value
	} else if (typeof value === 'string' && value.trim()) {
		roles = [value]
	}

	const normalized = roles
		.filter((role) => typeof role === 'string' && role.trim())
		.map((role) => role.trim())
	return [...new Set(normalized)]
}

export function getClientRoleOptions(value) {
	return normalizeClientRoles(value).map((role) => CLIENT_ROLE_OPTIONS.find((option) => option.value === role) || {
		value: role,
		label: role,
	})
}

export function getClientRoleValues(options) {
	return normalizeClientRoles((options || []).map((option) => option?.value))
}

export function formatClientRoles(value) {
	const labels = getClientRoleOptions(value).map((option) => option.label)
	return labels.length ? labels.join(', ') : '-'
}
