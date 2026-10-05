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
