<template>
 <section>
  <header class="pc-list-heading"><div><h1>{{ t('projectcreatoraio', 'Projecten') }}</h1><p>{{ t('projectcreatoraio', 'Alle projecten en hun volgende stap.') }}</p></div><a class="pc-button" :href="base + '?create=1'">+ {{ t('projectcreatoraio', 'Nieuw project') }}</a></header>
  <p class="pc-subtle">{{ t('projectcreatoraio', 'Aanmaken opent voorlopig in de huidige interface.') }}</p>
  <div class="pc-list-surface">
   <div class="pc-filters">
    <label v-if="isOrganizationAdmin">{{ t('projectcreatoraio', 'Weergave') }}<select :value="filters.scope" @change="filter('scope', $event.target.value)"><option value="all">{{ t('projectcreatoraio', 'Alle organisatieprojecten') }}</option><option value="my">{{ t('projectcreatoraio', 'Mijn projecten') }}</option></select></label>
    <label class="pc-search">{{ t('projectcreatoraio', 'Zoeken') }}<input type="search" :value="filters.query" :placeholder="t('projectcreatoraio', 'Project, nummer, opdrachtgever of plaats')" @input="filter('query', $event.target.value)"></label>
    <label>{{ t('projectcreatoraio', 'Status') }}<select :value="filters.status" @change="filter('status', $event.target.value)"><option value="all">{{ t('projectcreatoraio', 'Alle statussen') }}</option><option v-for="s in statuses" :key="s.value" :value="String(s.value)">{{ s.label }}</option></select></label>
    <label v-if="isGlobalAdmin">{{ t('projectcreatoraio', 'Organisatie') }}<select :value="filters.organization" @change="filter('organization', $event.target.value)"><option value="all">{{ t('projectcreatoraio', 'Alle organisaties') }}</option><option v-for="id in organizations" :key="id" :value="String(id)">{{ t('projectcreatoraio', 'Organisatie {id}', { id }) }}</option></select></label>
    <label>{{ t('projectcreatoraio', 'Sorteren') }}<select :value="filters.sort" @change="filter('sort', $event.target.value)"><option value="name">{{ t('projectcreatoraio', 'Naam') }}</option><option value="number">{{ t('projectcreatoraio', 'Projectnummer') }}</option></select></label>
   </div>
   <div v-if="loading" class="pc-state" role="status">{{ t('projectcreatoraio', 'Projecten laden…') }}</div>
   <div v-else-if="error" class="pc-state" role="alert"><p>{{ error }}</p><button class="pc-button" @click="$emit('retry')">{{ t('projectcreatoraio', 'Opnieuw proberen') }}</button></div>
   <template v-else>
    <div v-if="!visible.length" class="pc-state"><h2>{{ t('projectcreatoraio', 'Geen projecten gevonden') }}</h2><p>{{ t('projectcreatoraio', 'Pas de filters aan of maak een nieuw project aan.') }}</p></div>
    <div v-else class="pc-table-scroll"><table class="pc-project-table"><thead><tr><th>{{ t('projectcreatoraio', 'Project') }}</th><th>{{ t('projectcreatoraio', 'Opdrachtgever') }}</th><th>{{ t('projectcreatoraio', 'Locatie') }}</th><th>{{ t('projectcreatoraio', 'Status') }}</th><th><span class="hidden-visually">{{ t('projectcreatoraio', 'Openen') }}</span></th></tr></thead><tbody><tr v-for="p in visible" :key="p.id"><td><a :href="projectUrl(p.id)" @click.prevent="$emit('open', Number(p.id))">{{ p.name }}</a><small>#{{ p.number }}</small></td><td>{{ p.client_name || '—' }}</td><td>{{ p.loc_city || '—' }}</td><td><span class="pc-badge" :class="{ 'pc-positive': Number(p.status) === 1 }">{{ statusLabel(p.status) }}</span></td><td><a :href="projectUrl(p.id)" :aria-label="t('projectcreatoraio', 'Open {name}', { name: p.name })" @click.prevent="$emit('open', Number(p.id))">→</a></td></tr></tbody></table></div>
    <footer class="pc-list-footer" aria-live="polite">{{ t('projectcreatoraio', '{count} projecten', { count: visible.length }) }}</footer>
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
