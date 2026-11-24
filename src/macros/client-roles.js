import { t } from '../new/l10n.mjs'

/**
 * The client role's display name. The value is the stored code; an unknown
 * code is shown as it is. Translated at call time, never at module load.
 */
export function clientRoleLabel(value) {
	switch (value) {
	case 'project_sponsor': return t('Project Sponsor')
	case 'project_owner': return t('Project Owner')
	case 'key_stakeholder': return t('Key Stakeholder')
	case 'business_contact': return t('Business Contact')
	default: return value
	}
}

// `label` is a getter so each read is translated in the user's language.
export const CLIENT_ROLE_OPTIONS = ['project_sponsor', 'project_owner', 'key_stakeholder', 'business_contact']
	.map((value) => ({
		value,
		get label() {
			return clientRoleLabel(value)
		},
	}))

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
