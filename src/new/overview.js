export function progressSummary(summary) {
 const process = summary?.processCompleted
 const total = Number(process?.totalRequired || 0), done = Number(process?.doneCount || 0)
 const available = process && !['error', 'not_configured'].includes(process.status) && total > 0
 return { percent: available ? Math.round(Math.min(done, total) / total * 100) : null, done, total, missing: process?.missingTitles || [], status: process?.status }
}
export function planningSummary(summary) {
 const phases = summary?.phases || []
 const pending = phases.filter(p => p.tasks?.length && !p.tasks.every(t => t.isDone))
 const milestone = pending.map(p => p.milestone).filter(m => m?.date).sort((a, b) => a.date.localeCompare(b.date))[0] || null
 const conflict = summary?.desiredStartDate && Number.isFinite(summary.overallFloatDays) ? summary.overallFloatDays < 0 : null
 return { milestone, conflict, phases, currentPhase: pending[0]?.name || null }
}
// Open means what the board shows as work left: not archived, deleted or done.
// Oldest first: by when the card was created, then by id, which Deck hands out in order.
export function openTasks(stacks) {
 const age = card => Number(card.createdAt) || 0
 return stacks.filter(s => !s.deletedAt).flatMap(stack => (stack.cards || []).filter(card => !card.archived && !card.deletedAt && !card.done).map(card => ({ ...card, stackTitle: stack.title })))
  .sort((a, b) => age(a) - age(b) || Number(a.id) - Number(b.id))
}
export function assignedTasks(stacks, userId) {
 if (!userId) return []
 return openTasks(stacks).filter(card => (card.assignedUsers || []).some(a => Number(a.type) === 0 && (typeof a.participant === 'string' ? a.participant : a.participant?.uid) === userId))
}
export function recentFiles(tree) {
 const result = [], seen = new Set()
 const walk = (nodes, scope) => nodes.forEach(node => {
  if (node.type === 'folder') { if (!/^(public notes|private notes|notes)$/i.test(node.name)) walk(node.children || [], scope); return }
  if (node.type !== 'file' || /\.(whiteboard|excalidraw)$/i.test(node.name) || /^(public-note|private-note)\.md$/i.test(node.name) || seen.has(node.id)) return
  seen.add(node.id); result.push({ ...node, scope })
 })
 walk(tree.shared, 'Shared'); walk(tree.private, 'Private')
 return result.sort((a, b) => Number(b.mtime || 0) - Number(a.mtime || 0)).slice(0, 3)
}
export function activityText(event) {
 const payload = event.payload || {}
 if (payload.redacted) return 'Private note activity'
 const title = payload.fileName || payload.cardTitle || payload.noteTitle || payload.title || ''
 const action = String(event.eventType || 'Project activity').replace(/_/g, ' ')
 return title ? `${title} · ${action}` : action.charAt(0).toUpperCase() + action.slice(1)
}
export function dateLabel(value) {
 if (!value) return 'Not set'
 const date = new Date(typeof value === 'number' ? value * 1000 : /^\d{4}-\d{2}-\d{2}$/.test(value) ? value + 'T12:00:00' : value)
 return Number.isNaN(date.getTime()) ? 'Not set' : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}
export function fileSize(value) { return value >= 1048576 ? `${(value / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(value / 1024))} KB` }

