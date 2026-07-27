import Vue from 'vue'
import App from './App.vue'
import { t, n } from '@nextcloud/l10n'

Vue.mixin({ methods: { t, n } })

/* eslint-disable-next-line no-new */
new Vue({
	el: '#content',
	render: h => h(App),
})
