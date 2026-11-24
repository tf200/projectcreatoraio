<template>
 <section>
  <header class="pc-list-heading"><div><h2>{{ t('Projects') }}</h2></div><a v-if="canCreate" class="pc-button" :href="base + '/create'" @click.prevent="$emit('create')">+ {{ t('New project') }}</a></header>
  <div class="pc-list-surface">
   <div class="pc-filters">
    <label v-if="isOrganizationAdmin">{{ tc('View', 'project list scope') }}<select :value="filters.scope" @change="filter('scope', $event.target.value)"><option value="all">{{ t('All organization projects') }}</option><option value="my">{{ t('My projects') }}</option></select></label>
    <label class="pc-search">{{ t('Search') }}<input type="search" :value="filters.query" :placeholder="t('Project, number, client or location')" @input="filter('query', $event.target.value)"></label>
    <label>{{ t('Status') }}<select :value="filters.status" @change="filter('status', $event.target.value)"><option value="all">{{ t('All statuses') }}</option><option v-for="s in statuses" :key="s.value" :value="String(s.value)">{{ s.label }}</option></select></label>
    <label>{{ t('Client') }}<select :value="filters.client" @change="filter('client', $event.target.value)"><option value="">{{ t('All clients') }}</option><option v-for="client in clients" :key="client" :value="client">{{ client }}</option></select></label>
    <label v-if="isGlobalAdmin">{{ t('Organization') }}<select :value="filters.organization" @change="filter('organization', $event.target.value)"><option value="all">{{ t('All organizations') }}</option><option v-for="id in organizations" :key="id" :value="String(id)">{{ t('Organization {id}', { id }) }}</option></select></label>
    <label>{{ t('Sort by') }}<select :value="filters.sort" @change="filter('sort', $event.target.value)"><option value="recent">{{ t('Recently viewed') }}</option><option value="name">{{ t('Name') }}</option><option value="number">{{ t('Project number') }}</option></select></label>
   </div>
   <div v-if="loading" class="pc-state" role="status">{{ t('Loading projects…') }}</div>
   <div v-else-if="error" class="pc-state" role="alert"><p>{{ error }}</p><button class="pc-button" @click="$emit('retry')">{{ t('Try again') }}</button></div>
   <template v-else>
    <div v-if="!visible.length" class="pc-state"><h2>{{ t('No projects found') }}</h2><p>{{ t('Adjust the filters or create a new project.') }}</p></div>
    <div v-else class="pc-table-scroll"><table class="pc-project-table"><thead><tr><th>{{ t('Project') }}</th><th>{{ t('Client') }}</th><th>{{ t('Location') }}</th><th>{{ t('Type') }}</th><th>{{ t('Status') }}</th><th><span class="hidden-visually">{{ t('Open') }}</span></th></tr></thead><tbody><tr v-for="p in visible" :key="p.id" :class="{ 'pc-selected-project': Number(p.id) === selectedId }"><td><a :href="projectUrl(p.id)" :aria-current="Number(p.id) === selectedId ? 'page' : null" @click.prevent="$emit('open', Number(p.id))">{{ p.name }}</a><small>#{{ p.number }}<span v-if="Number(p.id) === selectedId"> · {{ t('Current project') }}</span></small></td><td>{{ p.client_name || '—' }}</td><td>{{ p.loc_city || '—' }}</td><td>{{ typeLabel(p.type) }}</td><td><span class="pc-badge" :class="{ 'pc-positive': Number(p.status) === 1 }">{{ statusLabel(p.status) }}</span></td><td><a :href="projectUrl(p.id)" :aria-label="t('Open {name}', { name: p.name })" @click.prevent="$emit('open', Number(p.id))">→</a></td></tr></tbody></table></div>
    <footer class="pc-list-footer" aria-live="polite">{{ n('%n project', '%n projects', visible.length) }}</footer>
   </template>
  </div>
 </section>
</template>
<script>
import { PROJECT_STATUS_OPTIONS, getProjectStatusLabel } from '../constants/project-statuses.js'
import { PROJECT_TYPES } from '../macros/project-types.js'
import { filterProjects, interfaceUrl } from './navigation.js'
export default {
 props: { projects: { type: Array, required: true }, selectedId: { type: Number, default: null }, loading: Boolean, error: { type: String, default: '' }, filters: { type: Object, required: true }, base: { type: String, required: true }, isGlobalAdmin: Boolean, isOrganizationAdmin: Boolean, myProjectIds: { type: Array, default: () => [] }, recentProjectIds: { type: Array, default: () => [] }, canCreate: { type: Boolean, default: true } },
 computed: {
  statuses() { return PROJECT_STATUS_OPTIONS },
  organizations() { return [...new Set(this.projects.map(p => p.organization_id).filter(Boolean))] },
  clients() { return [...new Set(this.projects.map(p => p.client_name).filter(Boolean))].sort((a, b) => a.localeCompare(b)) },
  visible() { return filterProjects(this.projects, this.filters, this) },
 },
 methods: { typeLabel(value) { return value == null ? '—' : PROJECT_TYPES.find(type => type.id === Number(value))?.label.trim() || '—' }, filter(key, value) { this.$emit('filter', { key, value }) }, statusLabel: getProjectStatusLabel, projectUrl(id) { return interfaceUrl(this.base, { projectId: id }) } },
}
</script>
