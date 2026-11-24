// The handover dialog: who takes the project over after the process steps.
// Nobody leaves here; the server checks everything again before it changes.
import { draftProblem, isoDay } from './members.js'
import { t, locale } from './l10n.mjs'

const names = list => list.map(person => person.displayName).join(', ')
const dayOf = value => (typeof value === 'string' ? value.slice(0, 10) : '')

// "28 Oct 2026", in the user's locale
export function dayLabel(day) {
	const date = new Date(dayOf(day) + 'T12:00:00')
	return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(locale(), { day: 'numeric', month: 'short', year: 'numeric' })
}

// The handover comes before the work on site starts, so it can't be later than that.
export function latestHandover(preview) {
	return dayOf(preview?.actualStartDate) || dayOf(preview?.actualEndDate)
}

// Nothing on the timeline comes before the project was requested.
export function earliestHandover(preview) {
	return dayOf(preview?.requestDate)
}

export function handoverDraft(preview, today = new Date()) {
	const earliest = earliestHandover(preview)
	const latest = latestHandover(preview)
	let day = isoDay(today)
	if (latest && latest < day) day = latest
	if (earliest && earliest > day) day = earliest
	return {
		date: dayOf(preview?.handoverDate) || day,
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
	if (end) return t('Access until {date}', { date: dayLabel(end) })
	return external.expiresAt ? t('No end of work on site planned yet: stays until {date}', { date: dayLabel(external.expiresAt) }) : t('No end of work on site planned yet: stays as it is')
}

export function handoverProblem(preview, draft) {
	return whoProblem(draft, preview) || externalsProblem(preview, draft)
}

// The first step: the date, the team and the people added.
export function whoProblem(draft, preview = {}) {
	if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.date)) return t('Choose the handover date.')
	const earliest = earliestHandover(preview)
	if (earliest && draft.date < earliest) {
		return t('The handover can’t be before the request date ({date}).', { date: dayLabel(earliest) })
	}
	const latest = latestHandover(preview)
	if (latest && draft.date > latest) {
		return dayOf(preview.actualStartDate)
			? t('The handover can’t be after the actual start ({date}).', { date: dayLabel(latest) })
			: t('The handover can’t be after the end date ({date}).', { date: dayLabel(latest) })
	}
	if (draft.team.mode === 'set' && !draft.team.teamId) return t('Choose the team that takes over.')
	for (const person of draft.add) {
		const problem = draftProblem({ drascivs: person.drascivs, functional: person.functional })
		if (problem) return t('{name}: {problem}', { name: person.displayName, problem: problem.charAt(0).toLowerCase() + problem.slice(1) })
	}
	return ''
}

/**
 * How the chosen day compares with the plan: allowed, but worth knowing.
 * The Planning tab's Handover card says the same (TimelinePlanningService::dateWarnings).
 * @param {object} preview the handover preview
 * @param {string} date the chosen handover day
 */
export function handoverWarnings(preview, date) {
	const day = dayOf(date)
	if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return []
	const warnings = []
	const process = preview?.processCompleted || {}
	const processDate = dayOf(process.date)
	if (['incomplete', 'missing_cards'].includes(process.status) || (processDate && day < processDate)) {
		warnings.push(t('Handover before the process steps are done'))
	}
	if (!dayOf(preview?.actualStartDate)) {
		const minimum = dayOf(preview?.minimumStartDate)
		const desired = dayOf(preview?.desiredStartDate)
		if (minimum && day > minimum) warnings.push(t('Handover after the minimum start ({date}), so preparation is squeezed', { date: dayLabel(minimum) }))
		if (desired && day > desired) warnings.push(t('Handover after the desired start ({date})', { date: dayLabel(desired) }))
	}
	return warnings
}

export function externalsProblem(preview, draft) {
	for (const external of preview.externals || []) {
		const choice = draft.externals[external.id]
		if (choice?.mode === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(choice.date || '')) return t('Choose the day {name}’s access ends.', { name: external.displayName })
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
	const lines = [t('Handover on {date}', { date: dayLabel(draft.date) })]
	const payload = handoverPayload(preview, draft)
	if (payload.team.mode === 'set') {
		const team = chosenTeam(preview, draft)
		const joining = joiningWithTeam(preview, draft)
		lines.push(joining.length ? t('{team} takes over · joining: {names}', { team: team.name, names: names(joining) }) : t('{team} takes over', { team: team.name }))
		if (preview.team) lines.push(t('{team}’s people stay on the project', { team: preview.team.name }))
	} else if (payload.team.mode === 'none') {
		lines.push(t('No team any more · {team}’s people stay on the project', { team: preview.team.name }))
	} else if (preview.team) {
		lines.push(t('{team} stays the team', { team: preview.team.name }))
	}
	if (draft.add.length) lines.push(t('Added: {names}', { names: names(draft.add) }))
	if (payload.ownerUid) {
		const owner = ownerOptions(preview, draft).find(person => person.id === payload.ownerUid)
		const previous = (preview.members || []).find(member => member.id === preview.ownerId)
		const name = owner?.displayName || payload.ownerUid
		lines.push(previous ? t('Owner becomes {name} · {previous} stays as a member', { name, previous: previous.displayName }) : t('Owner becomes {name}', { name }))
	}
	for (const external of preview.externals || []) {
		const end = externalEnd(preview, draft, external.id)
		lines.push(end
			? t('{name}: access until {date}', { name: external.displayName, date: dayLabel(end) })
			: t('{name}: {problem}', { name: external.displayName, problem: externalEndText(preview, draft, external) }))
	}
	lines.push(t('Nobody leaves the project'))
	return lines
}

// The server's report of each step, in words; failures first.
export function stepReport(preview, draft, steps) {
	const label = step => {
		if (step === 'team') return t('Team')
		if (step === 'owner') return t('Owner')
		if (step === 'handoverDate') return t('Handover date')
		const [kind, id] = step.split(':')
		const person = [...(preview.members || []), ...(preview.externals || []), ...draft.add].find(p => p.id === id)
		return kind === 'member' ? t('Adding {name}', { name: person?.displayName || id }) : (person?.displayName || id)
	}
	const rows = (steps || []).map(step => ({ label: label(step.step), failed: step.status === 'failed', message: step.message || '' }))
	return { failed: rows.filter(row => row.failed), notes: rows.filter(row => !row.failed && row.message) }
}
