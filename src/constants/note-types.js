/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export const DEFAULT_NOTE_TYPE = 'general'

export const NOTE_TYPES = [
	{ value: 'general', label: t('projectcreatoraio', 'General note') },
	{ value: 'customer', label: t('projectcreatoraio', 'Customer note') },
	{ value: 'internal', label: t('projectcreatoraio', 'Internal note') },
	{ value: 'decision', label: t('projectcreatoraio', 'Decision') },
	{ value: 'risk_blocker', label: t('projectcreatoraio', 'Risk / blocker') },
	{ value: 'action_point', label: t('projectcreatoraio', 'Action point') },
	{ value: 'technical', label: t('projectcreatoraio', 'Technical note') },
	{ value: 'audit', label: t('projectcreatoraio', 'Audit note') },
]

export function noteTypeLabel(value) {
	return NOTE_TYPES.find((type) => type.value === value)?.label || NOTE_TYPES[0].label
}
