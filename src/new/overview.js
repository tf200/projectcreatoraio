import { isCriticalLabel } from './tasks-progress.js'
import { t, n, locale, formatNumber } from './l10n.mjs'
export function progressSummary(summary) {
 const process = summary?.processCompleted
 const total = Number(process?.totalRequired || 0), done = Number(process?.doneCount || 0)
 const available = process && !['error', 'not_configured'].includes(process.status) && total > 0
 return { percent: available ? Math.round(Math.min(done, total) / total * 100) : null, done, total, missing: process?.missingTitles || [], status: process?.status }
}
export function planningSummary(summary) {
 const phases = summary?.phases || []
 const pending = phases.filter(p => p.tasks?.length && !p.tasks.every(task => task.isDone))
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
 walk(tree.shared, t('Shared')); walk(tree.private, t('Private'))
 return result.sort((a, b) => Number(b.mtime || 0) - Number(a.mtime || 0)).slice(0, 3)
}
// What happened, by the server's event code; a code this list doesn't know is
// shown as the code itself (underscores as spaces).
const EVENT_LABELS = {
 project_created: () => t('project created'), project_updated: () => t('project updated'), project_archived: () => t('project archived'),
 project_restored: () => t('project restored'), project_deleted: () => t('project deleted'), project_handed_over: () => t('project handed over'),
 member_added: () => t('member added'), member_removed: () => t('member removed'), owner_changed: () => t('owner changed'),
 note_created: () => t('note created'), note_updated: () => t('note updated'), note_deleted: () => t('note deleted'), project_notes_updated: () => t('project notes updated'),
 timeline_item_created: () => t('timeline item created'), timeline_item_updated: () => t('timeline item updated'), timeline_item_deleted: () => t('timeline item deleted'), timeline_reordered: () => t('timeline reordered'),
 deck_card_created: () => t('deck card created'), deck_card_updated: () => t('deck card updated'), deck_card_deleted: () => t('deck card deleted'),
 deck_acl_added: () => t('deck acl added'), deck_acl_removed: () => t('deck acl removed'), deck_acl_updated: () => t('deck acl updated'),
 deck_board_created: () => t('deck board created'), deck_board_updated: () => t('deck board updated'), deck_board_deleted: () => t('deck board deleted'),
 file_created: () => t('file created'), file_updated: () => t('file updated'), file_deleted: () => t('file deleted'), file_renamed: () => t('file renamed'), file_moved: () => t('file moved'), file_copied: () => t('file copied'),
 folder_created: () => t('folder created'), folder_deleted: () => t('folder deleted'),
 talk_message_sent: () => t('talk message sent'), talk_direct_message_sent: () => t('talk direct message sent'), talk_direct_chat_created: () => t('talk direct chat created'),
 talk_participant_added: () => t('talk participant added'), talk_participant_removed: () => t('talk participant removed'), talk_call_started: () => t('talk call started'), talk_call_ended: () => t('talk call ended'),
 talk_room_updated: () => t('talk room updated'), talk_reaction_added: () => t('talk reaction added'), talk_reaction_removed: () => t('talk reaction removed'), talk_user_joined: () => t('talk user joined'),
 whiteboard_updated: () => t('whiteboard updated'),
}
export function activityText(event) {
 const payload = event.payload || {}
 if (payload.redacted) return t('Private note activity')
 const title = payload.fileName || payload.cardTitle || payload.noteTitle || payload.title || ''
 const label = EVENT_LABELS[event.eventType]
 const action = label ? label() : event.eventType ? String(event.eventType).replace(/_/g, ' ') : t('Project activity')
 return title ? `${title} · ${action}` : action.charAt(0).toUpperCase() + action.slice(1)
}
// Where an activity came from, by the server's source code.
const SOURCE_LABELS = { internal: () => t('Project'), deck: () => t('Deck'), files: () => t('Files'), talk: () => t('Talk'), whiteboard: () => t('Whiteboard') }
export function sourceLabel(source) {
 if (!source) return t('Project')
 return SOURCE_LABELS[source] ? SOURCE_LABELS[source]() : String(source)
}
export function dateLabel(value) {
 if (!value) return t('Not set')
 const date = new Date(typeof value === 'number' ? value * 1000 : /^\d{4}-\d{2}-\d{2}$/.test(value) ? value + 'T12:00:00' : value)
 return Number.isNaN(date.getTime()) ? t('Not set') : date.toLocaleDateString(locale(), { day: 'numeric', month: 'short', year: 'numeric' })
}
const oneDecimal = value => { try { return new Intl.NumberFormat(locale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value) } catch { return value.toFixed(1) } }
export function fileSize(value) { return value >= 1048576 ? t('{size} MB', { size: oneDecimal(value / 1048576) }) : t('{size} KB', { size: Math.max(1, Math.round(value / 1024)) }) }

