import Vue from 'vue'
import { t, n, tc } from './new/l10n.mjs'
import NewApp from './new/NewApp.vue'
import './new/new-ui.css'
import './new/tasks-theme.css'
import './new/notes-theme.css'
import './new/whiteboard-theme.css'
import './new/documents-theme.css'
import './new/planning-theme.css'

// t(), n() and tc() in every template: the same helper .js/.vue scripts import
// from ./new/l10n.mjs (no app id, escaping off because Vue escapes). Nextcloud
// registers l10n/<lang>.js before this bundle.
Vue.mixin({ methods: { t, n, tc } })
new Vue({ el: '#content', render: h => h(NewApp) })
