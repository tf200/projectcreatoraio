<template>
 <section class="pc-overview">
  <article class="pc-summary pc-summary-wide"><h2>{{ t('projectcreatoraio', 'Projectdoel & gegevens') }}</h2><p>{{ project.description || t('projectcreatoraio', 'Er is nog geen projectdoel beschreven.') }}</p><dl><div><dt>{{ t('projectcreatoraio', 'Opdrachtgever') }}</dt><dd>{{ project.client_name || '—' }}</dd></div><div><dt>{{ t('projectcreatoraio', 'Locatie') }}</dt><dd>{{ [project.loc_street, project.loc_zip, project.loc_city].filter(Boolean).join(', ') || '—' }}</dd></div><div><dt>{{ t('projectcreatoraio', 'Projecteigenaar') }}</dt><dd>{{ project.ownerId }}</dd></div></dl><a :href="legacyUrl">{{ t('projectcreatoraio', 'Projectgegevens bewerken') }} →</a></article>
  <article class="pc-summary"><h2>{{ t('projectcreatoraio', 'Projectcontact') }}</h2><strong>{{ project.client_name || t('projectcreatoraio', 'Nog niet ingevuld') }}</strong><p v-if="project.client_role && project.client_role.length">{{ roles }}</p><p>{{ project.client_email || '—' }}</p><p>{{ project.client_phone || '—' }}</p><button class="pc-link" @click="$emit('navigate', 'members')">{{ t('projectcreatoraio', 'Projectteam bekijken') }} →</button></article>
  <article class="pc-summary"><h2>{{ t('projectcreatoraio', 'Verder werken') }}</h2><p>{{ t('projectcreatoraio', 'Open een onderdeel via de tabbladen. Je werkt met dezelfde projecten en gegevens als in de huidige interface.') }}</p><button class="pc-link" @click="$emit('navigate', 'tasks')">{{ t('projectcreatoraio', 'Taken openen') }} →</button><button class="pc-link" @click="$emit('navigate', 'documents')">{{ t('projectcreatoraio', 'Documenten openen') }} →</button><button class="pc-link" @click="$emit('navigate', 'planning')">{{ t('projectcreatoraio', 'Planning openen') }} →</button></article>
  <p class="pc-overview-note">{{ t('projectcreatoraio', 'Dit is de eerste versie van de nieuwe interface. De uitgebreide overzichtssamenvattingen volgen later; alle bestaande onderdelen blijven bereikbaar via de huidige interface.') }}</p>
 </section>
</template>
<script>
import { formatClientRoles } from '../macros/client-roles.js'
export default {
 props: { project: { type: Object, required: true }, legacyUrl: { type: String, required: true } },
 computed: { roles() { return formatClientRoles(this.project.client_role) } },
}
</script>
