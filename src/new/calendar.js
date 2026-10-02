// The Calendar tab's items, as the Calendar app returns them for a project:
// date proposals ("MeetingProposal") and meetings ("Meeting"). These helpers
// group and word them for the new layout; times show in the viewer's zone.

export const isProposal = event => event?.['@type'] === 'MeetingProposal'
export const isMeeting = event => event?.['@type'] === 'Meeting'
export const keyOf = event => String(event?.['@type']) + '-' + String(event?.id)

const time = value => (value ? new Date(value).getTime() : NaN)

// Proposals first (they need an answer), then meetings still to come, soonest
// first, then past meetings, latest first. Empty groups are left out.
export function groupsOf(events, filter = 'all', now = Date.now()) {
	const list = (events || []).filter(Boolean)
	const proposals = filter === 'meetings' ? [] : list.filter(isProposal)
	const meetings = filter === 'proposals' ? [] : list.filter(isMeeting)
	const endOf = meeting => (Number.isNaN(time(meeting.endDate)) ? time(meeting.startDate) : time(meeting.endDate))
	const upcoming = meetings.filter(m => endOf(m) >= now).sort((a, b) => time(a.startDate) - time(b.startDate))
	const past = meetings.filter(m => endOf(m) < now).sort((a, b) => time(b.startDate) - time(a.startDate))
	return [
		{ key: 'proposals', label: 'Needs a date', items: proposals },
		{ key: 'upcoming', label: 'Upcoming', items: upcoming },
		{ key: 'past', label: 'Past', items: past },
	].filter(group => group.items.length)
}

const sameYear = (date, now) => date.getFullYear() === new Date(now).getFullYear()

export function clock(value) {
	return new Date(value).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}

// "Mon 24 Aug · 11:00", with the year when it is not this year.
export function dayTime(value, now = Date.now()) {
	const date = new Date(value)
	const day = date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', ...(sameYear(date, now) ? {} : { year: 'numeric' }) })
	return day.replace(',', '') + ' · ' + clock(value)
}

// "Wednesday 7 October".
export function longDay(value, now = Date.now()) {
	const date = new Date(value)
	return date.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', ...(sameYear(date, now) ? {} : { year: 'numeric' }) }).replace(',', '')
}

export function timeRange(meeting) {
	if (Number.isNaN(time(meeting.startDate))) return ''
	return Number.isNaN(time(meeting.endDate)) ? clock(meeting.startDate) : clock(meeting.startDate) + ' – ' + clock(meeting.endDate)
}

// The date block in front of a meeting.
export function dateBlock(value) {
	const date = new Date(value)
	return {
		month: date.toLocaleDateString('en-GB', { month: 'short' }).slice(0, 3),
		day: String(date.getDate()),
		weekday: date.toLocaleDateString('en-GB', { weekday: 'short' }),
	}
}

export function optionsOf(proposal, now = Date.now()) {
	return (proposal.dates || [])
		.filter(option => !Number.isNaN(time(option?.date)))
		.map(option => ({ id: option.id, label: dayTime(option.date, now), passed: time(option.date) < now }))
}

// Every date offered is behind us: the proposal waits for nothing any more.
export function allPassed(proposal, now = Date.now()) {
	const options = optionsOf(proposal, now)
	return options.length > 0 && options.every(option => option.passed)
}

export function answeredOf(proposal) {
	const people = proposal.participants || []
	return { answered: people.filter(p => p?.status === 'responded').length, total: people.length }
}

// Statuses in plain words, with the theme tone they read in.
const STATUS = {
	'needs-action': ['Waiting', 'muted'],
	responded: ['Answered', 'success'],
	accepted: ['Accepted', 'success'],
	tentative: ['Maybe', 'warning'],
	declined: ['Declined', 'danger'],
	delegated: ['Delegated', 'muted'],
}

export function statusOf(status) {
	const [label, tone] = STATUS[String(status || 'needs-action').toLowerCase()] || [String(status), 'muted']
	return { label, tone }
}

// A participant's name, and their address only when it says something more.
export function peopleOf(event) {
	return (event.participants || []).map((p, index) => {
		const name = String(p?.name || p?.address || 'Unknown')
		const address = p?.address && p.address !== name ? p.address : ''
		return { key: index + ':' + (p?.address || name), name, address, ...statusOf(p?.status) }
	})
}

// "2 accepted · 1 maybe · 1 declined".
export function attendeeSummary(meeting) {
	const counts = {}
	for (const person of meeting.participants || []) {
		const { label } = statusOf(person?.status)
		counts[label] = (counts[label] || 0) + 1
	}
	return ['Accepted', 'Maybe', 'Declined', 'Waiting', 'Delegated']
		.filter(label => counts[label])
		.map(label => counts[label] + ' ' + label.toLowerCase())
		.join(' · ')
}

export function metaOf(event) {
	const parts = isProposal(event)
		? [(event.dates || []).length === 1 ? '1 date option' : (event.dates || []).length + ' date options', event.duration ? event.duration + ' min' : '', event.location]
		: [timeRange(event), event.location]
	return parts.filter(Boolean).join(' · ')
}
