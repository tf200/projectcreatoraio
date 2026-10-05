// The What-If scenario is a list of changes the server lays over the live plan.
// These helpers keep that list tidy as the user edits it and describe it in words.

const TASK_LENGTH_TYPES = ['duration', 'endDate']

/**
 * "3 days", "1 week", "2 weeks"; whole weeks read better than multiples of seven days.
 * @param {number} days number of days; the sign is ignored
 */
export function formatDays(days) {
	const n = Math.abs(days)
	if (n >= 7 && n % 7 === 0) {
		const weeks = n / 7
		return `${weeks} ${weeks === 1 ? 'week' : 'weeks'}`
	}
	return `${n} ${n === 1 ? 'day' : 'days'}`
}

/**
 * Badge text for how far something moved: "+2w", "−3d", "" when it did not move.
 * @param {number} days how far it moved, negative for earlier
 */
export function formatShiftBadge(days) {
	if (!days) {
		return ''
	}
	const n = Math.abs(days)
	const amount = n >= 7 && n % 7 === 0 ? `${n / 7}w` : `${n}d`
	return `${days > 0 ? '+' : '−'}${amount}`
}

const sameTask = (a, b) => String(a.taskId) === String(b.taskId)
const sameDependency = (a, b) => String(a.predecessorId) === String(b.predecessorId) && String(a.successorId) === String(b.successorId)

/**
 * Add a change to the scenario without piling up duplicates: a new length, end date,
 * start limit or overlap replaces the previous one for the same card, planning changes
 * merge, and repeated delays of the same card in a row add up (cancelling out removes them).
 *
 * @param {Array<object>} changes the scenario so far
 * @param {object} change the change to add
 * @param {{ mergeDelays?: boolean }} options Suggestions pass false, so a fix stays visible next to the delay it offsets
 * @return {Array<object>} a new list
 */
export function addChange(changes, change, { mergeDelays = true } = {}) {
	const next = changes.slice()
	switch (change.type) {
	case 'delay': {
		const last = next[next.length - 1]
		if (mergeDelays && last?.type === 'delay' && sameTask(last, change)) {
			const days = last.days + change.days
			next.pop()
			return days === 0 ? next : [...next, { ...last, days }]
		}
		return change.days === 0 ? next : [...next, change]
	}
	case 'duration':
	case 'endDate':
		return [...next.filter(c => !(TASK_LENGTH_TYPES.includes(c.type) && sameTask(c, change))), change]
	case 'startNotBefore':
		return [...next.filter(c => !(c.type === 'startNotBefore' && sameTask(c, change))), change]
	case 'overlap':
		return [...next.filter(c => !(c.type === 'overlap' && sameDependency(c, change))), change]
	case 'planning': {
		const index = next.findIndex(c => c.type === 'planning')
		if (index === -1) {
			return [...next, change]
		}
		next[index] = { ...next[index], ...change }
		return next
	}
	default:
		return [...next, change]
	}
}

/**
 * @param {object} change the change to describe
 * @param {(id: number|string) => string} labelOf card name for an id
 * @param {(date: string) => string} formatDate turns YYYY-MM-DD into a display date
 */
