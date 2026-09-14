<template>
 <section>
  <header class="pc-list-heading"><div><h1>{{ t('projectcreatoraio', 'Projects') }}</h1><p>{{ t('projectcreatoraio', 'All projects and their next steps.') }}</p></div><a class="pc-button" :href="base + '?create=1'">+ {{ t('projectcreatoraio', 'New project') }}</a></header>
  <p class="pc-subtle">{{ t('projectcreatoraio', 'Project creation opens in the current interface for now.') }}</p>
  <div class="pc-list-surface">
   <div class="pc-filters">
    <label v-if="isOrganizationAdmin">{{ t('projectcreatoraio', 'View') }}<select :value="filters.scope" @change="filter('scope', $event.target.value)"><option value="all">{{ t('projectcreatoraio', 'All organization projects') }}</option><option value="my">{{ t('projectcreatoraio', 'My projects') }}</option></select></label>
    <label class="pc-search">{{ t('projectcreatoraio', 'Search') }}<input type="search" :value="filters.query" :placeholder="t('projectcreatoraio', 'Project, number, client or location')" @input="filter('query', $event.target.value)"></label>
    <label>{{ t('projectcreatoraio', 'Status') }}<select :value="filters.status" @change="filter('status', $event.target.value)"><option value="all">{{ t('projectcreatoraio', 'All statuses') }}</option><option v-for="s in statuses" :key="s.value" :value="String(s.value)">{{ s.label }}</option></select></label>
    <label v-if="isGlobalAdmin">{{ t('projectcreatoraio', 'Organization') }}<select :value="filters.organization" @change="filter('organization', $event.target.value)"><option value="all">{{ t('projectcreatoraio', 'All organizations') }}</option><option v-for="id in organizations" :key="id" :value="String(id)">{{ t('projectcreatoraio', 'Organization {id}', { id }) }}</option></select></label>
    <label>{{ t('projectcreatoraio', 'Sort by') }}<select :value="filters.sort" @change="filter('sort', $event.target.value)"><option value="name">{{ t('projectcreatoraio', 'Name') }}</option><option value="number">{{ t('projectcreatoraio', 'Project number') }}</option></select></label>
   </div>
   <div v-if="loading" class="pc-state" role="status">{{ t('projectcreatoraio', 'Loading projects…') }}</div>
   <div v-else-if="error" class="pc-state" role="alert"><p>{{ error }}</p><button class="pc-button" @click="$emit('retry')">{{ t('projectcreatoraio', 'Try again') }}</button></div>
   <template v-else>
    <div v-if="!visible.length" class="pc-state"><h2>{{ t('projectcreatoraio', 'No projects found') }}</h2><p>{{ t('projectcreatoraio', 'Adjust the filters or create a new project.') }}</p></div>
    <div v-else class="pc-table-scroll"><table class="pc-project-table"><thead><tr><th>{{ t('projectcreatoraio', 'Project') }}</th><th>{{ t('projectcreatoraio', 'Client') }}</th><th>{{ t('projectcreatoraio', 'Location') }}</th><th>{{ t('projectcreatoraio', 'Status') }}</th><th><span class="hidden-visually">{{ t('projectcreatoraio', 'Open') }}</span></th></tr></thead><tbody><tr v-for="p in visible" :key="p.id"><td><a :href="projectUrl(p.id)" @click.prevent="$emit('open', Number(p.id))">{{ p.name }}</a><small>#{{ p.number }}</small></td><td>{{ p.client_name || '—' }}</td><td>{{ p.loc_city || '—' }}</td><td><span class="pc-badge" :class="{ 'pc-positive': Number(p.status) === 1 }">{{ statusLabel(p.status) }}</span></td><td><a :href="projectUrl(p.id)" :aria-label="t('projectcreatoraio', 'Open {name}', { name: p.name })" @click.prevent="$emit('open', Number(p.id))">→</a></td></tr></tbody></table></div>
    <footer class="pc-list-footer" aria-live="polite">{{ t('projectcreatoraio', '{count} projects', { count: visible.length }) }}</footer>
   </template>
  </div>
 </section>
</template>
<script>
import { PROJECT_STATUS_OPTIONS, getProjectStatusLabel } from '../constants/project-statuses.js'
import { filterProjects, interfaceUrl } from './navigation.js'
export default {
 props: { projects: { type: Array, required: true }, loading: Boolean, error: { type: String, default: '' }, filters: { type: Object, required: true }, base: { type: String, required: true }, isGlobalAdmin: Boolean, isOrganizationAdmin: Boolean, myProjectIds: { type: Array, default: () => [] } },
 computed: {
  statuses() { return PROJECT_STATUS_OPTIONS },
  organizations() { return [...new Set(this.projects.map(p => p.organization_id).filter(Boolean))] },
  visible() { return filterProjects(this.projects, this.filters).filter(p => !this.isOrganizationAdmin || this.filters.scope !== 'my' || this.myProjectIds.includes(Number(p.id))).filter(p => !this.isGlobalAdmin || this.filters.organization === 'all' || String(p.organization_id) === this.filters.organization) },
 },
 methods: { filter(key, value) { this.$emit('filter', { key, value }) }, statusLabel: getProjectStatusLabel, projectUrl(id) { return interfaceUrl(this.base, { projectId: id }) } },
}
</script>
