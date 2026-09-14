<template>
 <article class="pc-overview-panel" :class="'pc-panel-' + name" :aria-busy="state && state.loading ? 'true' : 'false'">
  <header><h2><component :is="icons[icon]" :size="19" />{{ title }}</h2><button v-if="action" class="pc-panel-action" @click="$emit('open')">{{ action }}</button></header>
  <div class="pc-panel-body">
   <div v-if="state && state.loading" class="pc-panel-empty" role="status">Loading…</div>
   <div v-else-if="state && state.error" class="pc-panel-empty" role="alert"><component :is="icons.info" :size="25" /><p>{{ state.error }}</p><button class="pc-link" @click="$emit('retry')">Try again</button></div>
   <slot v-else />
  </div>
  <footer v-if="link"><button class="pc-panel-action" @click="$emit('open')">{{ link }} <span aria-hidden="true">→</span></button></footer>
 </article>
</template>
<script>
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ChartDonut from 'vue-material-design-icons/ChartDonut.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
export default {
 props: { title: String, name: String, icon: String, action: String, link: String, state: Object },
 data: () => ({ icons: { flag: FlagOutline, eye: EyeOutline, history: History, tasks: ClipboardCheckOutline, progress: ChartDonut, calendar: CalendarMonthOutline, files: FolderOutline, info: InformationOutline } }),
}
</script>
