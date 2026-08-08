<template>
	<div id="projectcreatoraio" :class="containerClasses">
		<div class="project-creator-form">
			<h1 class="project-creator-title">Create a New Project</h1>
			<p class="project-creator-subtitle">
				Fill out the details below to set up your new project environment.
			</p>

			<NcNoteCard v-if="submissionStatus" :type="submissionStatus" class="status-card">
				<strong>{{ statusMessage }}</strong>
				<p v-if="statusDescription" class="status-description">{{ statusDescription }}</p>
			</NcNoteCard>

			<form @submit.prevent="createProject">
				<!-- SECTION 1: BASIC INFORMATION -->
				<div class="form-section">
					<h3 class="form-section-title">Basic Information</h3>
					<div class="form-row">
						<NcTextField
							v-model="project.name"
							label="Project Name*"
							class="form-row-item"
							placeholder="e.g., Q4 Marketing Campaign"
							:show-label="true" />

						<NcTextField
							v-model="project.number"
							label="Project Number*"
							placeholder="e.g., P-2025-001"
							:show-label="true"
							class="form-row-item" />
					</div>

					<div class="form-row">
						<NcSelect
							v-model="selectedProjectType"
							class="form-row-item"
							placeholder="Select project type"
							input-label="Project Type*"
							:options="PROJECT_TYPES"
							:show-label="true"
							:multiple="false" />

						<NcTextField
							v-model="project.required_preparation_weeks"
							type="number"
							min="0"
							label="Preparation Time (weeks)"
							class="form-row-item"
							placeholder="e.g., 2"
							:show-label="true" />
					</div>

					<div v-if="isAdmin" class="form-row">
						<OrganizationsFetcher
							class="form-row-item"
							input-label="Organization*"
							placeholder="Search for an organization..."
							:model-value="project.organizationId"
							@update:modelValue="project.organizationId = $event"
							@error="handleDependencyError" />
					</div>
				</div>

				<!-- SECTION 2: CLIENT INFORMATION -->
				<div class="form-section">
					<h3 class="form-section-title">Client Information</h3>
					<div class="form-row">
						<NcTextField
							v-model="project.client_name"
							label="Client Name"
							class="form-row-item"
							placeholder="e.g., ACME Corp"
							:show-label="true" />

						<NcTextField
							v-model="project.client_role"
							label="Client Role"
							class="form-row-item"
							placeholder="e.g., Project sponsor"
							:show-label="true" />
					</div>

					<div class="form-row">
						<NcTextField
							v-model="project.client_phone"
							label="Client Phone"
							class="form-row-item"
							placeholder="e.g., +1 555 123 4567"
							:show-label="true" />

						<NcTextField
							v-model="project.client_email"
							label="Client Email"
							class="form-row-item"
							placeholder="e.g., client@example.com"
							:show-label="true" />
					</div>

					<div class="form-row">
						<NcTextField
							v-model="project.client_address"
							label="Client Address"
							class="form-row-item"
							placeholder="e.g., 12 Market Street"
							:show-label="true" />
					</div>
				</div>

				<!-- SECTION 3: PROJECT LOCATION -->
				<div class="form-section">
					<h3 class="form-section-title">Project Location</h3>
					<div class="form-row">
						<NcTextField
							v-model="project.loc_street"
							label="Location Street"
							class="form-row-item"
							placeholder="e.g., 45 Industrial Ave"
							:show-label="true" />

						<NcTextField
							v-model="project.loc_city"
							label="Location City"
							class="form-row-item"
							placeholder="e.g., Toronto"
							:show-label="true" />

						<NcTextField
							v-model="project.loc_zip"
							label="Location ZIP"
							class="form-row-item"
							placeholder="e.g., 10001"
							:show-label="true" />
					</div>
					<ProjectLocationMap
						:street="project.loc_street"
						:city="project.loc_city"
						:zip="project.loc_zip" />
				</div>

				<!-- SECTION 4: DESCRIPTION -->
				<div class="form-section">
					<h3 class="form-section-title">Description</h3>
					<div class="form-row">
						<NcTextArea
							v-model="project.description"
							class="form-row-item"
							label="Project Description"
							placeholder="Provide project overview and details"
							:show-label="true"
							rows="3" />
					</div>
				</div>

				<!-- STICKY ACTION FOOTER BAR -->
				<div class="project-creator-actions">
					<div class="action-status-hint">
						<span v-if="!canSubmit" class="hint-text">* Required: Name, Number, Type<template v-if="isAdmin">, Organization</template></span>
					</div>
					<div class="action-buttons">
						<NcButton
							v-if="embedded"
							type="secondary"
							class="cancel-button"
							@click="$emit('cancel')">
							Cancel
						</NcButton>
						<NcButton
							:disabled="isCreatingProject || !canSubmit"
							type="primary"
							@click="createProject"
							class="submit-button">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ isCreatingProject ? 'Creating Project...' : 'Create Project' }}
						</NcButton>
					</div>
				</div>
			</form>
		</div>
	</div>
</template>

<script>
import NcTextField from '@nextcloud/vue/components/NcTextField';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcTextArea from '@nextcloud/vue/components/NcTextArea';
import NcButton from '@nextcloud/vue/components/NcButton';
import NcSelect from '@nextcloud/vue/components/NcSelect';
import Plus from 'vue-material-design-icons/Plus.vue';

