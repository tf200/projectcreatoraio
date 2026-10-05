// Who can do what on a project's Deck board, from the project's
// deck-access-summary: the line on the Task Board and the table in Card
// Permissions of the new layout.

export const ACTIONS = [
	{ key: 'view', label: 'View', verb: 'see' },
	{ key: 'move', label: 'Move', verb: 'move' },
	{ key: 'verify', label: 'Verify', verb: 'verify' },
	{ key: 'sign', label: 'Sign', verb: 'sign' },
]

const count = value => Math.max(0, Math.trunc(Number(value) || 0))
const listOf = value => (Array.isArray(value) ? value : [])

// The summary as the views use it. Every card someone may act on is known by
// name, so a member's left-out cards are the named cards missing from their
// lists; cards nobody listed can see stay unnamed and are only counted.
export function accessOf(summary) {
	if (!summary || typeof summary !== 'object' || !Array.isArray(summary.members)) throw new Error('Invalid access response')
	const total = count(summary.totalCards)
	const named = new Map()
	for (const member of summary.members) {
		for (const { key } of ACTIONS) {
			for (const card of listOf(member?.actions?.[key]?.allowedCards)) {
				const id = Number(card?.id)
				if (Number.isFinite(id) && !named.has(id)) named.set(id, String(card?.title ?? ''))
			}
		}
	}
	const members = summary.members.map(member => {
		const actions = {}
		const allowedIds = {}
		for (const { key } of ACTIONS) {
			const action = member?.actions?.[key]
			actions[key] = { allowed: Math.min(count(action?.allowed), total), total }
			allowedIds[key] = new Set(listOf(action?.allowedCards).map(card => Number(card?.id)))
		}
		// An action on no card at all is said once, not repeated on every card.
		const none = total ? ACTIONS.map(({ key }) => key).filter(key => actions[key].allowed === 0) : []
		const missing = []
		for (const [id, title] of named) {
			const keys = ACTIONS.map(({ key }) => key).filter(key => !none.includes(key) && !allowedIds[key].has(id))
			if (keys.length) missing.push({ id, title, actions: keys })
		}
		return {
			id: String(member?.id ?? ''),
			displayName: String(member?.displayName || member?.id || ''),
			isOwner: !!member?.isOwner,
			roles: [...listOf(member?.drascivsRoleLabels), ...listOf(member?.functionalRoleLabels)].map(String).filter(Boolean),
			boardAccess: ['edit', 'read'].includes(member?.boardAccess) ? member.boardAccess : 'none',
			actions,
			limited: ACTIONS.some(({ key }) => actions[key].allowed < total),
			none,
			missing,
		}
	})
	return { total, scope: summary.scope === 'self' ? 'self' : 'team', members, unnamed: Math.max(0, total - named.size) }
}

// "All 20", "19 of 20" or "None", with the pill tone that goes with it.
export function cellOf(action) {
	if (!action.total) return { text: '–', tone: 'muted' }
	if (action.allowed >= action.total) return { text: 'All ' + action.total, tone: 'success' }
	if (action.allowed === 0) return { text: 'None', tone: 'muted' }
	return { text: action.allowed + ' of ' + action.total, tone: 'warning' }
}

export function joined(parts, last = 'and') {
	if (parts.length <= 1) return parts.join('')
	return parts.slice(0, -1).join(', ') + ' ' + last + ' ' + parts[parts.length - 1]
}

// Up to three names; more become "and n others".
export function namesOf(names, max = 3) {
	if (names.length <= max) return joined(names)
	const rest = names.length - (max - 1)
	return names.slice(0, max - 1).join(', ') + ' and ' + rest + ' others'
}

export function cardsText(n) {
	return n + (n === 1 ? ' card' : ' cards')
}

// The line on the Task Board, in three parts so the names can stand out:
// lead, then who, then what they can't do. Null when there is nothing to say.
export function lineOf(access) {
	const { total, members } = access
	if (!total || !members.length) return null
	if (access.scope === 'self') {
		const own = members[0]
		if (!own.limited) return { lead: `You can view, move, verify and sign all ${cardsText(total)}.` }
		const view = own.actions.view.allowed
		const seen = view === total ? `see all ${cardsText(total)}` : `see ${view} of ${cardsText(total)}`
		const rest = ACTIONS.slice(1).map(({ key, verb }) => verb + ' ' + (own.actions[key].allowed || 'none'))
		return { lead: `You can ${joined([seen, ...rest])}.` }
	}
	const limited = members.filter(member => member.limited)
	if (!limited.length) {
		const who = members.length === 1 ? members[0].displayName : `All ${members.length} members`
		return { lead: '', who, tail: ` can view, move, verify and sign all ${cardsText(total)}.` }
	}
	const everyoneSees = members.every(member => member.actions.view.allowed >= total)
	const short = ACTIONS.filter(({ key }) => limited.some(member => member.actions[key].allowed < total)).map(({ verb }) => verb)
	return {
		lead: everyoneSees ? `Everyone can see all ${cardsText(total)}. ` : '',
		who: namesOf(limited.map(member => member.displayName)),
		tail: ` can't ${joined(short, 'or')} every card.`,
	}
}

// What a left-out card's actions read as in the table: "move, verify, sign", or "any action".
export function missingText(keys) {
	if (keys.length === ACTIONS.length) return 'any action'
	return keys.map(key => ACTIONS.find(action => action.key === key).verb).join(', ')
}

// What a member can do on no card at all, with the reason when the board says so.
export function noneText(member, self = false) {
	if (!member.none.length) return ''
	const verbs = ACTIONS.filter(({ key }) => member.none.includes(key)).map(({ verb }) => verb)
	const why = { read: ' (read only on this board)', none: ' (no access to this board)' }[member.boardAccess] || ''
	return (self ? 'You can’t ' : 'Can’t ') + joined(verbs, 'or') + ' any card' + why + '.'
}
