export const DAY_MS = 1000 * 60 * 60 * 24

export function toDateOnly(date) {
	const d = new Date(date)
	d.setHours(0, 0, 0, 0)
	return d
}

export function addDays(date, days) {
	const d = new Date(date)
	d.setDate(d.getDate() + days)
	return d
}

export function getIsoWeekInfo(date) {
	const d = toDateOnly(date)
	const utcDate = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
	const day = utcDate.getUTCDay() || 7
	utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day)
	const isoYear = utcDate.getUTCFullYear()
	const yearStart = new Date(Date.UTC(isoYear, 0, 1))
	const isoWeek = Math.ceil(((utcDate - yearStart) / DAY_MS) + 1) / 7
	return { isoWeek: Math.ceil(isoWeek), isoYear }
}

/**
 * Days from `date` back to the Monday of its week (0 for Monday, 6 for Sunday).
 * @param {Date} date local calendar date
 */
export function daysSinceMonday(date) {
	return (date.getDay() + 6) % 7
}

export function formatShortDate(date, withYear = true) {
	const opts = { day: 'numeric', month: 'short' }
	if (withYear) opts.year = 'numeric'
	return date.toLocaleDateString('default', opts)
}

/**
 * "Today", "in 3w 2d", "5d ago"
 * @param {number} days signed day difference from today
 */
export function formatRelativeDays(days) {
	if (days === 0) return 'Today'
	const abs = Math.abs(days)
	const weeks = Math.floor(abs / 7)
	const rest = abs % 7
	const parts = []
	if (weeks) parts.push(`${weeks}w`)
	if (rest || !weeks) parts.push(`${rest}d`)
	const span = parts.join(' ')
	return days > 0 ? `in ${span}` : `${span} ago`
}

// The years a typed date may fall in; the server refuses anything outside.
export const FIRST_DAY = '2000-01-01'
export const LAST_DAY = '2100-12-31'

const dayOf = value => (typeof value === 'string' && value ? value.slice(0, 10) : '')
const later = (a, b) => (a > b ? a : b)

// "5 Oct 2026", as the server words it.
function dayText(day) {
	const date = new Date(day + 'T00:00:00')
	return Number.isNaN(date.getTime()) ? day : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

/**
 * The days each typed-in date may take. Nothing comes before the request
 * date, and handover, actual start and end stay in that order. The desired
 * start is a plan, so only the request date holds it. The server checks the
 * same rules (TimelineDates.php); how dates compare with the plan is a
 * warning the server sends as kpis.dateWarnings.
 * @param {object} kpis the timeline summary's kpis
 */
export function plannedDateBounds(kpis) {
	const request = dayOf(kpis?.requestDate) || FIRST_DAY
	const handover = dayOf(kpis?.actualHandoverDate)
	const start = dayOf(kpis?.actualStartDate)
	const end = dayOf(kpis?.actualEndDate)
	return {
		desiredStart: { min: request, max: LAST_DAY },
		handover: { min: request, max: start || end || LAST_DAY },
		actualStart: { min: later(handover, request), max: end || LAST_DAY },
		end: { min: later(start || handover, request), max: LAST_DAY },
		// The end is the last day on site, so the work has to have started.
		canSetEnd: !!start || !!end,
		canClearStart: !end,
	}
}

const NAMES = { desiredStart: 'desired start', handover: 'handover', actualStart: 'actual start', end: 'end date' }

/**
 * Why a picked day would be refused, worded like the server, or ''.
 * @param {object} kpis the timeline summary's kpis
 * @param {'desiredStart'|'handover'|'actualStart'|'end'} field the date being set
 * @param {string} value the picked day, YYYY-MM-DD
 */
export function dateRefusal(kpis, field, value) {
	const day = dayOf(value)
	if (!day) return ''
	const name = NAMES[field]
	if (day < FIRST_DAY || day > LAST_DAY) return `The ${name} must fall between 2000 and 2100.`
	const request = dayOf(kpis?.requestDate)
	if (request && day < request) return `The ${name} (${dayText(day)}) can’t be before the request date (${dayText(request)}).`
	const dates = { handover: dayOf(kpis?.actualHandoverDate), actualStart: dayOf(kpis?.actualStartDate), end: dayOf(kpis?.actualEndDate), [field]: day }
	if (field === 'end' && !dates.actualStart) return 'Set the actual start before the end date.'
	const order = [['handover', 'actualStart'], ['actualStart', 'end'], ['handover', 'end']]
	for (const [earlier, laterField] of order) {
		const first = dates[earlier]
		const second = dates[laterField]
		if (!first || !second || second >= first) continue
		if (field === laterField) return `The ${NAMES[laterField]} (${dayText(second)}) can’t be before the ${NAMES[earlier]} (${dayText(first)}).`
		if (field === earlier) return `The ${NAMES[earlier]} (${dayText(first)}) can’t be after the ${NAMES[laterField]} (${dayText(second)}).`
	}
	return ''
}
