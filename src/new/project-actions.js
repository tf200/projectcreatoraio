// What the header's More menu may offer, and the bodies it sends: the same
// rules the server applies to PUT /projects/{id} and DELETE /projects/{id}.
import { normalizeClientRoles } from '../macros/client-roles.js'

// Administrators of the project's organisation edit everything; the owner may
// rename it, change its status and delete it; every member edits the client
// and the address.
export function permissionsOf(project, context) {
	const userId = String(context?.userId || '').trim()
	const isAdmin = !!(context?.isGlobalAdmin
		|| (context?.organizationRole === 'admin' && Number(context?.organizationId) === Number(project?.organization_id)))
	const isOwner = userId !== '' && String(project?.ownerId || '').trim() === userId
	return {
		rename: isAdmin || isOwner,
		describe: isAdmin,
		status: isAdmin || isOwner,
		delete: isAdmin || isOwner,
		client: true,
	}
}

export function detailsDraft(project) {
	return { name: project?.name || '', description: project?.description || '' }
}

export function clientDraft(project) {
	return {
		client_name: project?.client_name || '',
		client_role: normalizeClientRoles(project?.client_role),
		client_phone: project?.client_phone || '',
		client_email: project?.client_email || '',
		client_address: project?.client_address || '',
		loc_street: project?.loc_street || '',
		loc_city: project?.loc_city || '',
		loc_zip: project?.loc_zip || '',
	}
}

// Only the fields this person may change and actually changed, trimmed, so a
// member never sends a field the server would refuse.
export function changesOf(draft, project, allowed) {
	const body = {}
	for (const [key, value] of Object.entries(draft)) {
		if (!allowed.includes(key)) continue
		const next = Array.isArray(value) ? normalizeClientRoles(value) : String(value).trim()
		const before = Array.isArray(value) ? normalizeClientRoles(project?.[key]) : String(project?.[key] ?? '').trim()
		if (JSON.stringify(next) !== JSON.stringify(before)) body[key] = next
	}
	return body
}

export function detailsFields(permissions) {
	return [permissions.rename && 'name', permissions.describe && 'description'].filter(Boolean)
}

export const CLIENT_FIELDS = ['client_name', 'client_role', 'client_phone', 'client_email', 'client_address', 'loc_street', 'loc_city', 'loc_zip']

export function messageOf(error, fallback) {
	const message = error?.response?.data?.message ?? error?.response?.data?.ocs?.meta?.message
	return typeof message === 'string' && message.trim() ? message.trim() : fallback
}