// The overview's Timeline panel, in the Planning tab's three groups and words.
// Everything comes from the timeline summary; the status is the server's own.
const PLANNING_STATUS = { on_track: ['success', () => t('On track')], attention_needed: ['warning', () => t('Attention needed')], behind_at_risk: ['danger', () => t('Behind / at risk')] }
const days = count => n('%n day', '%n days', count)
const weeksText = count => n('%n week', '%n weeks', count)
const signedWeeks = weeks => `${weeks > 0 ? '+' : ''}${weeksText(weeks)}`
// A YYYY-MM-DD date as a day number, so differences are whole days in any time zone.
const dayOf = value => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || '')); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000 : null }
// The process steps' pill and count badge, as the Planning tab shows them.
function processPill(status, done) {
 const [tone, label] = { complete: ['success', () => t('READY')], incomplete: ['warning', () => t('IN PROGRESS')], missing_cards: ['muted', () => t('INITIAL')], not_configured: ['muted', () => t('N/A')] }[status] || ['muted', () => t('PENDING')]
 return { tone, label: label(), badge: status === 'complete' ? 'success' : done > 0 ? 'progress' : 'muted' }
}
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
 const [tone, statusLabel] = PLANNING_STATUS[s.planningStatus] || PLANNING_STATUS.on_track
 const label = statusLabel()
 let text = t('No desired start yet, so there is no float to check.')
 if (desired && floatDays !== null) {
  if (floatDays < 0) text = t('Desired start is {days} before the earliest possible start · float {weeks}.', { days: days(-floatDays), weeks: signedWeeks(floatWeeks ?? Math.round(floatDays / 7 * 10) / 10) })
  else if (floatDays === 0) text = t('Desired start is the earliest possible start · no float.')
  else text = t('Float of {weeks} before the desired start.', { weeks: signedWeeks(floatWeeks ?? Math.round(floatDays / 7 * 10) / 10) })
 }
 const pendingWeeks = Number.isFinite(s.coordinationPendingPeriod?.weeks) ? s.coordinationPendingPeriod.weeks : null
 const missing = Array.isArray(process.missingTitles) ? process.missingTitles.length : 0
 let stepsNote = ''
 if (!configured) stepsNote = t('No process steps configured')
 else if (stepsDone) stepsNote = process.date ? t('Completed {date}', { date: dateLabel(process.date) }) : t('All steps done')
 else if (missing) stepsNote = n('%n required card missing from the board', '%n required cards missing from the board', missing)
 else if (pendingWeeks !== null) stepsNote = t('{weeks} since the request', { weeks: weeksText(pendingWeeks) })
 const weeks = Number.isFinite(s.executionWeeks) ? s.executionWeeks : null
 const planned = !ended && s.plannedEndDate ? s.plannedEndDate : null
 let endNote = t('Set the actual start in Planning to plan it')
 if (ended) endNote = t('Work on site finished')
 else if (planned) endNote = t('{weeks} on site from the actual start', { weeks: weeksText(weeks ?? 0) })
 else if (actual) endNote = t('Set weeks on site in Planning to plan it')
 const warnings = Array.isArray(s.handoverWarnings) ? s.handoverWarnings : []
 const handoverNote = warnings.length ? warnings.join(' · ') : handover ? t('Handed to the execution team') : t('After the process steps')
 const preparation = Math.max(0, Number(s.requiredPreparationWeeks) || 0)
 const minimumWeeks = Number(s.minimumDurationWeeks ?? s.kpis?.minimumDurationWeeks) || 0
 // The server's warnings when a start misses the plan, as on the Planning tab.
 const listOf = value => (Array.isArray(value) ? value : [])
 const desiredWarnings = listOf(s.dateWarnings?.desiredStart)
 const actualWarnings = listOf(s.dateWarnings?.actualStart)
 return {
  status: { tone, label: desired ? label : t('{status} (no target)', { status: label }), text },
  // Which group the project is in: 0 Initiation until the handover, 1 Execution
  // until the actual start, 2 Delivery on site, 3 ended.
  stage: ended ? 3 : actual ? 2 : handover ? 1 : 0,
  initiation: { requestDate: s.requestDate || null, done, total, configured, stepsDone, steps: processPill(process.status, done), note: stepsNote, warn: configured && !stepsDone && missing > 0, completedOn: process.date || null, handover, handoverNote, handoverWarn: warnings.length > 0 },
  execution: { preparation, preparationText: weeksText(preparation), duration: t('Min. Duration: {min}w (Initiation: {init}w + Prep: {prep}w)', { min: formatNumber(minimumWeeks), init: formatNumber(Number(s.kpis?.deckTasksWeeks) || 0), prep: formatNumber(preparation) }), minimumStart: s.minimumStartDate || null, desired, actual, float: desired && floatWeeks !== null ? { tone: floatWeeks < 0 ? 'danger' : 'success', text: (floatWeeks > 0 ? '+' : '') + t('{n}w', { n: formatNumber(floatWeeks) }) } : null, desiredNote: desiredWarnings.join(' · '), actualNote: actualWarnings.join(' · ') },
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
 if (from === null) return none(t('of the timeline · no request date yet'))
 let end = ended || planned
 let label = ended ? t('ended') : t('planned end')
 let note = ''
 const which = s.desiredStartDate ? 'desired' : 'minimum'
 const start = s.desiredStartDate || s.minimumStartDate || null
 if (!end && dayOf(start) !== null) {
  const weeks = Number.isFinite(s.executionWeeks) ? Math.max(0, s.executionWeeks) : null
  if (weeks !== null) {
   end = isoOf(dayOf(start) + 7 * weeks)
   label = t('estimated end')
   const vars = { date: dateLabel(start), weeks: weeksText(weeks) }
   note = which === 'desired' ? t('Estimated from the desired start, {date}, + {weeks} on site.', vars) : t('Estimated from the minimum start, {date}, + {weeks} on site.', vars)
  } else {
   end = start
   label = which === 'desired' ? t('desired start') : t('minimum start')
   note = which === 'desired' ? t("Measured to the desired start, as weeks on site aren't set yet.") : t("Measured to the minimum start, as weeks on site aren't set yet.")
  }
  note += ' ' + t('It becomes the planned end once the actual start and weeks on site are set.')
 }
 const to = dayOf(end)
 if (to === null) return none(t('of the timeline · no end date planned yet, so there is nothing to measure against'))
 const percent = ended ? 100 : to <= from ? (today >= to ? 100 : 0) : Math.min(100, Math.max(0, Math.round((today - from) / (to - from) * 100)))
 return { percent, estimate: note !== '', text: t('of the timeline · {from} → {label} {end}', { from: dateLabel(requestDate), label, end: dateLabel(end) }), note }
}

