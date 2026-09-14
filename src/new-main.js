import Vue from 'vue'
import { t, n } from '@nextcloud/l10n'
import NewApp from './new/NewApp.vue'
import './new/new-ui.css'

Vue.mixin({ methods: { t, n } })
new Vue({ el: '#content', render: h => h(NewApp) })