// The overview's Timeline panel, in the Planning tab's three groups and words.
// Everything comes from the timeline summary; the status is the server's own.
const PLANNING_STATUS = { on_track: ['success', 'On track'], attention_needed: ['warning', 'Attention needed'], behind_at_risk: ['danger', 'Behind / at risk'] }
const count = (n, one, many = one + 's') => `${n} ${n === 1 ? one : many}`
const signedWeeks = weeks => `${weeks > 0 ? '+' : ''}${count(weeks, 'week')}`
// A YYYY-MM-DD date as a day number, so differences are whole days in any time zone.
const dayOf = value => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || '')); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000 : null }
export function timelineOf(summary, now = Date.now()) {
 const s = summary || {}
 const process = s.processCompleted || {}
 const total = Math.max(0, Number(process.totalRequired) || 0)
 const done = Math.min(Math.max(0, Number(process.doneCount) || 0), total)
 const configured = total > 0 && !['error', 'not_configured'].includes(process.status)
 const stepsDone = configured && process.status === 'complete'
 const desired = s.desiredStartDate || null
 const actual = s.actualStartDate || null
 const handover = s.actualHandoverDate || null
 const ended = s.actualEndDate || null
 const floatDays = Number.isFinite(s.overallFloatDays) ? s.overallFloatDays : null
 const floatWeeks = Number.isFinite(s.overallFloatWeeks) ? s.overallFloatWeeks : null
 const [tone, label] = PLANNING_STATUS[s.planningStatus] || PLANNING_STATUS.on_track
 let text = 'No desired start yet, so there is no float to check.'
 if (desired && floatDays !== null) {
  if (floatDays < 0) text = `Desired start is ${count(-floatDays, 'day')} before the earliest possible start · float ${signedWeeks(floatWeeks ?? Math.round(floatDays / 7 * 10) / 10)}.`
  else if (floatDays === 0) text = 'Desired start is the earliest possible start · no float.'
  else text = `Float of ${signedWeeks(floatWeeks ?? Math.round(floatDays / 7 * 10) / 10)} before the desired start.`
 }
 const pendingWeeks = Number.isFinite(s.coordinationPendingPeriod?.weeks) ? s.coordinationPendingPeriod.weeks : null
 const missing = Array.isArray(process.missingTitles) ? process.missingTitles.length : 0
 let stepsNote = ''
 if (!configured) stepsNote = 'No process steps configured'
 else if (stepsDone) stepsNote = process.date ? `Completed ${dateLabel(process.date)}` : 'All steps done'
 else if (missing) stepsNote = `${count(missing, 'required card')} missing from the board`
 else if (pendingWeeks !== null) stepsNote = `${count(pendingWeeks, 'week')} since the request`
 const weeks = Number.isFinite(s.executionWeeks) ? s.executionWeeks : null
 const planned = !ended && s.plannedEndDate ? s.plannedEndDate : null
 let endNote = 'Set the actual start in Planning to plan it'
 if (ended) endNote = 'Work on site finished'
 else if (planned) endNote = `${count(weeks ?? 0, 'week')} on site from the actual start`
 else if (actual) endNote = 'Set weeks on site in Planning to plan it'
 const warnings = Array.isArray(s.handoverWarnings) ? s.handoverWarnings : []
 const handoverNote = warnings.length ? warnings.join(' · ') : handover ? 'Handed to the execution team' : 'After the process steps'
 const preparation = Math.max(0, Number(s.requiredPreparationWeeks) || 0)
 return {
  status: { tone, label: desired ? label : label + ' (no target)', text },
  // Which group the project is in: 0 Initiation until the handover, 1 Execution
  // until the actual start, 2 Delivery on site, 3 ended.
  stage: ended ? 3 : actual ? 2 : handover ? 1 : 0,
  initiation: { requestDate: s.requestDate || null, done, total, configured, percent: configured ? Math.round(done / total * 100) : 0, note: stepsNote, warn: configured && !stepsDone && missing > 0, handover, handoverNote, handoverWarn: warnings.length > 0 },
  execution: { minimumStart: s.minimumStartDate || null, note: preparation ? `${count(preparation, 'week')} preparation after the process steps` : 'Right after the process steps', desired, actual },
  delivery: { end: ended || planned, planned: !!planned, note: endNote, weeks },
  overall: overallOf(s, ended, planned, dayOf(s.kpis?.today) ?? dayOf(new Date(now).toISOString())),
 }
}
const isoOf = day => new Date(day * 86400000).toISOString().slice(0, 10)
// How far along the whole timeline is: the share of the time from the request
// date to the end that has passed. Time, not work. The end is the recorded end,
// else the planned end (actual start + weeks on site); without either it is
// estimated from the desired (else minimum) start + weeks on site, or, with no
// weeks on site yet, the bar runs to that start. Estimates say so.
function overallOf(s, ended, planned, today) {
 const requestDate = s.requestDate || null
 const from = dayOf(requestDate)
 const none = text => ({ percent: null, estimate: false, text, note: '' })
 if (from === null) return none('of the timeline · no request date yet')
 let end = ended || planned
 let label = ended ? 'ended' : 'planned end'
 let note = ''
 const which = s.desiredStartDate ? 'desired' : 'minimum'
 const start = s.desiredStartDate || s.minimumStartDate || null
 if (!end && dayOf(start) !== null) {
  const weeks = Number.isFinite(s.executionWeeks) ? Math.max(0, s.executionWeeks) : null
  if (weeks !== null) {
   end = isoOf(dayOf(start) + 7 * weeks)
   label = 'estimated end'
   note = `Estimated from the ${which} start, ${dateLabel(start)}, + ${count(weeks, 'week')} on site.`
  } else {
   end = start
   label = `${which} start`
   note = `Measured to the ${which} start, as weeks on site aren't set yet.`
  }
  note += ' It becomes the planned end once the actual start and weeks on site are set.'
 }
 const to = dayOf(end)
 if (to === null) return none('of the timeline · no end date planned yet, so there is nothing to measure against')
 const percent = ended ? 100 : to <= from ? (today >= to ? 100 : 0) : Math.min(100, Math.max(0, Math.round((today - from) / (to - from) * 100)))
 return { percent, estimate: note !== '', text: `of the timeline · ${dateLabel(requestDate)} → ${label} ${dateLabel(end)}`, note }
}
