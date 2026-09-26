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
export function openTasks(stacks) {
 return stacks.filter(s => !s.deletedAt).flatMap(stack => (stack.cards || []).filter(card => !card.archived && !card.deletedAt && !card.done).map(card => ({ ...card, stackTitle: stack.title })))
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
