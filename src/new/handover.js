// The handover dialog: who takes the project over after the process steps.
// Nobody leaves here; the server checks everything again before it changes.
import { draftProblem, isoDay } from './members.js'

const names = list => list.map(person => person.displayName).join(', ')
const dayOf = value => (typeof value === 'string' ? value.slice(0, 10) : '')

// "28 Oct 2026"
export function dayLabel(day) {
	const date = new Date(dayOf(day) + 'T12:00:00')
	return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

// The handover comes before the work on site starts, so it can't be later than that.
export function latestHandover(preview) {
	return dayOf(preview?.actualStartDate) || dayOf(preview?.actualEndDate)
}

export function handoverDraft(preview, today = new Date()) {
	const latest = latestHandover(preview)
	const day = isoDay(today)
	return {
		date: dayOf(preview?.handoverDate) || (latest && latest < day ? latest : day),
		ownerUid: preview?.ownerId || '',
		team: { mode: 'keep', teamId: preview?.team?.id ?? null },
		add: [],
		// Externals keep access until the end of work on site unless chosen otherwise.
		externals: Object.fromEntries((preview?.externals || []).map(external => [external.id, { mode: 'untilEnd', date: dayOf(external.expiresAt) }])),
	}
}

export function chosenTeam(preview, draft) {
	if (draft.team.mode === 'none') return null
	if (draft.team.mode === 'keep') return preview.team || null
	return (preview.teams || []).find(team => team.id === draft.team.teamId) || null
}

// The new team's people who are not on the project yet join with it.
export function joiningWithTeam(preview, draft) {
	if (draft.team.mode !== 'set') return []
	const onProject = new Set((preview.members || []).map(member => member.id))
	return (chosenTeam(preview, draft)?.members || []).filter(person => !onProject.has(person.id))
}

// Organisation members on the project, or joining in this handover, may own it.
export function ownerOptions(preview, draft) {
	const seen = new Set()
	const options = []
	const push = person => {
		if (!seen.has(person.id)) {
			seen.add(person.id)
			options.push({ id: person.id, displayName: person.displayName })
		}
	}
	;(preview.members || []).filter(member => !member.isExternal).forEach(push)
	joiningWithTeam(preview, draft).forEach(push)
	draft.add.forEach(push)
	return options
}

// The day an external's access ends, or null when it stays as it is.
export function externalEnd(preview, draft, externalId) {
	const choice = draft.externals[externalId] || { mode: 'untilEnd' }
	if (choice.mode === 'atHandover') return draft.date || null
	if (choice.mode === 'date') return choice.date || null
	return dayOf(preview.plannedEndDate) || null
}

export function externalEndText(preview, draft, external) {
	const end = externalEnd(preview, draft, external.id)
	if (end) return 'Access until ' + dayLabel(end)
	return external.expiresAt ? 'No end of work on site planned yet: stays until ' + dayLabel(external.expiresAt) : 'No end of work on site planned yet: stays as it is'
}

export function handoverProblem(preview, draft) {
	return whoProblem(draft, preview) || externalsProblem(preview, draft)
}

// The first step: the date, the team and the people added.
export function whoProblem(draft, preview = {}) {
	if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.date)) return 'Choose the handover date.'
	const latest = latestHandover(preview)
	if (latest && draft.date > latest) {
		const what = dayOf(preview.actualStartDate) ? 'actual start' : 'end date'
		return 'The handover can’t be after the ' + what + ' (' + dayLabel(latest) + ').'
	}
	if (draft.team.mode === 'set' && !draft.team.teamId) return 'Choose the team that takes over.'
	for (const person of draft.add) {
		const problem = draftProblem({ drascivs: person.drascivs, functional: person.functional })
		if (problem) return person.displayName + ': ' + problem.charAt(0).toLowerCase() + problem.slice(1)
	}
	return ''
}

export function externalsProblem(preview, draft) {
	for (const external of preview.externals || []) {
		const choice = draft.externals[external.id]
		if (choice?.mode === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(choice.date || '')) return 'Choose the day ' + external.displayName + '’s access ends.'
	}
	return ''
}

export function handoverPayload(preview, draft) {
	const teamChanged = draft.team.mode === 'none'
		? !!preview.team
		: draft.team.mode === 'set' && draft.team.teamId !== (preview.team?.id ?? null)
	return {
		handoverDate: draft.date,
		ownerUid: draft.ownerUid !== preview.ownerId ? draft.ownerUid : '',
		team: teamChanged ? { mode: draft.team.mode, ...(draft.team.mode === 'set' ? { teamId: draft.team.teamId } : {}) } : { mode: 'keep' },
		addMembers: draft.add.map(person => ({ uid: person.id, drascivsRoles: person.drascivs, functionalRoleKeys: person.functional })),
		externals: (preview.externals || []).map(external => {
			const choice = draft.externals[external.id] || { mode: 'untilEnd' }
			return choice.mode === 'date' ? { uid: external.id, mode: 'date', date: choice.date } : { uid: external.id, mode: choice.mode }
		}),
	}
}

// What the review step says will happen, one line each.
export function handoverSummary(preview, draft) {
	const lines = ['Handover on ' + dayLabel(draft.date)]
	const payload = handoverPayload(preview, draft)
	if (payload.team.mode === 'set') {
		const team = chosenTeam(preview, draft)
		const joining = joiningWithTeam(preview, draft)
		lines.push(team.name + ' takes over' + (joining.length ? ' · joining: ' + names(joining) : ''))
		if (preview.team) lines.push(preview.team.name + '’s people stay on the project')
	} else if (payload.team.mode === 'none') {
		lines.push('No team any more · ' + preview.team.name + '’s people stay on the project')
	} else if (preview.team) {
		lines.push(preview.team.name + ' stays the team')
	}
	if (draft.add.length) lines.push('Added: ' + names(draft.add))
	if (payload.ownerUid) {
		const owner = ownerOptions(preview, draft).find(person => person.id === payload.ownerUid)
		const previous = (preview.members || []).find(member => member.id === preview.ownerId)
		lines.push('Owner becomes ' + (owner?.displayName || payload.ownerUid) + (previous ? ' · ' + previous.displayName + ' stays as a member' : ''))
	}
	for (const external of preview.externals || []) {
		lines.push(external.displayName + ': ' + externalEndText(preview, draft, external).replace(/^Access until/, 'access until'))
	}
	lines.push('Nobody leaves the project')
	return lines
}

// The server's report of each step, in words; failures first.
export function stepReport(preview, draft, steps) {
	const label = step => {
		if (step === 'team') return 'Team'
		if (step === 'owner') return 'Owner'
		if (step === 'handoverDate') return 'Handover date'
		const [kind, id] = step.split(':')
		const person = [...(preview.members || []), ...(preview.externals || []), ...draft.add].find(p => p.id === id)
		return (kind === 'member' ? 'Adding ' : '') + (person?.displayName || id)
	}
	const rows = (steps || []).map(step => ({ label: label(step.step), failed: step.status === 'failed', message: step.message || '' }))
	return { failed: rows.filter(row => row.failed), notes: rows.filter(row => !row.failed && row.message) }
}
