// Who can do what on a project's Deck board, from the project's
// deck-access-summary: the line on the Task Board and the table in Card
// Permissions of the new layout.
import { t, n } from './l10n.mjs'
import { drascivsLabel } from './members.js'

// label heads the table's column, verb goes into a sentence; both are read in
// the user's language when shown, the key is what the code compares.
export const ACTIONS = [
	{ key: 'view', get label() { return t('View') }, get verb() { return t('see') } },
	{ key: 'move', get label() { return t('Move') }, get verb() { return t('move') } },
	{ key: 'verify', get label() { return t('Verify') }, get verb() { return t('verify') } },
	{ key: 'sign', get label() { return t('Sign') }, get verb() { return t('sign') } },
]

// A sentence with one part set apart (a name in bold): the text before and
// after that part, so the translation decides where it goes.
const MARK = '\u0001'
function around(text) {
	const at = text.indexOf(MARK)
	return at < 0 ? [text, ''] : [text.slice(0, at), text.slice(at + MARK.length)]
}

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
			// DRASCIVS roles by their code, so they read in the user's language; the
			// project roles are the project type's own names.
			roles: [...(listOf(member?.drascivsRoles).length ? listOf(member.drascivsRoles).map(drascivsLabel) : listOf(member?.drascivsRoleLabels)), ...listOf(member?.functionalRoleLabels)].map(String).filter(Boolean),
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
	if (action.allowed >= action.total) return { text: t('All {n}', { n: action.total }), tone: 'success' }
	if (action.allowed === 0) return { text: t('None'), tone: 'muted' }
	return { text: t('{allowed} of {total}', { allowed: action.allowed, total: action.total }), tone: 'warning' }
}

// last is a code, 'and' or 'or'; the words come from the translation.
export function joined(parts, last = 'and') {
	if (parts.length <= 1) return parts.join('')
	const vars = { list: parts.slice(0, -1).join(', '), last: parts[parts.length - 1] }
	return last === 'or' ? t('{list} or {last}', vars) : t('{list} and {last}', vars)
}

// Up to three names; more become "and n others".
export function namesOf(names, max = 3) {
	if (names.length <= max) return joined(names)
	const rest = names.length - (max - 1)
	return t('{names} and {n} others', { names: names.slice(0, max - 1).join(', '), n: rest })
}

export function cardsText(total) {
	return n('%n card', '%n cards', total)
}

// The line on the Task Board, in three parts so the names can stand out:
// lead, then who, then what they can't do. Null when there is nothing to say.
export function lineOf(access) {
	const { total, members } = access
	if (!total || !members.length) return null
	if (access.scope === 'self') {
		const own = members[0]
		const cards = cardsText(total)
		if (!own.limited) return { lead: t('You can view, move, verify and sign all {cards}.', { cards }) }
		const view = own.actions.view.allowed
		const [move, verify, sign] = ['move', 'verify', 'sign'].map(key => own.actions[key].allowed || t('none'))
		return {
			lead: view === total
				? t('You can see all {cards}, move {move}, verify {verify} and sign {sign}.', { cards, move, verify, sign })
				: t('You can see {seen} of {cards}, move {move}, verify {verify} and sign {sign}.', { seen: view, cards, move, verify, sign }),
		}
	}
	const limited = members.filter(member => member.limited)
	if (!limited.length) {
		if (members.length !== 1) return { lead: t('All {n} members can view, move, verify and sign all {cards}.', { n: members.length, cards: cardsText(total) }), who: '', tail: '' }
		const [lead, tail] = around(t('{name} can view, move, verify and sign all {cards}.', { name: MARK, cards: cardsText(total) }))
		return { lead, who: members[0].displayName, tail }
	}
	const everyoneSees = members.every(member => member.actions.view.allowed >= total)
	const short = ACTIONS.filter(({ key }) => limited.some(member => member.actions[key].allowed < total)).map(({ verb }) => verb)
	const [before, tail] = around(t('{names} can\'t {actions} every card.', { names: MARK, actions: joined(short, 'or') }))
	return {
		lead: (everyoneSees ? t('Everyone can see all {cards}.', { cards: cardsText(total) }) + ' ' : '') + before,
		who: namesOf(limited.map(member => member.displayName)),
		tail,
	}
}

// What a left-out card's actions read as in the table: "move, verify, sign", or "any action".
export function missingText(keys) {
	if (keys.length === ACTIONS.length) return t('any action')
	return keys.map(key => ACTIONS.find(action => action.key === key).verb).join(', ')
}

// What a member can do on no card at all, with the reason when the board says so.
export function noneText(member, self = false) {
	if (!member.none.length) return ''
	const verbs = ACTIONS.filter(({ key }) => member.none.includes(key)).map(({ verb }) => verb)
	const actions = joined(verbs, 'or')
	const sentence = self ? t('You can’t {actions} any card.', { actions }) : t('Can’t {actions} any card.', { actions })
	const why = { read: t('(read only on this board)'), none: t('(no access to this board)') }[member.boardAccess]
	// The reason goes inside the sentence, before its full stop.
	return why ? sentence.replace(/\.$/, '') + ' ' + why + '.' : sentence
}
