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
