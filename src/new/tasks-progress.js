// Progress on a project's Deck board, for the Tasks tab of the new layout:
// the same counts as Deck's own dashboard, plus the project's critical process
// steps in process order.
import { t, n, locale } from './l10n.mjs'

// A card is a critical process step when it carries this label. "Kritieke
// Processtap" and "Belangrijk" are its former names on older boards; the match
// ignores case, as Deck's dashboard does.
export const CRITICAL_LABELS = ['Kritieke processtap', 'Kritieke Processtap', 'Belangrijk']
export function isCriticalLabel(label) {
	const title = String(label?.title ?? '').trim().toLowerCase()
	return CRITICAL_LABELS.some(name => name.toLowerCase() === title)
}

// Default card titles the client corrected (review of 25 November 2026), so
// boards renamed afterwards still sort in process order.
const RENAMED_TITLES = {
	Situatietekening: 'Situatie tekening',
	Garantieovereenkomst: 'Garantie overeenkomst',
	'Zakelijk recht': 'Zakelijkrecht',
	'Intake inplannen en leiden': 'Intake inplannen & hosten',
}

// The Combi process in timeline order, by card title. Mirrors
// ProjectTypeDeckDefaults::getDefaultCardKeysInTimelineOrder(); a test keeps
// the two in step. Used only to break ties the board's dependencies leave open.
export const TIMELINE_ORDER = [
	'Intakeformulier', 'Piekvermogensformulier', 'Quickscan', 'Situatie tekening', 'AVP', 'VO',
	'Intake inplannen & hosten', 'Intakeverslag', 'DO', 'Hoogbouwoverleg inplannen', 'VO inpandige tekeningen',
	'Verslag inpandig overleg', 'DO inpandige tekeningen', 'Blokkenschema', 'Aanvraag particuliere grond',
	'Bodemrapport', 'Saneringsevaluatierapport', 'Zakelijkrecht', 'Huisnummerbesluit', 'Garantie overeenkomst',
]

const DONE_COLUMN = /done|afgerond|completed|afgehandeld/i
const time = value => (value ? new Date(value).getTime() : NaN)

// The board's columns in order and its live cards, each with the column it is
// in (stage), whether it counts as completed and whether it is critical.
export function boardOf(stacks) {
	const columns = (stacks || [])
		.filter(stack => stack && !stack.deletedAt)
		.slice()
		.sort((a, b) => (a.order ?? 0) - (b.order ?? 0) || a.id - b.id)
		.map(stack => ({ id: stack.id, title: stack.title || '', done: !!stack.isDoneColumn || DONE_COLUMN.test(stack.title || ''), cards: stack.cards || [] }))
	const cards = columns.flatMap((column, stage) => column.cards
		.filter(card => card && !card.archived && !card.deletedAt)
		.map(card => ({
			id: card.id,
			title: String(card.title || '').trim(),
			stage,
			column: column.title,
			done: card.done || null,
			duedate: card.duedate || null,
			completed: !!card.done || column.done,
			critical: (card.labels || []).some(isCriticalLabel),
			dependsOn: Array.isArray(card.dependentCards) ? card.dependentCards.map(Number) : [],
		})))
	return { columns: columns.map(({ cards: _cards, ...column }) => column), cards }
}

// The four numbers Deck's dashboard shows, counted the same way.
export function countsOf(cards, now = Date.now()) {
	const critical = cards.filter(card => card.critical)
	const other = cards.filter(card => !card.critical)
	const completed = cards.filter(card => card.completed).length
	return {
		total: cards.length,
		completed,
		percent: cards.length ? Math.round(completed / cards.length * 100) : 0,
		overdue: cards.filter(card => !card.completed && time(card.duedate) < now).length,
		criticalOpen: critical.filter(card => !card.completed).length,
		criticalTotal: critical.length,
		criticalDone: critical.filter(card => card.completed).length,
		otherOpen: other.filter(card => !card.completed).length,
		otherTotal: other.length,
	}
}

// Critical steps in process order: a step comes after every step it depends
// on, also through non-critical cards in between. Where the dependencies leave
// a choice, the Combi timeline decides, then the due date, then the title.
// Open steps come first, completed ones after them.
export function stepsOf(cards) {
	const byId = new Map(cards.map(card => [card.id, card]))
	const steps = cards.filter(card => card.critical)
	const stepIds = new Set(steps.map(card => card.id))
	const ancestors = new Map(steps.map(step => {
		const seen = new Set()
		const walk = id => {
			for (const dep of byId.get(id)?.dependsOn || []) {
				if (!seen.has(dep)) { seen.add(dep); walk(dep) }
			}
		}
		walk(step.id)
		return [step.id, [...seen].filter(id => stepIds.has(id))]
	}))
	const rank = card => {
		// New boards may carry the default titles in the server's language.
		const canonical = RENAMED_TITLES[card.title] ?? card.title
		const index = TIMELINE_ORDER.findIndex(title => title === canonical || t(title) === card.title)
		return [index === -1 ? TIMELINE_ORDER.length : index, Number.isNaN(time(card.duedate)) ? Infinity : time(card.duedate), card.title]
	}
	const before = (a, b) => {
		const [x, y] = [rank(a), rank(b)]
		return x[0] - y[0] || x[1] - y[1] || x[2].localeCompare(y[2])
	}
	const ordered = []
	const placed = new Set()
	let left = steps.slice()
	while (left.length) {
		const ready = left.filter(step => ancestors.get(step.id).every(id => placed.has(id)))
		// A dependency cycle cannot block the list: fall back to the rank alone.
		const next = (ready.length ? ready : left).slice().sort(before)[0]
		ordered.push(next)
		placed.add(next.id)
		left = left.filter(step => step !== next)
	}
	return [...ordered.filter(step => !step.completed), ...ordered.filter(step => step.completed)]
}

const DAY = 86400000

export function shortDate(value, now = Date.now()) {
	const date = new Date(value)
	return date.toLocaleDateString(locale(), { day: 'numeric', month: 'short', ...(date.getFullYear() !== new Date(now).getFullYear() ? { year: 'numeric' } : {}) })
}

// What the Due column says for a step, and how it should read.
export function dueOf(step, now = Date.now()) {
	if (step.completed) return { text: step.done ? t('Done {date}', { date: shortDate(step.done, now) }) : t('Done'), tone: 'done' }
	const due = time(step.duedate)
	if (Number.isNaN(due)) return { text: t('Not planned'), tone: 'muted' }
	if (due < now) {
		const days = Math.max(1, Math.floor((now - due) / DAY))
		return { text: n('%n day late', '%n days late', days), tone: 'late' }
	}
	return { text: shortDate(due, now), tone: 'normal' }
}
