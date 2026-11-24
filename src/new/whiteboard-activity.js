// Whiteboard activity arrives as one event per save, and the board autosaves
// every ten seconds while someone draws. These helpers turn those saves into
// editing sessions, grouped by day.

import { t, locale, formatNumber } from './l10n.mjs'

// Saves by one person less than this far apart belong to one sitting.
export const SESSION_GAP_MS = 20 * 60 * 1000

const time = event => new Date(event.occurredAt).getTime()
const sizeOf = event => {
	const size = Number(event?.payload?.fileSize)
	return Number.isFinite(size) ? size : null
}

// Sessions, newest first. A session is one sitting by one person, or several
// people whose sittings overlap ("edited together"). Each person keeps a lane
// with their own from–to and number of saves. sizeChange compares the board's
// size after the session with its size before it, when both are known.
export function sessionsOf(events) {
	const saves = (events || [])
		.filter(event => event && Number.isFinite(time(event)))
		.sort((a, b) => time(a) - time(b))

	// One person's saves, split where they paused for longer than the gap.
	const runs = []
	const open = new Map()
	for (const event of saves) {
		const who = event.actorUid || ''
		const run = open.get(who)
		if (run && time(event) - run.end <= SESSION_GAP_MS) {
			run.end = time(event)
			run.saves.push(event)
		} else {
			const next = { actorUid: who, displayName: event.actorDisplayName || who || t('Unknown user'), start: time(event), end: time(event), saves: [event] }
			runs.push(next)
			open.set(who, next)
		}
	}

	// Runs by different people that overlap in time become one session.
	runs.sort((a, b) => a.start - b.start)
	const sessions = []
	for (const run of runs) {
		const last = sessions[sessions.length - 1]
		if (last && run.start <= last.end) {
			last.runs.push(run)
			last.end = Math.max(last.end, run.end)
		} else {
			sessions.push({ start: run.start, end: run.end, runs: [run] })
		}
	}

	let before = null
	const result = sessions.map(session => {
		const all = session.runs.flatMap(run => run.saves).sort((a, b) => time(a) - time(b))
		const first = sizeOf(all[0])
		const after = sizeOf(all[all.length - 1])
		const base = before ?? first
		before = after ?? before
		const lanes = []
		for (const run of session.runs) {
			const lane = lanes.find(item => item.actorUid === run.actorUid)
			if (lane) {
				lane.end = Math.max(lane.end, run.end)
				lane.saves += run.saves.length
			} else {
				lanes.push({ actorUid: run.actorUid, displayName: run.displayName, start: run.start, end: run.end, saves: run.saves.length })
			}
		}
		return {
			id: String(all[0].id ?? session.start),
			start: session.start,
			end: session.end,
			saves: all.length,
			lanes,
			sizeChange: base !== null && after !== null ? after - base : null,
		}
	})
	return result.reverse()
}

function dayKey(ms) {
	const date = new Date(ms)
	return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate()
}

// Whole days between the day of `ms` and today: 0 is today, 1 yesterday.
export function daysAgo(ms, now = Date.now()) {
	const today = new Date(now)
	today.setHours(0, 0, 0, 0)
	const day = new Date(ms)
	day.setHours(0, 0, 0, 0)
	return Math.round((today - day) / 86400000)
}

export function dayLabel(ms, now = Date.now()) {
	const days = daysAgo(ms, now)
	if (days === 0) return t('Today')
	if (days === 1) return t('Yesterday')
	const day = new Date(ms)
	return day.toLocaleDateString(locale(), {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		...(day.getFullYear() !== new Date(now).getFullYear() ? { year: 'numeric' } : {}),
	})
}

// Sessions grouped by the day they ended on, newest first.
export function daysOf(sessions, now = Date.now()) {
	const days = []
	for (const session of sessions) {
		const key = dayKey(session.end)
		let day = days[days.length - 1]
		if (!day || day.key !== key) {
			day = { key, daysAgo: daysAgo(session.end, now), label: dayLabel(session.end, now), sessions: [], minutes: 0, people: [] }
			days.push(day)
		}
		day.sessions.push(session)
		day.minutes += minutesOf(session)
		for (const lane of session.lanes) {
			if (!day.people.some(person => person.actorUid === lane.actorUid)) day.people.push({ actorUid: lane.actorUid, displayName: lane.displayName })
		}
	}
	return days
}

export function minutesOf(span) {
	return Math.round((span.end - span.start) / 60000)
}

export function duration(minutes) {
	if (minutes < 1) return t('under a minute')
	if (minutes < 60) return t('{n} min', { n: minutes })
	const rest = minutes % 60
	const h = Math.floor(minutes / 60)
	return rest ? t('{h} h {m} min', { h, m: rest }) : t('{h} h', { h })
}

export function clock(ms) {
	return new Date(ms).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
}

export function range(span) {
	const from = clock(span.start)
	const to = clock(span.end)
	return from === to ? from : t('{start} – {end}', { start: from, end: to })
}

export function names(people) {
	const list = people.map(person => person.displayName)
	if (list.length <= 1) return list.join('')
	return t('{list} and {last}', { list: list.slice(0, -1).join(', '), last: list[list.length - 1] })
}

export function sizeChange(bytes) {
	if (bytes === null || bytes === undefined) return ''
	if (bytes === 0) return t('Board size unchanged')
	const abs = Math.abs(bytes)
	const amount = abs >= 1048576 ? formatNumber(abs / 1048576) + ' MB' : abs >= 1024 ? Math.round(abs / 1024) + ' KB' : t('{n} bytes', { n: abs })
	return bytes > 0 ? t('Board grew by {amount}', { amount }) : t('Board shrank by {amount}', { amount })
}
