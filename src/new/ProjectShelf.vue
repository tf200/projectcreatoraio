<template>
 <section class="pc-project-navigation" :aria-label="t('projectcreatoraio', 'Project navigation')">
  <div class="pc-shelf-toolbar">
   <strong class="pc-shelf-label">{{ t('projectcreatoraio', 'Projects') }}</strong>
   <button class="pc-secondary pc-mobile-project" @click="openPicker">{{ selectedName || t('projectcreatoraio', 'Choose a project') }} <ChevronDown :size="18" /></button>
   <input class="pc-shelf-search" type="search" :value="filters.query" :aria-label="t('projectcreatoraio', 'Search projects')" :placeholder="t('projectcreatoraio', 'Search projects…')" @input="$emit('filter', { key: 'query', value: $event.target.value })">
   <button ref="expand" class="pc-secondary pc-shelf-expand" aria-haspopup="dialog" @click="openPicker"><ArrowExpand :size="18" />{{ t('projectcreatoraio', 'Expand') }}</button>
   <button class="pc-secondary pc-shelf-toggle" :aria-expanded="String(!collapsed)" @click="collapsed = !collapsed">{{ collapsed ? t('projectcreatoraio', 'Show shelf') : t('projectcreatoraio', 'Hide') }}<component :is="collapsed ? 'ChevronDown' : 'ChevronUp'" :size="18" /></button>
   <a class="pc-button pc-shelf-create" :href="base + '?create=1'">+ {{ t('projectcreatoraio', 'New project') }}</a>
  </div>
  <div v-if="loading" class="pc-shelf-message" role="status">{{ t('projectcreatoraio', 'Loading projects…') }}</div>
  <div v-else-if="error" class="pc-shelf-message" role="alert"><span>{{ error }}</span><button class="pc-secondary" @click="$emit('retry')">{{ t('projectcreatoraio', 'Try again') }}</button></div>
  <div v-else v-show="!collapsed" class="pc-shelf-content">
   <div v-if="visible.length" ref="cards" class="pc-shelf-cards">
    <a v-for="p in visible" :key="p.id" class="pc-shelf-card" :href="projectUrl(p.id)" :aria-current="Number(p.id) === selectedId ? 'page' : null" @click.prevent="select(p.id)">
     <span class="pc-shelf-monogram" aria-hidden="true">{{ initials(p.name) }}</span>
     <span class="pc-shelf-card-text"><strong>{{ p.name }}</strong><small>#{{ p.number }} · {{ p.loc_city || p.client_name || statusLabel(p.status) }}</small><span class="pc-shelf-status">{{ statusLabel(p.status) }}</span></span>
    </a>
   </div>
   <p v-else class="pc-shelf-message">{{ t('projectcreatoraio', 'No projects found. Adjust the filters or create a new project.') }}</p>
   <div class="pc-shelf-caption"><span>{{ t('projectcreatoraio', '{count} projects', { count: visible.length }) }}</span><button v-if="hasFilters" class="pc-link" @click="clearFilters">{{ t('projectcreatoraio', 'Clear filters') }}</button></div>
  </div>
  <dialog ref="picker" class="pc-project-picker" :aria-label="t('projectcreatoraio', 'Choose a project')" @cancel.prevent="closePicker" @keydown.esc.prevent.stop="closePicker" @click="onBackdropClick">
   <div class="pc-picker-top"><span>{{ t('projectcreatoraio', 'Choose a project to return to your workspace.') }}</span><button class="pc-secondary" @click="closePicker"><Close :size="18" />{{ t('projectcreatoraio', 'Close') }}</button></div>
   <ProjectList :projects="projects" :selected-id="selectedId" :loading="loading" :error="error" :filters="filters" :base="base" :is-global-admin="isGlobalAdmin" :is-organization-admin="isOrganizationAdmin" :my-project-ids="myProjectIds" :recent-project-ids="recentProjectIds" @filter="$emit('filter', $event)" @open="select" @retry="$emit('retry')" />
  </dialog>
 </section>
</template>
<script>
import ArrowExpand from 'vue-material-design-icons/ArrowExpand.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ProjectList from './ProjectList.vue'
import { filterProjects, interfaceUrl } from './navigation.js'
import { getProjectStatusLabel } from '../constants/project-statuses.js'

export default {
 name: 'ProjectShelf',
 components: { ArrowExpand, ChevronDown, ChevronUp, Close, ProjectList },
 props: {
  projects: { type: Array, required: true }, selectedId: { type: Number, default: null }, selectedName: { type: String, default: '' },
  loading: Boolean, error: { type: String, default: '' }, filters: { type: Object, required: true }, base: { type: String, required: true },
  isGlobalAdmin: Boolean, isOrganizationAdmin: Boolean, myProjectIds: { type: Array, default: () => [] }, recentProjectIds: { type: Array, default: () => [] },
 },
 data() { return { collapsed: false } },
 computed: {
  visible() { return filterProjects(this.projects, this.filters, this) },
  hasFilters() { return !!(this.filters.query || this.filters.client || this.filters.status !== 'all' || this.filters.organization !== 'all' || this.filters.scope !== 'all') },
 },
 watch: { selectedId() { this.revealSelection() } },
 mounted() { this.revealSelection() },
 beforeDestroy() { if (this.$refs.picker.open) this.$refs.picker.close() },
 methods: {
  statusLabel: getProjectStatusLabel,
  initials(name) { return String(name || '').trim().split(/\s+/).slice(0, 2).map(word => word[0] || '').join('').toLocaleUpperCase() },
  projectUrl(id) { return interfaceUrl(this.base, { projectId: id }) },
  openPicker() { this.$refs.picker.showModal(); this.$nextTick(() => this.$refs.picker.querySelector('input[type="search"]')?.focus()) },
  closePicker() { this.$refs.picker.close(); this.$refs.expand.focus() },
  onBackdropClick(event) {
   if (event.target !== this.$refs.picker) return
   const rect = this.$refs.picker.getBoundingClientRect()
   if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) this.closePicker()
  },
  select(id) { if (this.$refs.picker.open) this.closePicker(); this.$emit('open', Number(id)) },
  clearFilters() { for (const [key, value] of Object.entries({ query: '', client: '', status: 'all', organization: 'all', scope: 'all' })) this.$emit('filter', { key, value }) },
  revealSelection() {
   this.$nextTick(() => {
    const cards = this.$refs.cards
    const selected = cards?.querySelector('[aria-current="page"]')
    if (selected) cards.scrollLeft = selected.offsetLeft - cards.offsetLeft
   })
  },
 },
}
</script>
