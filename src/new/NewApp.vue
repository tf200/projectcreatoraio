<template>
 <NcContent app-name="projectcreatoraio">
  <NcAppContent :allow-swipe-navigation="false">
   <main class="pc-new">
    <div class="pc-interface-bar">
     <span>{{ t('projectcreatoraio', 'New interface') }}</span>
     <a :href="legacyUrl">{{ t('projectcreatoraio', 'Back to current interface') }}</a>
    </div>
    <section v-if="contextLoading" class="pc-state" role="status">{{ t('projectcreatoraio', 'Loading project workspace…') }}</section>
    <section v-else-if="contextError" class="pc-state" role="alert">
     <h1>{{ t('projectcreatoraio', 'Project workspace unavailable') }}</h1><p>{{ contextError }}</p>
     <button class="pc-button" @click="initialize">{{ t('projectcreatoraio', 'Try again') }}</button>
    </section>
    <section v-else-if="!hasAccess" class="pc-state">
     <h1>{{ t('projectcreatoraio', 'No organization assigned') }}</h1>
     <p>{{ t('projectcreatoraio', 'Ask your administrator for access to an organization.') }}</p>
    </section>
    <template v-else>
     <ProjectShelf ref="shelf" :projects="projects" :selected-id="route.projectId" :selected-name="project ? project.name : ''" :loading="listLoading" :error="listError" :filters="filters" :base="base" :is-global-admin="context.isGlobalAdmin" :is-organization-admin="context.organizationRole === 'admin' && !context.isGlobalAdmin" :my-project-ids="myProjectIds" :recent-project-ids="recentProjectIds" @filter="setFilter" @open="navigate($event, route.tab)" @retry="retryList" />
    <template v-if="route.projectId">
     <section v-if="projectLoading" class="pc-state" role="status">{{ t('projectcreatoraio', 'Loading project…') }}</section>
     <section v-else-if="projectError" class="pc-state" role="alert">
      <h1>{{ t('projectcreatoraio', 'Project unavailable') }}</h1><p>{{ projectError }}</p>
      <button class="pc-button" @click="loadProject">{{ t('projectcreatoraio', 'Try again') }}</button>
      <button class="pc-secondary" @click="$refs.shelf.openPicker()">{{ t('projectcreatoraio', 'Choose another project') }}</button>
     </section>
     <template v-else-if="project">
      <ProjectHeader :overview="overview" :context="context" :project="project" :tab="route.tab" :base="base" :legacy-url="legacyUrl" @navigate="navigate(route.projectId, $event)" />
      <NewOverview :overview="overview" :context="context" @retry="reloadOverview" v-if="route.tab === 'overview'" :project="project" :legacy-url="legacyUrl" @navigate="navigate(route.projectId, $event)" />
      <section v-else class="pc-module" :class="'pc-module--' + route.tab" :aria-label="tabLabel" :aria-busy="projectLoading">
       <div v-if="moduleError" class="pc-state" role="alert"><h2>{{ t('projectcreatoraio', 'Section unavailable') }}</h2><p>{{ t('projectcreatoraio', 'Open this section in the current interface to continue.') }}</p><a :href="legacyUrl" class="pc-button">{{ t('projectcreatoraio', 'Open current interface') }}</a></div>
       <ProjectModule v-else :key="route.projectId + ':' + route.tab" :project="project" :context="context" :tab="route.tab" :legacy-url="legacyUrl" />
      </section>
     </template>
    </template>
    <section v-else-if="!listLoading && !listError" class="pc-state">
     <h1>{{ t('projectcreatoraio', 'Your project workspace') }}</h1>
     <p>{{ t('projectcreatoraio', 'Create a project to get started. Your projects will appear in the shelf above.') }}</p>
     <a class="pc-button" :href="base + '?create=1'">{{ t('projectcreatoraio', 'New project') }}</a>
    </section>
    </template>
   </main>
  </NcAppContent>
 </NcContent>
</template>
<script>
import overviewState from './overview-state.js'

import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import { generateUrl } from '@nextcloud/router'
import { api, errorMessage } from './api.js'
import { readRoute, interfaceUrl, normalizeTab } from './navigation.js'
import ProjectShelf from './ProjectShelf.vue'
import ProjectHeader from './ProjectHeader.vue'
import NewOverview from './NewOverview.vue'
import ProjectModule from './ProjectModule.vue'

