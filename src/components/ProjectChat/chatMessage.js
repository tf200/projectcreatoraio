// Turns Talk's rich message parameters into NcRichText arguments, the way Talk's own chat does.

const MENTION_TYPES = ['user', 'guest', 'call', 'user-group', 'group', 'email', 'federated_user', 'circle', 'team']

export function isMention(parameter) {
	return MENTION_TYPES.includes(parameter?.type)
}

/**
 * @param {object} parameters the message's messageParameters
 * @param {object} components the Vue components that render each kind of part
 * @param {object} components.mention renders a user, group or "@all" mention
 * @param {object} components.file renders a shared file
 * @param {object} components.object renders any other shared object
 * @param {string} currentUserId the viewer, whose own mentions are highlighted
 */
export function buildRichArguments(parameters, { mention, file, object }, currentUserId = '') {
	const args = {}
	for (const [key, parameter] of Object.entries(parameters || {})) {
		if (!parameter || typeof parameter !== 'object') {
			continue
		}
		if (isMention(parameter)) {
			args[key] = { component: mention, props: { parameter, currentUserId } }
		} else if (parameter.type === 'file') {
			args[key] = { component: file, props: { file: parameter } }
		} else {
			args[key] = { component: object, props: { parameter } }
		}
	}
	return args
}

// A file share carries its file as {file}; a message that is only "{file}" has no caption.
export function splitFileShare(message) {
	const file = message?.messageParameters?.file
	if (!file || file.type !== 'file') {
		return { file: null, caption: message?.message || '' }
	}
	const caption = message.message === '{file}' ? '' : message.message.replace('{file}', '').trim()
	return { file, caption }
}

// One-line text for a reply quote: placeholders become names.
export function plainText(message) {
	const parameters = message?.messageParameters || {}
	return (message?.message || '').replace(/\{([a-z\-_.0-9]+)\}/gi, (match, key) => {
		const parameter = parameters[key]
		if (!parameter?.name) {
			return match
		}
		return isMention(parameter) ? `@${parameter.name}` : parameter.name
	})
}

export function reactionList(reactions) {
	if (!reactions || Array.isArray(reactions)) {
		return []
	}
	return Object.entries(reactions).map(([emoji, count]) => ({ emoji, count }))
}

export function fileSizeLabel(bytes) {
	let size = Number(bytes)
	if (!Number.isFinite(size) || size <= 0) {
		return ''
	}
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let unit = 0
	while (size >= 1024 && unit < units.length - 1) {
		size /= 1024
		unit++
	}
	return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

const MEDIA_KINDS = {
	'voice-message': 'audio',
	'record-audio': 'audio',
	'record-video': 'video',
}

// Voice messages and call recordings play inline, like in Talk.
export function mediaKind(message) {
	return MEDIA_KINDS[message?.messageType] || null
}

// The viewer's own copy of the shared file, as Talk plays it.
export function davFileUrl(remoteRoot, file) {
	const path = String(file?.path || '').replace(/^\/+/, '')
	if (!remoteRoot || path === '') {
		return ''
	}
	return `${remoteRoot}/${path.split('/').map(encodeURIComponent).join('/')}`
}
