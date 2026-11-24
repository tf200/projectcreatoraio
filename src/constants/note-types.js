/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '../new/l10n.mjs'

export const DEFAULT_NOTE_TYPE = 'general'

// The value is the stored code; `label` is a getter so it is translated when a
// view reads it, after Nextcloud has registered the app's translations, never
// when this module loads.
function noteTypeName(value) {
	switch (value) {
	case 'customer': return t('Customer note')
	case 'internal': return t('Internal note')
	case 'decision': return t('Decision')
	case 'risk_blocker': return t('Risk / blocker')
	case 'action_point': return t('Action point')
	case 'technical': return t('Technical note')
	case 'audit': return t('Audit note')
	default: return t('General note')
	}
}

export const NOTE_TYPES = ['general', 'customer', 'internal', 'decision', 'risk_blocker', 'action_point', 'technical', 'audit']
	.map((value) => ({
		value,
		get label() {
			return noteTypeName(value)
		},
	}))

export function noteTypeLabel(value) {
	return noteTypeName(NOTE_TYPES.some((type) => type.value === value) ? value : DEFAULT_NOTE_TYPE)
}