// The header's next milestone: the open task whose due date comes up first.
// Anything overdue comes first (the most overdue), then anything due today,
// then the nearest date. On one date: critical process steps, then the task
// furthest along the board, then the title. Null when no open task has a date.
const localDay = value => { const d = new Date(value); return Number.isNaN(d.getTime()) ? null : Math.round(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) / 86400000) }
export function nextMilestoneOf(stacks, now = Date.now()) {
 const today = localDay(now)
 const columns = (Array.isArray(stacks) ? stacks : []).filter(stack => !stack.deletedAt).sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0))
 const tasks = columns.flatMap((stack, stage) => (stack.cards || [])
  .filter(card => !card.archived && !card.deletedAt && !card.done && card.duedate)
  .map(card => ({ title: String(card.title || ''), due: card.duedate, day: localDay(card.duedate), stage, critical: (card.labels || []).some(isCriticalLabel) })))
  .filter(task => task.day !== null)
 if (!tasks.length) return null
 const order = (a, b) => a.day - b.day || Number(b.critical) - Number(a.critical) || b.stage - a.stage || a.title.localeCompare(b.title)
 const overdue = tasks.filter(task => task.day < today).sort(order)
 const first = Math.min(...tasks.map(task => task.day))
 const group = overdue.length ? overdue : tasks.filter(task => task.day === first).sort(order)
 const lead = group[0]
 const state = overdue.length ? 'overdue' : lead.day === today ? 'today' : 'upcoming'
 const late = today - lead.day
 const when = state === 'overdue' ? n('%n day late', '%n days late', late) : state === 'today' ? t('today') : dateLabel(lead.due)
 return { title: lead.title, when, state, more: group.length - 1, tasks: group.map(task => task.title) }
}