import { getCurrentUser } from '@nextcloud/auth';
import { PROJECT_TYPES } from '../macros/project-types';
import { ProjectsService } from '../Services/projects';
import { Project } from '../Models/project';

import OrganizationsFetcher from './OrganizationsFetcher.vue'
import ProjectLocationMap from './ProjectLocationMap.vue'

const projectsService = ProjectsService.getInstance();

export default {
	name: 'ProjectCreator',
	emits: ['cancel', 'created'],
	props: {
		embedded: {
			type: Boolean,
			default: false,
		},
	},
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcNoteCard,
		OrganizationsFetcher,
		NcTextArea,
		Plus,
		ProjectLocationMap,
	},
	data() {
		return {
			project: new Project(),
			isCreatingProject: false,
			submissionStatus: '',
			statusMessage: '',
			statusDescription: '',
			PROJECT_TYPES,
		};
	},
	computed: {
		containerClasses() {
			return {
				'project-creator-container': true,
				'project-creator-container--embedded': this.embedded,
			}
		},
		selectedProjectType: {
			get() {
				return this.PROJECT_TYPES.find((type) => type.id === this.project.type) || null;
			},
			set(option) {
				this.project.type = option ? option.id : null;
			},
		},
		isAdmin() {
			return !!getCurrentUser()?.isAdmin;
		},
		canSubmit() {
			if (!this.project.name || !this.project.number || isNaN(this.project.type)) {
				return false;
			}

			if (this.isAdmin && !this.project.organizationId) {
				return false;
			}

			return true;
		},
	},
	methods: {
		handleDependencyError(error) {
			this.showProjectCreationErrorMessage(error)
		},
		async createProject() {
			if (!this.canSubmit || this.isCreatingProject) return;

			this.isCreatingProject = true;
			this.submissionStatus = '';
			this.statusMessage = '';
			this.statusDescription = '';

			try {
				const result = await projectsService.create(this.project);
				this.showProjectCreationSuccessMessage();
				this.resetProjectForm();
				this.$emit('created', result);
				
				setTimeout(() => {
					this.resetProjectCreationMessage();
				}, 4000);

			} catch (error) {
				this.showProjectCreationErrorMessage(error);
				console.error('Error creating project:', error);
			} finally {
				this.isCreatingProject = false;
			}
		},
		resetProjectForm() {
			this.project = new Project();
		},
		showProjectCreationSuccessMessage() {
			this.submissionStatus = 'success';
			this.statusMessage = 'Project has been created successfully';
		},
		resetProjectCreationMessage() {
			this.submissionStatus = '';
			this.statusMessage = '';
			this.statusDescription = '';
		},
		showProjectCreationErrorMessage(error) {
			this.submissionStatus = 'error';
			let fullMessage = 'An unknown error occurred.';
			if (error.response && error.response.data && error.response.data.message) {
				fullMessage = error.response.data.message;
			} else if (error.message) {
				fullMessage = error.message;
			}

			const ocsMatch = fullMessage.match(/(?:Exception|OCSException): ([\s\S]*?) in \/var\/www\/nextcloud\//);

			if (ocsMatch && ocsMatch[1]) {
				this.statusMessage = 'Project creation failed';
				this.statusDescription = ocsMatch[1].trim();
			} else {
				const stackTraceSplit = fullMessage.split('\nStack trace:');
				this.statusMessage = stackTraceSplit[0];
				this.statusDescription = '';
			}
		},
	},
}
</script>

<style scoped>
.project-creator-container {
	padding: 32px 48px;
	display: flex;
	justify-content: center;
	width: 100%;
}

.project-creator-container--embedded {
	padding: 16px 24px;
}

.project-creator-form {
	max-width: 720px;
	width: 100%;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.project-creator-title {
	font-size: 1.8em;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: 0;
}

.project-creator-subtitle {
	font-size: 1em;
	color: var(--color-text-maxcontrast);
	margin-top: -12px;
	margin-bottom: 12px;
}

.form-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.form-section-title {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-primary-element);
}

.form-row {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.form-row-item {
	flex: 1;
	min-width: 0;
}

.location-map {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background: var(--color-background-dark);
}

.location-map__title,
.location-map__status,
.location-map__attribution {
	margin: 0;
	padding: 8px 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.location-map__title {
	font-weight: 700;
	color: var(--color-main-text);
	border-bottom: 1px solid var(--color-border);
}

.location-map__frame {
	display: block;
	width: 100%;
	height: 220px;
	border: 0;
}

.location-map__attribution {
	border-top: 1px solid var(--color-border);
}

.location-map__attribution a {
	color: var(--color-primary-element);
}

/* STICKY FOOTER ACTION BAR */
.project-creator-actions {
	position: sticky;
	bottom: 0;
	background: var(--color-main-background);
	z-index: 20;
	border-top: 1px solid var(--color-border);
	padding: 16px 0;
	margin-top: 8px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.action-status-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.action-buttons {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-left: auto;
}

.submit-button {
	height: 40px;
}

.cancel-button {
	height: 40px;
}

.status-card {
	margin-bottom: 4px;
}

.status-description {
	margin-top: 6px;
	margin-bottom: 0;
	font-size: 0.9em;
	word-break: break-word;
}
</style>
