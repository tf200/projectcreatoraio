<template>
 <section class="pc-overview">
  <article class="pc-summary pc-summary-wide"><h2>{{ t('projectcreatoraio', 'Project purpose & details') }}</h2><p>{{ project.description || t('projectcreatoraio', 'No project purpose has been added yet.') }}</p><dl><div><dt>{{ t('projectcreatoraio', 'Client') }}</dt><dd>{{ project.client_name || '—' }}</dd></div><div><dt>{{ t('projectcreatoraio', 'Location') }}</dt><dd>{{ [project.loc_street, project.loc_zip, project.loc_city].filter(Boolean).join(', ') || '—' }}</dd></div><div><dt>{{ t('projectcreatoraio', 'Project owner') }}</dt><dd>{{ project.ownerId }}</dd></div></dl><a :href="legacyUrl">{{ t('projectcreatoraio', 'Edit project details') }} →</a></article>
  <article class="pc-summary"><h2>{{ t('projectcreatoraio', 'Project contact') }}</h2><strong>{{ project.client_name || t('projectcreatoraio', 'Not provided yet') }}</strong><p v-if="project.client_role && project.client_role.length">{{ roles }}</p><p>{{ project.client_email || '—' }}</p><p>{{ project.client_phone || '—' }}</p><button class="pc-link" @click="$emit('navigate', 'members')">{{ t('projectcreatoraio', 'View project team') }} →</button></article>
  <article class="pc-summary"><h2>{{ t('projectcreatoraio', 'Continue working') }}</h2><p>{{ t('projectcreatoraio', 'Open a section using the tabs. You are working with the same projects and data as in the current interface.') }}</p><button class="pc-link" @click="$emit('navigate', 'tasks')">{{ t('projectcreatoraio', 'Open tasks') }} →</button><button class="pc-link" @click="$emit('navigate', 'documents')">{{ t('projectcreatoraio', 'Open documents') }} →</button><button class="pc-link" @click="$emit('navigate', 'planning')">{{ t('projectcreatoraio', 'Open planning') }} →</button></article>
  <p class="pc-overview-note">{{ t('projectcreatoraio', 'This is the first version of the new interface. Detailed overview summaries will follow later; all existing sections remain available in the current interface.') }}</p>
 </section>
</template>
<script>
import { formatClientRoles } from '../macros/client-roles.js'
export default {
 props: { project: { type: Object, required: true }, legacyUrl: { type: String, required: true } },
 computed: { roles() { return formatClientRoles(this.project.client_role) } },
}
</script>
