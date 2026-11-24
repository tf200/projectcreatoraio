// The New project page of the new layout: the same fields and request as the
// current interface's create form, plus the disciplines and what Create will set up.
import { disciplinesOf } from './disciplines.js'
import { t, formatNumber } from './l10n.mjs'

// The id is what the server stores; label and text are read in the user's
// language each time they are shown.
export const TYPES = [
	{ id: 0, get label() { return t('Combi') }, get text() { return t('Starts with the 20 Combi process tasks and the intake form.') } },
	{ id: 1, get label() { return t('Solo Elektra') }, get text() { return t('The six standard columns, without seeded tasks.') } },
	{ id: 2, get label() { return t('Solo Water') }, get text() { return t('The six standard columns, without seeded tasks.') } },
	{ id: 3, get label() { return t('Custom') }, get text() { return t('The six standard columns, to shape yourself.') } },
]

export function emptyForm() {
	return {
		name: '',
		number: '',
		type: null,
		organizationId: null,
		clientName: '',
		clientRoles: [],
		clientPhone: '',
		clientEmail: '',
		clientAddress: '',
		street: '',
		city: '',
		zip: '',
		disciplines: [],
		description: '',
	}
}

// What is still needed before Create can be pressed: the same rules as the
// current form (name, number, type, and an organisation for administrators).
export function missingOf(form, isAdmin) {
	return [
		!String(form.name || '').trim() && t('a name'),
		!String(form.number || '').trim() && t('a number'),
		!TYPES.some(type => type.id === form.type) && t('a type'),
		isAdmin && !(Number(form.organizationId) > 0) && t('an organisation'),
	].filter(Boolean)
}

export function sentence(parts) {
	if (parts.length <= 1) return parts.join('')
	return t('{list} and {last}', { list: parts.slice(0, -1).join(', '), last: parts[parts.length - 1] })
}

// The body the create endpoint takes, as the current interface sends it, plus
// the disciplines, which only this page asks for.
export function payloadOf(form) {
	return {
		name: form.name.trim(),
		number: form.number.trim(),
		description: form.description.trim(),
		client_name: form.clientName.trim(),
		client_role: [...new Set(form.clientRoles)],
		client_phone: form.clientPhone.trim(),
		client_email: form.clientEmail.trim(),
		client_address: form.clientAddress.trim(),
		loc_street: form.street.trim(),
		loc_city: form.city.trim(),
		loc_zip: form.zip.trim(),
		disciplines: disciplinesOf(form.disciplines),
		type: form.type,
		organizationId: Number(form.organizationId) > 0 ? Number(form.organizationId) : null,
		members: [],
	}
}

export function formatBytes(bytes) {
	const value = Number(bytes)
	if (!(value > 0)) return ''
	const gb = value / 1024 ** 3
	return gb >= 1 ? t('{size} GB', { size: formatNumber(gb) }) : t('{size} MB', { size: formatNumber(Math.round(value / 1024 ** 2), 0) })
}

// The list beside the form, named after the project as it is typed.
export function setupOf(form, allowance) {
	const name = String(form.name || '').trim() || t('Project name')
	const storage = formatBytes(allowance?.sharedStoragePerProject)
	return [
		{ key: 'board', title: t('{name} - Main Board', { name }), text: form.type === 0 ? t('Deck board, six columns, the 20 Combi process tasks') : t('Deck board with the six standard columns') },
		{ key: 'shared', title: t('{name} - Shared Files', { name }), text: storage ? t('Team folder for everyone on the project, {size}', { size: storage }) : t('Team folder for everyone on the project') },
		{ key: 'private', title: t('{name} - Private Files', { name }), text: t('A private folder for each member') },
		{ key: 'whiteboard', title: t('{name}.whiteboard', { name }), text: t('Whiteboard, shared in the project chat') },
		{ key: 'chat', title: t('{name} - Chat', { name }), text: t('Talk conversation for the team') },
	]
}

// The plan line: how many projects the plan allows and has, and whether one
// more fits. Null when the server has no plan to report.
export function planOf(allowance) {
	const max = Number(allowance?.maxProjects)
	const used = Number(allowance?.projectsCount)
	if (!Number.isFinite(max) || max <= 0 || !Number.isFinite(used)) return null
	return {
		organization: String(allowance.organizationName || ''),
		used,
		max,
		full: used >= max,
		percent: Math.min(100, Math.round(used / max * 100)),
	}
}

// The server's own sentence for a refused create, without its prefix or a
// PHP trace, as the current interface does.
export function failureOf(error) {
	let message = error?.response?.data?.message
	if (typeof message !== 'string' || !message.trim()) message = error?.message || ''
	message = message.replace(/^Failed to create project:\s*/, '').split('\nStack trace:')[0]
	const thrown = message.match(/(?:Exception|OCSException): (.+?) in \/var\/www\//s)
	if (thrown) message = thrown[1]
	message = message.trim()
	return message && !/^Request failed with status code/.test(message) ? message : t('The project could not be created. Please try again.')
}
