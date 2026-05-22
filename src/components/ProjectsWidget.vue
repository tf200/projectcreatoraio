<template>
    <div class="project-widget">
        <div class="filter-container">
            <NcTextField v-model="searchQuery"
                :label="t('projectcreatoraio', 'Search Projects')"
                :placeholder="t('projectcreatoraio', 'e.g: Project Alpha...')"
                trailing-button-icon="close">
                <template #icon>
                    <Magnify :size="20" />
                </template>
            </NcTextField>
            
            <NcActions v-if="isAdmin">
                <template #icon>
                    <NcAvatar 
                        v-if="selectedUser" 
                        :display-name="selectedUser.displayName" 
                        :is-no-user="true" 
                        size="32" />
                    <AccountPlus v-else :size="20" />
                </template>

                <NcActionInput
                    v-model="selectedUser"
                    ref="usersInputRef"
                    type="multiselect"
                    track-by="label"
                    :append-to-body="true"
                    :multiple="false"
                    :options="allUsers"
                    :loading="isFetchingUsers"
                    @search="fetchUsers"
                    @update:modelValue="fetchProjectsByUser">
                    <template #icon>
                        <Account :size="20" />
                    </template>
                    Please select a user
                </NcActionInput>
            </NcActions>
        </div>

        <div v-if="loading" class="loading-placeholder">
            <NcLoadingIcon :size="44" />
        </div>

        <NcEmptyContent v-else-if="projects.length === 0"
            :name="t('projectcreatoraio', 'No projects found')">
            <template #icon>
                <FolderOutline :size="36" />
            </template>
        </NcEmptyContent>

        <div v-else class="project-list">
            <ul>
                <NcListItem
                    v-for="project in filteredProjects"
                    :name="project.name"
                    :active="selectedProjectId === project.id"

                    @click="selectProject(project)">

                    <template #icon>
                        <FolderOutline :size="30" />
                    </template>
                    
                    <template #subname>
                        {{ PROJECT_TYPES[project.type]?.label }}
                    </template>

                    <template #indicator>
                        <NcChip
                            no-close 
                            :text="currentProjectStatus(project)" 
                            :variant="selectedProjectId !== project.id ? 'primary':'secondary'" />
                    </template>

                    <template #extra>
                        <button class="project-arrow-btn"
                            :title="t('projectcreatoraio', 'Go to project')"
                            @click.stop="navigateToProject(project)">
                            <ChevronRight :size="20" />
                        </button>
                    </template>
                </NcListItem>
            </ul>
        </div>
    </div>
</template>

<script>
import { NcActions, NcEmptyContent, NcTextField } from '@nextcloud/vue'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActionInput from '@nextcloud/vue/components/NcActionInput'
import { t } from '@nextcloud/l10n'

import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue';
import Account from 'vue-material-design-icons/Account.vue';
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue';
import NcAvatar from '@nextcloud/vue/components/NcAvatar';
import NcChip from '@nextcloud/vue/components/NcChip';
import { getCurrentUser } from '@nextcloud/auth'
import { PROJECT_TYPES } from '../macros/project-types';
import {
	PROJECT_STATUS_OPTIONS,
	getProjectStatusLabel,
} from '../constants/project-statuses.js'
import { UsersService } from '../Services/users'
import { ProjectsService } from '../Services/projects'
import { generateUrl } from '@nextcloud/router';

const usersService = UsersService.getInstance();
const projectsService = ProjectsService.getInstance();

export default {
	name: 'ProjectsWidget',
	components: {
		NcActions,
		NcEmptyContent,
        NcLoadingIcon,
        NcTextField,
        FolderOutline,
        Magnify,
        NcListItem,
        ChevronRight,
        NcActionInput,
        AccountPlus,
        Account,
        NcAvatar,
        NcChip
	},
	data() {
		return {
			t,
			projects: [],
			loading: true,
			searchQuery: null,
            selectedProjectId: null,
            showFilterDialog: false,
            isFetchingUsers: false,
            selectedUser: null,
            PROJECT_TYPES,
            allUsers: [],
            searchTimeout: undefined,
            statuses: PROJECT_STATUS_OPTIONS
		}
	},
	computed: {
        isAdmin() {
            return !!getCurrentUser()?.isAdmin;
        },
		filteredProjects() {
			if (!this.searchQuery) {
				return this.projects;
			}

			return this.projects.filter(project => {
				return project.name.toLowerCase().includes(this.searchQuery.toLowerCase());
			});
		}
	},
	async mounted() {
        const panelContent = this.$el.closest('.panel--content');
        if (panelContent) {
            panelContent.style.overflowY = 'scroll';
        }

        await this.listProjects();
	},
	methods: {
        async listProjects() {
            this.loading = true;
            this.projects = await projectsService.list();
            this.loading = false;
        },
        async fetchUsers(query) {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout);
			}

			this.isFetchingUsers = true;

			this.searchTimeout = setTimeout(async () => {
                this.allUsers = await usersService.search(query);
                this.isFetchingUsers = false;
			}, 300);
		},
        async fetchProjectsByUser(user) {
            try {
                if(user) {
                    this.projects = await projectsService.fetchProjectsByUser(user.id);
                } else {
                    this.projects = await projectsService.list();
                }
            } catch(error) {
                console.error('Error searching for user projects', error);
                this.projects = [];
            }
        },
        selectProject(project) {
            let eventPayload = null;

            if (this.selectedProjectId === project.id) {
                this.selectedProjectId = null;
            } else {
                this.selectedProjectId = project.id;
                eventPayload = {
                    projectId: project.id,
                    boardId: project.boardId
                };
            }

            const event = new CustomEvent('projectcreatoraio:project-selected', { detail: eventPayload });
            document.dispatchEvent(event);
        },
        navigateToProject(project) {
            const url = generateUrl(`/apps/projectcreatoraio/${project.id}`);
            window.open(url, "_blank");
        },
        currentProjectStatus(project) {
            if (!this.statuses || !project) {
                return 'Unknown';
            }
            return getProjectStatusLabel(project.status)
        }
	}
}
</script>

<style lang="css" scoped>
@import '../styles/dashboard.css';

.project-arrow-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    color: var(--color-main-text);
    transition: background-color 0.2s;
}

.project-arrow-btn:hover {
    background-color: var(--color-background-hover);
}
</style>