export default {
 mixins: [overviewState],
 name: 'NewProjectApp',
 components: { NcContent, NcAppContent, ProjectShelf, ProjectHeader, NewOverview, ProjectModule },
 data() {
  return { base: generateUrl('/apps/projectcreatoraio'), route: readRoute(location.pathname, location.search), context: null, contextLoading: true, contextError: '', projects: [], myProjectIds: [], recentProjectIds: [], listLoaded: false, listLoading: false, listError: '', project: null, projectLoading: false, projectError: '', filters: { query: '', status: 'all', sort: 'recent', organization: 'all', scope: 'all', client: '' }, moduleError: false, requestVersion: 0, listVersion: 0, contextVersion: 0 }
 },
 computed: {
  hasAccess() { return !!(this.context?.isGlobalAdmin || this.context?.organizationId) },
  legacyUrl() { return interfaceUrl(this.base, this.route, false) },
  tabLabel() { return this.route.tab },
 },
 mounted() { window.addEventListener('popstate', this.onPopState); this.initialize() },
 beforeDestroy() { this.requestVersion++; this.listVersion++; this.contextVersion++; window.removeEventListener('popstate', this.onPopState) },
 errorCaptured(error) { console.error('New project module failed', error); this.moduleError = true; return false },
 methods: {
  async initialize() {
   const version = ++this.contextVersion
   this.contextLoading = true; this.contextError = ''
   try {
    const context = await api.context()
    if (version !== this.contextVersion) return
    this.context = context
    if (this.hasAccess) await this.loadRoute()
   } catch (error) { if (version === this.contextVersion) this.contextError = errorMessage(error) }
   finally { if (version === this.contextVersion) this.contextLoading = false }
  },
  setFilter({ key, value }) { this.$set(this.filters, key, value) },
  async loadList() {
   const version = ++this.listVersion
   this.listLoading = true; this.listError = ''
   try {
    const scoped = this.context.organizationRole === 'admin' && !this.context.isGlobalAdmin
    const [rows, mine] = await Promise.all([api.list(), scoped ? api.myProjects(this.context.userId) : Promise.resolve([])])
    if (!Array.isArray(rows) || !Array.isArray(mine)) throw new Error('Invalid project list response')
    if (version === this.listVersion) { this.projects = rows; this.myProjectIds = mine.map(p => Number(p.id)); this.listLoaded = true }
   }
   catch (error) { if (version === this.listVersion) { this.projects = []; this.listError = errorMessage(error) } }
   finally { if (version === this.listVersion) this.listLoading = false }
  },
  async loadProject() {
   const version = ++this.requestVersion
   const id = this.route.projectId
   this.project = null; this.projectLoading = true; this.projectError = ''; this.moduleError = false
   try {
    const project = await api.project(id)
    if (version !== this.requestVersion) return
    if (!project || Number(project.id) !== id) throw new Error('Unexpected project response')
    this.project = project
    this.recentProjectIds = [id, ...this.recentProjectIds.filter(value => value !== id)].slice(0, 30)
    try { localStorage.setItem(this.base + ':last-project:' + this.context.userId, String(id)) } catch { /* Storage can be unavailable. */ }
    this.loadOverview()
   } catch (error) { if (version === this.requestVersion) this.projectError = errorMessage(error) }
   finally { if (version === this.requestVersion) this.projectLoading = false }
  },
  async loadRoute() {
   if (!this.listLoaded) await this.loadList()
   if (!this.route.projectId && this.projects.length) {
    let remembered = null
    try { remembered = Number(localStorage.getItem(this.base + ':last-project:' + this.context.userId)) } catch { /* Default to an accessible project. */ }
    const first = this.projects.find(p => Number(p.id) === remembered) || this.projects[0]
    this.route = { projectId: Number(first.id), tab: 'overview' }
    history.replaceState(null, '', interfaceUrl(this.base, this.route, true))
   }
   if (this.route.projectId) await this.loadProject()
   else { this.requestVersion++; this.project = null; this.projectLoading = false; this.projectError = '' }
  },
  async retryList() { await this.loadList(); if (!this.route.projectId && !this.listError) await this.loadRoute() },
  scrollContainer() { return this.$el.querySelector('.app-content') || this.$el },
  async navigate(projectId, tab = 'overview') {
   const sameProject = projectId && projectId === this.route.projectId && this.project
   this.route = { projectId: projectId ? Number(projectId) : null, tab: normalizeTab(tab) }
   this.moduleError = false
   history.pushState(null, '', interfaceUrl(this.base, this.route, true))
   if (!sameProject) await this.loadRoute()
   else if (this.route.tab === 'overview') this.loadOverview()
   await this.$nextTick()
   this.scrollContainer().scrollTop = 0
  },
  async onPopState() {
   this.route = readRoute(location.pathname, location.search); this.moduleError = false
   await this.loadRoute()
   await this.$nextTick()
   this.scrollContainer().scrollTop = 0
  },
 },
}
</script>