export function describeChange(change, labelOf, formatDate) {
	switch (change.type) {
	case 'delay':
		return change.days > 0
			? `Delay “${labelOf(change.taskId)}” by ${formatDays(change.days)}`
			: `Finish “${labelOf(change.taskId)}” ${formatDays(change.days)} earlier`
	case 'duration':
		return `“${labelOf(change.taskId)}” takes ${formatDays(change.days)}`
	case 'endDate':
		return `“${labelOf(change.taskId)}” ends on ${formatDate(change.date)}`
	case 'startNotBefore':
		return change.date
			? `“${labelOf(change.taskId)}” can’t start before ${formatDate(change.date)}`
			: `“${labelOf(change.taskId)}” has no start limit`
	case 'overlap':
		return change.days > 0
			? `“${labelOf(change.successorId)}” starts ${formatDays(change.days)} before “${labelOf(change.predecessorId)}” ends`
			: `“${labelOf(change.successorId)}” waits for “${labelOf(change.predecessorId)}” to finish`
	case 'planning': {
		const parts = []
		if ('desiredStartDate' in change) {
			parts.push(change.desiredStartDate ? `Desired start on ${formatDate(change.desiredStartDate)}` : 'No desired start')
		}
		if ('requiredPreparationWeeks' in change) {
			parts.push(`Preparation takes ${formatDays(change.requiredPreparationWeeks * 7)}`)
		}
		return parts.join(' · ')
	}
	default:
		return 'Unknown change'
	}
}

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * Shift a YYYY-MM-DD date by whole days, in UTC so daylight saving never skips a day.
 * @param {string} date YYYY-MM-DD
 * @param {number} days days to add, negative for earlier
 */
export function shiftDate(date, days) {
	const [year, month, day] = date.split('-').map(Number)
	return new Date(Date.UTC(year, month - 1, day) + days * DAY_MS).toISOString().slice(0, 10)
}

/**
 * Days from one YYYY-MM-DD date to another.
 * @param {string} from YYYY-MM-DD
 * @param {string} to YYYY-MM-DD
 */
export function daysBetween(from, to) {
	const parse = value => {
		const [year, month, day] = value.split('-').map(Number)
		return Date.UTC(year, month - 1, day)
	}
	return Math.round((parse(to) - parse(from)) / DAY_MS)
}

/**
 * The scenario changes that move a card's start by some days, as dragging it does.
 * Later becomes a "can't start before" date. Earlier lowers that date when it is what holds
 * the card back, and otherwise lets the card overlap the cards it waits for. A card that waits
 * for nothing already starts as early as the plan allows.
 *
 * @param {object} task scenario task: id, label, startDate, startNotBefore
 * @param {number} days how far to move the start, negative for earlier
 * @param {Array<{predecessorEndDate: string, predecessorId: number|string, overlapDays: number}>} incoming the dependencies the card waits for
 * @return {{changes: Array<object>, reason: string}} the changes, or why there are none
 */
export function changesForMove(task, days, incoming) {
	if (!days) {
		return { changes: [], reason: '' }
	}
	const newStart = shiftDate(task.startDate, days)
	if (days > 0) {
		return { changes: [{ type: 'startNotBefore', taskId: task.id, date: newStart }], reason: '' }
	}

	const changes = []
	const limitHolds = !!task.startNotBefore && task.startNotBefore >= task.startDate
	if (limitHolds) {
		changes.push({ type: 'startNotBefore', taskId: task.id, date: newStart })
	}
	for (const dependency of incoming) {
		// The card may start the day after its predecessor ends, minus any overlap.
		const overlap = daysBetween(newStart, shiftDate(dependency.predecessorEndDate, 1))
		if (overlap > (dependency.overlapDays || 0)) {
			changes.push({ type: 'overlap', predecessorId: dependency.predecessorId, successorId: task.id, days: overlap })
		}
	}
	if (!changes.length) {
		return {
			changes,
			reason: incoming.length
				? `“${task.label}” already starts as early as the cards before it allow.`
				: `“${task.label}” doesn’t wait for another card, so it can’t start earlier in a scenario. Change its start date in Deck instead.`,
		}
	}
	return { changes, reason: '' }
}

/**
 * The scenario change that stretches or shrinks a card from its end, as dragging its edge does.
 * @param {object} task scenario task: id, durationDays
 * @param {number} days days to add to its length, negative to shorten
 * @return {object|null} a duration change, or null when the length stays the same
 */
export function changeForResize(task, days) {
	const length = Math.max(1, task.durationDays + days)
	return length === task.durationDays ? null : { type: 'duration', taskId: task.id, days: length }
}
