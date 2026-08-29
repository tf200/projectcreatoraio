<template>
	<div v-if="contextError" class="projects-home-empty">
		<NcEmptyContent name="Could not load project context" :description="contextError">
			<template #icon>
				<AlertCircle :size="44" />
			</template>
		</NcEmptyContent>
	</div>
	<div v-else-if="!hasProjectAccess" class="projects-home-empty">
		<NcEmptyContent
			name="No organization assigned"
			description="Ask your administrator to assign your account to an organization before creating projects.">
			<template #icon>
				<OfficeBuilding :size="44" />
			</template>
		</NcEmptyContent>
	</div>
	<div
		v-else
		class="projects-home"
		:class="{
			'projects-home--narrow': isNarrow,
			'projects-home--narrow-details': isNarrow && mobilePane === 'details',
			'projects-home--sidebar-collapsed': isSidebarCollapsed && !isNarrow,
		}">
		<!-- Collapsible Sidebar -->
		<aside
			v-show="!isNarrow || mobilePane === 'list'"
			class="projects-home__sidebar"
			:class="{ 'projects-home__sidebar--collapsed': isSidebarCollapsed && !isNarrow }">
			<header class="projects-home__sidebar-header">
				<div v-if="!isSidebarCollapsed || isNarrow" class="projects-home__sidebar-brand">
					<h2 class="projects-home__title">
						Projects
					</h2>
					<p class="projects-home__subtitle">
						Manage spaces
					</p>
				</div>
				<div class="projects-home__sidebar-actions">
					<NcButton
						v-if="!isNarrow"
						type="tertiary"
						:aria-label="isSidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
						@click="toggleSidebar">
						<template #icon>
							<MenuOpen v-if="isSidebarCollapsed" :size="18" />
							<MenuClose v-else :size="18" />
						</template>
					</NcButton>
					<NcButton
						v-if="!isSidebarCollapsed || isNarrow"
						type="primary"
						@click="startCreateProject">
						<template #icon>
							<Plus :size="18" />
						</template>
						<span v-if="!isSidebarCollapsed || isNarrow">New</span>
					</NcButton>
				</div>
			</header>

			<div v-if="!isSidebarCollapsed || isNarrow" class="projects-home__controls">
				<div v-if="isOrganizationAdmin" class="projects-home__control-row">
					<label class="projects-home__control-label" for="projects-scope">View</label>
					<select
						id="projects-scope"
						v-model="projectScope"
						class="projects-home__filter-select"
						aria-label="Project scope"
						@change="loadProjects">
						<option value="all">
							All organization
						</option>
						<option value="my">
							My projects
						</option>
					</select>
				</div>

				<NcTextField
					v-model="searchQuery"
					label="Search projects"
					input-label="Search projects"
					placeholder="Search by name or number">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>

				<div class="projects-home__filters-row">
					<div class="projects-home__filter">
						<label class="projects-home__control-label" for="projects-status">Status</label>
						<select
							id="projects-status"
							v-model="statusFilter"
							class="projects-home__filter-select"
							aria-label="Filter projects">
							<option
								v-for="option in projectStatusFilterOptions"
								:key="option.value"
								:value="option.value">
								{{ option.label }}
							</option>
						</select>
					</div>
					<div class="projects-home__filter">
						<label class="projects-home__control-label" for="projects-sort">Sort</label>
						<select
							id="projects-sort"
							v-model="sortKey"
							class="projects-home__filter-select"
							aria-label="Sort projects">
							<option value="default">
								Default
							</option>
							<option value="name">
								Name
							</option>
							<option value="number">
								Number
							</option>
						</select>
					</div>
					<NcButton
						type="tertiary"
						:disabled="!canClearFilters"
						class="projects-home__clear"
						:aria-label="'Clear filters'"
						@click="clearFilters">
						<template #icon>
							<FilterRemove :size="16" />
						</template>
					</NcButton>
				</div>

				<div v-if="!loading" class="projects-home__count-row">
					{{ visibleProjects.length }} of {{ projects.length }} projects
				</div>
			</div>

			<!-- Collapsed sidebar: minimal controls -->
			<div v-else class="projects-home__sidebar-collapsed-controls">
				<NcButton
					type="tertiary"
					:title="'Search projects'"
					@click="isSidebarCollapsed = false">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcButton>
				<NcButton
					type="tertiary"
					:title="'Create new project'"
					@click="startCreateProject">
					<template #icon>
						<Plus :size="18" />
					</template>
				</NcButton>
			</div>

			<div class="projects-home__list">
				<div v-if="loading" class="projects-home__centered">
					<NcLoadingIcon :size="24" />
					<span>Loading projects...</span>
				</div>
				<NcEmptyContent
					v-else-if="visibleProjects.length === 0"
					name="No projects found"
					:description="canClearFilters ? 'Try adjusting your filters' : 'Create a project to get started.'">
					<template #icon>
						<FolderOutline :size="36" />
					</template>
				</NcEmptyContent>
				<ul v-else class="projects-home__items">
					<li v-for="project in visibleProjects" :key="project.id">
						<button
							type="button"
							class="projects-home__project-item"
							:class="{ 'projects-home__project-item--active': selectedProjectId === project.id }"
							:title="project.name"
							@click="selectProject(project)">
							<div class="projects-home__project-main">
								<div class="projects-home__project-title-row">
									<FolderOutline :size="isSidebarCollapsed && !isNarrow ? 20 : 18" />
									<span
										class="projects-home__project-name"
										:class="{ 'projects-home__project-name--collapsed': isSidebarCollapsed && !isNarrow }">
										{{ isSidebarCollapsed && !isNarrow ? projectInitials(project) : project.name }}
									</span>
									<span
										v-if="!isSidebarCollapsed || isNarrow"
										class="projects-home__status-pill"
										:class="statusPillClass(project.status)">
										{{ statusLabelShort(project.status) }}
									</span>
								</div>
								<div v-if="!isSidebarCollapsed || isNarrow" class="projects-home__project-meta">
									<span>{{ project.number || 'No number' }}</span>
									<span class="projects-home__meta-dot">•</span>
									<span>{{ typeLabelShort(project.type) }}</span>
								</div>
							</div>
						</button>
					</li>
				</ul>
			</div>

		</aside>

		<!-- Main Content Area -->
		<main v-show="!isNarrow || mobilePane === 'details'" ref="mainContainer" class="projects-home__main">
			<div v-if="selectedProject" class="projects-home__main-content">
				<!-- Hero Section -->
				<header class="projects-home__hero">
					<div v-if="isNarrow" class="projects-home__hero-mobile">
						<NcButton type="tertiary" class="projects-home__back" @click="mobilePane = 'list'">
							<template #icon>
								<ChevronLeft :size="18" />
							</template>
							Back to projects
						</NcButton>
					</div>
					<div class="projects-home__hero-main">
						<div class="projects-home__hero-info">
							<div class="projects-home__hero-title-row">
								<h2 class="projects-home__details-title">
									{{ selectedProject.name || 'Unnamed project' }}
								</h2>
								<NcButton
									v-if="canEditProjectTitle"
									type="tertiary"
									class="projects-home__title-edit-btn"
									aria-label="Edit project title"
									@click="startProjectProfileEdit('project')">
									<template #icon>
										<Pencil :size="15" />
									</template>
								</NcButton>
								<div class="projects-home__hero-badges">
									<span class="projects-home__badge" :class="statusBadgeClass(selectedProject.status)">
										{{ statusLabel(selectedProject.status) }}
									</span>
									<span class="projects-home__badge projects-home__badge--secondary">
										{{ typeLabel(selectedProject.type) }}
									</span>
									<span class="projects-home__badge projects-home__badge--muted">
										#{{ selectedProject.number || 'N/A' }}
									</span>
								</div>
							</div>
							<p class="projects-home__details-subtitle">
								{{ selectedProject.description || 'No description provided' }}
							</p>
						</div>
						<div class="projects-home__hero-actions">
							<NcButton
								v-if="selectedProject.talk_url"
								type="secondary"
								aria-label="Open chat"
								@click="openChat(selectedProject)">
								<template #icon>
									<Chat :size="16" />
								</template>
							</NcButton>
							<NcButton
								type="secondary"
								:disabled="exportRequesting"
								aria-label="Download project export"
								@click="requestProjectDownload">
								<template #icon>
									<Download :size="16" />
								</template>
								{{ exportRequesting ? 'Preparing...' : 'Download' }}
							</NcButton>
							<label v-if="canEditProjectStatus" class="projects-home__status-editor" for="projects-status-editor">
								<span class="projects-home__status-editor-label">Status</span>
								<select
									id="projects-status-editor"
									class="projects-home__filter-select projects-home__status-select"
									:value="String(selectedProjectStatusValue)"
									:disabled="statusUpdating"
									@change="updateSelectedProjectStatus($event.target.value)">
									<option
										v-for="option in projectStatusOptions"
										:key="option.value"
										:value="option.value">
										{{ option.label }}
									</option>
								</select>
							</label>
							<NcButton
								v-if="canDeleteSelectedProject"
								type="tertiary"
								:disabled="projectDeleting"
								aria-label="Delete project"
								class="projects-home__delete-button"
								@click="deleteSelectedProject">
								<template #icon>
									<Delete :size="16" />
								</template>
							</NcButton>
						</div>
					</div>
					<p v-if="canEditProjectStatus && statusUpdateError" class="projects-home__inline-error">
						{{ statusUpdateError }}
					</p>
					<p v-if="projectDeleteError" class="projects-home__inline-error">
						{{ projectDeleteError }}
					</p>
					<p v-if="exportQueuedMessage" class="projects-home__inline-success">
						{{ exportQueuedMessage }}
					</p>
				</header>

				<!-- Navigation Tabs -->
				<nav class="projects-home__tabs" role="tablist" aria-label="Project workspace">
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'overview' }"
						@click="setActiveTab('overview')">
						<ViewDashboard :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Overview</span>
					</button>
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'whiteboard' }"
						@click="setActiveTab('whiteboard')">
						<Draw :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Whiteboard</span>
					</button>
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'notes' }"
						@click="setActiveTab('notes')">
						<NoteText :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Notes</span>
					</button>
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'timeline' }"
						@click="setActiveTab('timeline')">
						<ChartGantt :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Timeline</span>
					</button>
					<button
						v-if="context?.features?.deck !== false"
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'deck' }"
						@click="setActiveTab('deck')">
						<ViewDashboard :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Deck</span>
					</button>
					<button
						v-if="isSelectedProjectCombi && context?.features?.deck !== false"
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'cardVisibility' }"
						@click="setActiveTab('cardVisibility')">
						<NoteText :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Intake formulier</span>
					</button>
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'files' }"
						@click="setActiveTab('files')">
						<FolderOpen :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Files</span>
					</button>
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'members' }"
						@click="setActiveTab('members')">
						<AccountMultiple :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Members</span>
						<span v-if="projectMembers.length > 0" class="projects-home__tab-badge">
							{{ projectMembers.length }}
						</span>
					</button>
					<button
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'activity' }"
						@click="setActiveTab('activity')">
						<History :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Activity</span>
					</button>
					<button
						v-if="context?.features?.calendar !== false"
						type="button"
						class="projects-home__tab"
						:class="{ 'projects-home__tab--active': activeTab === 'calendar' }"
						@click="setActiveTab('calendar')">
						<Calendar :size="16" class="projects-home__tab-icon" />
						<span class="projects-home__tab-label">Calendar</span>
					</button>
				</nav>

				<!-- Tab Content -->
				<div class="projects-home__tab-panel" role="tabpanel">
					<!-- Overview Tab - Combined: Project Address, Client Information, Notes, Timeline & Deck -->
					<div v-if="activeTab === 'overview'" class="projects-home__tab-section projects-home__tab-section--full">
						<div class="projects-home__overview">
							<!-- Location & Client Section -->
							<section class="projects-home__overview-section projects-home__overview-section--editable">
								<div class="projects-home__tab-section-header">
									<h3 class="projects-home__section-title">
										<MapMarker :size="20" />
										<Account :size="20" />
										Location & Client
									</h3>
									<NcButton
										v-if="canEditSelectedProjectDetails"
										type="tertiary-no-background"
										class="projects-home__section-edit-btn"
										aria-label="Edit location and client info"
										@click="startProjectProfileEdit('location')">
										<template #icon>
											<Pencil :size="16" />
										</template>
									</NcButton>
								</div>
								<div class="projects-home__unified-sheet">
									<div class="projects-home__sheet-col">
										<h4 class="projects-home__card-subtitle">
											Address
										</h4>
										<div class="projects-home__kv-list">
											<div class="projects-home__kv">
												<span class="projects-home__label">Street</span>
												<span class="projects-home__value">{{ selectedProject.loc_street || '-' }}</span>
											</div>
											<div class="projects-home__kv">
												<span class="projects-home__label">City</span>
												<span class="projects-home__value">{{ selectedProject.loc_city || '-' }}</span>
											</div>
											<div class="projects-home__kv">
												<span class="projects-home__label">ZIP Code</span>
												<span class="projects-home__value">{{ selectedProject.loc_zip || '-' }}</span>
											</div>
											<div class="projects-home__kv">
												<span class="projects-home__label">Full Address</span>
												<span class="projects-home__value">{{ selectedProject.client_address || '-' }}</span>
											</div>
										</div>
									</div>
									<div class="projects-home__sheet-divider" />
									<div class="projects-home__sheet-col">
										<h4 class="projects-home__card-subtitle">
											Client Contact
										</h4>
										<div class="projects-home__kv-list">
											<div class="projects-home__kv">
												<span class="projects-home__label">Client Name</span>
												<span class="projects-home__value">{{ selectedProject.client_name || '-' }}</span>
											</div>
											<div class="projects-home__kv">
												<span class="projects-home__label">Role / Title</span>
												<span class="projects-home__value">{{ formatClientRoles(selectedProject.client_role) }}</span>
											</div>
											<div class="projects-home__kv">
												<span class="projects-home__label">Phone</span>
												<span class="projects-home__value">{{ selectedProject.client_phone || '-' }}</span>
											</div>
											<div class="projects-home__kv">
												<span class="projects-home__label">Email</span>
												<span class="projects-home__value">{{ selectedProject.client_email || '-' }}</span>
											</div>
										</div>
										</div>
									</div>
									<ProjectLocationMap
										class="projects-home__location-map"
										:street="selectedProject.loc_street || ''"
										:city="selectedProject.loc_city || ''"
										:zip="selectedProject.loc_zip || ''" />
									<p v-if="projectProfileError" class="projects-home__inline-error projects-home__inline-error--left">
										{{ projectProfileError }}
									</p>
									<p v-if="projectProfileMessage" class="projects-home__inline-success">
										{{ projectProfileMessage }}
									</p>
							</section>

							<!-- Notes Section -->
							<section class="projects-home__overview-section">
								<div class="projects-home__tab-section-header">
									<h3 class="projects-home__section-title">
										<NoteText :size="20" />
										Notes
									</h3>
								</div>
								<ProjectNotesList
									:project-id="selectedProject.id"
									:talk-conversation-token="selectedProject.talk_conversation_token"
									:talk-url="selectedProject.talk_url" />
							</section>

							<!-- Timeline & Deck Section -->
							<section class="projects-home__overview-section">
								<div class="projects-home__tab-section-header">
									<h3 class="projects-home__section-title">
										<ChartGantt :size="20" />
										Timeline & Deck
									</h3>
								</div>
								<div class="projects-home__split-view">
									<div class="projects-home__split-panel projects-home__split-panel--timeline">
										<div class="projects-home__panel-header">
											<h3 class="projects-home__panel-title">
												<ChartGantt :size="18" />
												Timeline
											</h3>
										</div>
										<div class="projects-home__panel-content">
											<TimelineSummary :project-id="selectedProject.id" :can-edit="canEditPreparationWeeks" />
											<GanttChart :project-id="selectedProject.id" :is-admin="canManageTimelineItems" />
										</div>
									</div>
									<div class="projects-home__split-panel projects-home__split-panel--deck">
										<div class="projects-home__panel-header">
											<h3 class="projects-home__panel-title">
												<ViewDashboard :size="18" />
												Deck
											</h3>
											<NcButton
												type="tertiary"
												:disabled="!selectedProject.boardId"
												@click="openDeck(selectedProject)">
												<template #icon>
													<OpenInNew :size="14" />
												</template>
												Open
											</NcButton>
										</div>
										<div class="projects-home__panel-content">
											<h4 class="projects-home__panel-subtitle">
												Analytics
											</h4>
											<DeckAnalytics :board-id="selectedProject.boardId" />
											<h4 class="projects-home__panel-subtitle" style="margin-top: 16px;">
												Tasks
											</h4>
											<DeckBoard
												:board-id="selectedProject.boardId"
												:project-id="selectedProject.id"
												:organization-id="Number(selectedProject.organization_id) || Number(context?.organizationId) || null"
												:can-manage-profiles="canManageProjects" />
										</div>
									</div>
								</div>
							</section>

							<!-- Whiteboard Section -->
							<section class="projects-home__overview-section">
								<div class="projects-home__tab-section-header">
									<h3 class="projects-home__section-title">
										<Draw :size="20" />
										Whiteboard
									</h3>
								</div>
								<WhiteboardBoard
									ref="whiteboardBoardOverview"
									:key="String(selectedProject.id || '') + ':' + String(selectedProject.white_board_id || '')"
									:project-id="selectedProject.id"
									:user-id="context?.userId || ''" />
							</section>

							<!-- Files Section -->
							<section class="projects-home__overview-section">
								<div class="projects-home__tab-toolbar">
									<div class="projects-home__tab-toolbar-left">
										<h3 class="projects-home__section-title">
											<FolderOpen :size="20" />
											Project Files
										</h3>
										<p class="projects-home__section-subtitle">
											Manage project documents from here.
										</p>
									</div>
									<div class="projects-home__tab-toolbar-actions">
										<NcButton
											type="secondary"
											:disabled="!selectedProject.folderPath"
											@click.stop.prevent="downloadProject(selectedProject)">
											<template #icon>
												<Download :size="18" />
											</template>
											Download ZIP
										</NcButton>
									</div>
								</div>
								<ProjectFilesBrowser
									:project-id="Number(selectedProject.id) || null"
									:shared-roots="projectFiles.shared"
									:private-roots="projectFiles.private"
									:loading="filesLoading"
									:error="filesError"
									@refresh="refreshProjectFiles" />
							</section>

							<!-- Members Section -->
							<section class="projects-home__overview-section">
								<div class="projects-home__tab-section-header">
									<div>
										<h3 class="projects-home__section-title">
											<AccountMultiple :size="20" />
											Project Members
										</h3>
										<p class="projects-home__section-subtitle">
											DRASCIVS responsibilities and functional project roles are managed independently
										</p>
									</div>
								</div>

								<div v-if="canManageMembers" class="projects-home__member-invite-row">
									<NcSelect
										class="projects-home__member-invite-select"
										:model-value="memberInviteSelection"
										:options="memberInviteOptions"
										:loading="memberSearchLoading"
										:show-label="true"
										:multiple="false"
										:searchable="true"
										:clearable="true"
										input-label="Invite a member"
										placeholder="Search organization members"
										@search="searchMemberCandidates"
										@update:model-value="memberInviteSelection = $event" />
									<NcSelect
										class="projects-home__member-invite-role-select"
										:model-value="memberInviteRoles"
										:options="drasciRoleOptions"
										:show-label="true"
										:multiple="true"
										:searchable="false"
										:clearable="false"
										input-label="DRASCIVS roles"
										label="label"
										track-by="value"
										placeholder="Select roles"
										@update:model-value="memberInviteRoles = $event || []" />
									<NcSelect
										class="projects-home__member-invite-functional-select"
										:model-value="memberInviteFunctionalRoles"
										:options="functionalRoleOptions"
										:show-label="true"
										:multiple="true"
										:searchable="false"
										:clearable="false"
										input-label="Functional roles"
										label="label"
										track-by="value"
										placeholder="Select project roles"
										@update:model-value="memberInviteFunctionalRoles = $event || []" />
									<NcButton
										class="projects-home__member-invite-button"
										type="primary"
										:disabled="memberInviteLoading || !memberInviteSelection || memberInviteRoles.length === 0 || memberInviteFunctionalRoles.length === 0 || !selectedProject.id"
										@click="inviteSelectedMember">
										<template #icon>
											<Plus :size="16" />
										</template>
										{{ memberInviteLoading ? 'Inviting...' : 'Invite' }}
									</NcButton>
								</div>

								<p v-if="memberInviteMessage" class="projects-home__inline-success">
									{{ memberInviteMessage }}
								</p>
								<p v-if="membersError" class="projects-home__inline-error projects-home__inline-error--left">
									{{ membersError }}
								</p>

								<div v-if="membersLoading" class="projects-home__loading-state">
									<NcLoadingIcon :size="24" />
									<span>Loading members...</span>
								</div>
								<div v-else-if="projectMembers.length === 0" class="projects-home__empty-state">
									<AccountOff :size="32" />
									<p>No members found</p>
								</div>
								<ul v-else class="projects-home__members-list">
									<li v-for="member in projectMembers" :key="member.id" class="projects-home__member-item">
										<div class="projects-home__member-avatar">
											<span class="projects-home__avatar-placeholder">
												{{ memberInitials(member) }}
											</span>
										</div>
										<div class="projects-home__member-main">
											<span class="projects-home__member-name">{{ member.displayName || member.id }}</span>
											<span class="projects-home__member-meta">{{ member.id }}</span>
										</div>
										<div class="projects-home__member-role">
											<NcSelect
												:model-value="getDrasciRoleOptions(member.drascivsRoles || member.drasciRoles, member.drasciRole)"
												:options="drasciRoleOptions"
												:loading="memberRoleUpdating[member.id]"
												:show-label="false"
												:multiple="true"
												:searchable="false"
												:clearable="false"
												:disabled="memberRoleUpdating[member.id] || !canManageMembers"
												label="label"
												track-by="value"
												placeholder="Select roles"
												class="projects-home__member-role-select"
												@update:model-value="updateMemberDrasciRoles(member, $event)" />
										</div>
										<div class="projects-home__member-functional-role">
											<NcSelect
												:model-value="getFunctionalRoleOptions(member.functionalRoleKeys)"
												:options="functionalRoleOptions"
												:loading="memberRoleUpdating[member.id]"
												:show-label="false"
												:multiple="true"
												:searchable="false"
												:clearable="false"
												:disabled="memberRoleUpdating[member.id] || !canManageMembers"
												label="label"
												track-by="value"
												placeholder="Functional roles"
												class="projects-home__member-functional-role-select"
												@update:model-value="updateMemberFunctionalRoles(member, $event)" />
										</div>
										<div class="projects-home__member-badges">
											<span v-if="member.isOwner" class="projects-home__member-badge projects-home__member-badge--owner">Owner</span>
											<span v-if="member.email" class="projects-home__member-badge projects-home__member-badge--muted">{{ member.email }}</span>
										</div>
									</li>
								</ul>
							</section>

							<!-- Intake Formulier Section -->
							<section v-if="isSelectedProjectCombi" class="projects-home__overview-section">
								<div class="projects-home__tab-section-header">
									<h3 class="projects-home__section-title">
										<NoteText :size="20" />
										Intake Formulier
									</h3>
								</div>
								<ProjectCardVisibilityTab
									:project-id="selectedProject.id"
									:can-edit="canEditPreparationWeeks" />
							</section>
						</div>
					</div>

					<!-- Whiteboard Tab -->
					<div v-else-if="activeTab === 'whiteboard'" class="projects-home__tab-section projects-home__tab-section--full">
						<WhiteboardBoard
							ref="whiteboardBoard"
							:key="String(selectedProject.id || '') + ':' + String(selectedProject.white_board_id || '')"
							:project-id="selectedProject.id"
							:user-id="context?.userId || ''" />
					</div>

					<!-- Notes Tab -->
					<div v-else-if="activeTab === 'notes'" class="projects-home__tab-section projects-home__tab-section--full">
						<div class="projects-home__tab-section-header">
							<h3 class="projects-home__section-title">
								<NoteText :size="20" />
								Notes
							</h3>
						</div>
						<ProjectNotesList
							:project-id="selectedProject.id"
							:talk-conversation-token="selectedProject.talk_conversation_token"
							:talk-url="selectedProject.talk_url" />
					</div>

					<!-- Timeline Tab -->
					<div v-else-if="activeTab === 'timeline'" class="projects-home__tab-section projects-home__tab-section--full">
						<div class="projects-home__tab-section-header">
							<h3 class="projects-home__section-title">
								<ChartGantt :size="20" />
								Timeline
							</h3>
						</div>
						<div class="projects-home__split-panel projects-home__split-panel--timeline">
							<div class="projects-home__panel-content">
								<TimelineSummary :project-id="selectedProject.id" :can-edit="canEditPreparationWeeks" />
								<GanttChart :project-id="selectedProject.id" :is-admin="canManageTimelineItems" />
							</div>
						</div>
					</div>

					<!-- Deck Tab -->
					<div v-else-if="activeTab === 'deck'" class="projects-home__tab-section projects-home__tab-section--full">
						<div class="projects-home__tab-section-header">
							<h3 class="projects-home__section-title">
								<ViewDashboard :size="20" />
								Deck
							</h3>
							<NcButton
								type="tertiary"
								:disabled="!selectedProject.boardId"
								@click="openDeck(selectedProject)">
								<template #icon>
									<OpenInNew :size="14" />
								</template>
								Open
							</NcButton>
						</div>

						<div class="projects-home__deck-sections">
							<div class="projects-home__split-panel projects-home__split-panel--deck">
								<div class="projects-home__panel-header">
									<h3 class="projects-home__panel-title">
										<ViewDashboard :size="18" />
										Analytics
									</h3>
								</div>
								<div class="projects-home__panel-content">
									<DeckAnalytics :board-id="selectedProject.boardId" />
								</div>
							</div>

							<div class="projects-home__split-panel projects-home__split-panel--deck">
								<div class="projects-home__panel-header">
									<h3 class="projects-home__panel-title">
										<ViewDashboard :size="18" />
										Tasks
									</h3>
								</div>
								<div class="projects-home__panel-content">
									<DeckBoard
										:board-id="selectedProject.boardId"
										:project-id="selectedProject.id"
										:organization-id="Number(selectedProject.organization_id) || Number(context?.organizationId) || null"
										:can-manage-profiles="canManageProjects" />
								</div>
							</div>
						</div>
					</div>

					<!-- Card Visibility Tab -->
					<div v-else-if="activeTab === 'cardVisibility'" class="projects-home__tab-section projects-home__tab-section--full">
						<ProjectCardVisibilityTab
							:project-id="selectedProject.id"
							:can-edit="canEditPreparationWeeks" />
					</div>

					<!-- Files Tab -->
					<div v-else-if="activeTab === 'files'" class="projects-home__tab-section projects-home__tab-section--full">
						<div class="projects-home__tab-toolbar">
							<div class="projects-home__tab-toolbar-left">
								<h3 class="projects-home__section-title">
									<FolderOpen :size="20" />
									Project Files
								</h3>
								<p class="projects-home__section-subtitle">
									Manage project documents from here.
								</p>
							</div>
							<div class="projects-home__tab-toolbar-actions">
								<NcButton
									type="secondary"
									:disabled="!selectedProject.folderPath"
									@click.stop.prevent="downloadProject(selectedProject)">
									<template #icon>
										<Download :size="18" />
									</template>
									Download ZIP
								</NcButton>
							</div>
						</div>
						<ProjectFilesBrowser
							:project-id="Number(selectedProject.id) || null"
							:shared-roots="projectFiles.shared"
							:private-roots="projectFiles.private"
							:loading="filesLoading"
							:error="filesError"
							@refresh="refreshProjectFiles" />
					</div>

					<!-- Members Tab -->
					<div v-else-if="activeTab === 'members'" class="projects-home__tab-section">
						<div class="projects-home__tab-section-header">
							<div>
								<h3 class="projects-home__section-title">
									<AccountMultiple :size="20" />
									Project Members
								</h3>
								<p class="projects-home__section-subtitle">
									DRASCIVS responsibilities and functional project roles are managed independently
								</p>
							</div>
						</div>

						<div v-if="canManageMembers" class="projects-home__member-invite-row">
							<NcSelect
								class="projects-home__member-invite-select"
								:model-value="memberInviteSelection"
								:options="memberInviteOptions"
								:loading="memberSearchLoading"
								:show-label="true"
								:multiple="false"
								:searchable="true"
								:clearable="true"
								input-label="Invite a member"
								placeholder="Search organization members"
								@search="searchMemberCandidates"
								@update:model-value="memberInviteSelection = $event" />
							<NcSelect
								class="projects-home__member-invite-role-select"
								:model-value="memberInviteRoles"
								:options="drasciRoleOptions"
								:show-label="true"
								:multiple="true"
								:searchable="false"
								:clearable="false"
								input-label="DRASCIVS roles"
								label="label"
								track-by="value"
								placeholder="Select roles"
								@update:model-value="memberInviteRoles = $event || []" />
							<NcSelect
								class="projects-home__member-invite-functional-select"
								:model-value="memberInviteFunctionalRoles"
								:options="functionalRoleOptions"
								:show-label="true"
								:multiple="true"
								:searchable="false"
								:clearable="false"
								input-label="Functional roles"
								label="label"
								track-by="value"
								placeholder="Select project roles"
								@update:model-value="memberInviteFunctionalRoles = $event || []" />
							<NcButton
								class="projects-home__member-invite-button"
								type="primary"
								:disabled="memberInviteLoading || !memberInviteSelection || memberInviteRoles.length === 0 || memberInviteFunctionalRoles.length === 0 || !selectedProject.id"
								@click="inviteSelectedMember">
								<template #icon>
									<Plus :size="16" />
								</template>
								{{ memberInviteLoading ? 'Inviting...' : 'Invite' }}
							</NcButton>
						</div>

						<p v-if="memberInviteMessage" class="projects-home__inline-success">
							{{ memberInviteMessage }}
						</p>
						<p v-if="membersError" class="projects-home__inline-error projects-home__inline-error--left">
							{{ membersError }}
						</p>

						<div v-if="membersLoading" class="projects-home__loading-state">
							<NcLoadingIcon :size="24" />
							<span>Loading members...</span>
						</div>
						<div v-else-if="projectMembers.length === 0" class="projects-home__empty-state">
							<AccountOff :size="32" />
							<p>No members found</p>
						</div>
						<ul v-else class="projects-home__members-list">
							<li v-for="member in projectMembers" :key="member.id" class="projects-home__member-item">
								<div class="projects-home__member-avatar">
									<span class="projects-home__avatar-placeholder">
										{{ memberInitials(member) }}
									</span>
								</div>
								<div class="projects-home__member-main">
									<span class="projects-home__member-name">{{ member.displayName || member.id }}</span>
									<span class="projects-home__member-meta">{{ member.id }}</span>
								</div>
								<div class="projects-home__member-role">
									<NcSelect
										:model-value="getDrasciRoleOptions(member.drascivsRoles || member.drasciRoles, member.drasciRole)"
										:options="drasciRoleOptions"
										:loading="memberRoleUpdating[member.id]"
										:show-label="false"
										:multiple="true"
										:searchable="false"
										:clearable="false"
										:disabled="memberRoleUpdating[member.id] || !canManageMembers"
										label="label"
										track-by="value"
										placeholder="Select roles"
										class="projects-home__member-role-select"
										@update:model-value="updateMemberDrasciRoles(member, $event)" />
								</div>
								<div class="projects-home__member-functional-role">
									<NcSelect
										:model-value="getFunctionalRoleOptions(member.functionalRoleKeys)"
										:options="functionalRoleOptions"
										:loading="memberRoleUpdating[member.id]"
										:show-label="false"
										:multiple="true"
										:searchable="false"
										:clearable="false"
										:disabled="memberRoleUpdating[member.id] || !canManageMembers"
										label="label"
										track-by="value"
										placeholder="Functional roles"
										class="projects-home__member-functional-role-select"
										@update:model-value="updateMemberFunctionalRoles(member, $event)" />
								</div>
								<div class="projects-home__member-badges">
									<span v-if="member.isOwner" class="projects-home__member-badge projects-home__member-badge--owner">Owner</span>
									<span v-if="member.email" class="projects-home__member-badge projects-home__member-badge--muted">{{ member.email }}</span>
								</div>
							</li>
						</ul>
					</div>
					<div v-else-if="activeTab === 'activity'" class="projects-home__tab-section">
						<ProjectActivity :key="String(selectedProject?.id || '')" :project-id="selectedProject?.id || null" />
					</div>
					<div v-else-if="activeTab === 'calendar'" class="projects-home__tab-section projects-home__tab-section--full">
						<ProjectCalendar :project-id="selectedProject?.id || null" />
					</div>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent
				v-else
				class="projects-home__empty-main"
				name="Select a project"
				description="Choose a project from the sidebar to view its details and manage resources.">
				<template #icon>
					<FolderOpen :size="48" />
				</template>
			</NcEmptyContent>
		</main>

		<!-- Create Project Modal -->
		<NcModal :show="showCreateModal" size="large" @close="closeCreateModal">
			<ProjectCreator embedded @created="handleProjectCreated" @cancel="closeCreateModal" />
		</NcModal>

		<!-- Edit Profile Modal -->
		<NcModal :show="showProjectProfileModal" size="normal" @close="cancelProjectProfileEdit">
			<div class="projects-home__profile-modal">
				<h3 class="projects-home__profile-modal-title">
					Edit Project Details
				</h3>
				<p class="projects-home__profile-modal-subtitle">
					Update project name, client information, and project location in separate sections.
				</p>

				<div
					v-if="projectProfileSection"
					class="projects-home__profile-nav"
					role="tablist"
					aria-label="Project detail sections">
					<button
						v-if="canEditProjectTitle"
						ref="projectProfileProjectTab"
						type="button"
						role="tab"
						:aria-selected="projectProfileSection === 'project'"
						class="projects-home__profile-nav-btn"
						:class="{ 'projects-home__profile-nav-btn--active': projectProfileSection === 'project' }"
						@click="projectProfileSection = 'project'">
						Project Name
					</button>
					<button
						ref="projectProfileClientTab"
						type="button"
						role="tab"
						:aria-selected="projectProfileSection === 'client'"
						class="projects-home__profile-nav-btn"
						:class="{ 'projects-home__profile-nav-btn--active': projectProfileSection === 'client' }"
						@click="projectProfileSection = 'client'">
						Client Info
					</button>
					<button
						ref="projectProfileLocationTab"
						type="button"
						role="tab"
						:aria-selected="projectProfileSection === 'location'"
						class="projects-home__profile-nav-btn"
						:class="{ 'projects-home__profile-nav-btn--active': projectProfileSection === 'location' }"
						@click="projectProfileSection = 'location'">
						Location Info
					</button>
				</div>

				<div v-if="projectProfileSection" class="projects-home__profile-sections">
					<div v-if="canEditProjectTitle && projectProfileSection === 'project'" class="projects-home__profile-section">
						<h4 class="projects-home__profile-section-title">
							Project Name
						</h4>
						<div class="projects-home__profile-grid projects-home__profile-grid--single">
							<NcTextField
								v-model="projectProfileDraft.name"
								label="Project name"
								:show-label="true"
								input-label="Project name"
								placeholder="e.g., Riverside Renovation" />
							<NcTextField
								v-model="projectProfileDraft.description"
								label="Description"
								:show-label="true"
								input-label="Description"
								type="textarea"
								placeholder="Brief description of the project..." />
						</div>
					</div>

					<div v-else-if="projectProfileSection === 'client'" class="projects-home__profile-section">
						<h4 class="projects-home__profile-section-title">
							Client Information
						</h4>
						<div class="projects-home__profile-grid">
							<NcTextField
								v-model="projectProfileDraft.client_name"
								label="Client name"
								:show-label="true"
								input-label="Client name"
								placeholder="e.g., Acme Corporation" />
							<NcSelect
								v-model="selectedProfileClientRoles"
								:options="CLIENT_ROLE_OPTIONS"
								label="label"
								track-by="value"
								:multiple="true"
								:close-on-select="false"
								:show-label="true"
								input-label="Client Roles"
								placeholder="Select client roles" />
							<NcTextField
								v-model="projectProfileDraft.client_phone"
								label="Phone number"
								:show-label="true"
								input-label="Phone number"
								placeholder="e.g., +1 555-0123" />
							<NcTextField
								v-model="projectProfileDraft.client_email"
								label="Email address"
								:show-label="true"
								input-label="Email address"
								placeholder="e.g., contact@example.com" />
							<NcTextField
								v-model="projectProfileDraft.client_address"
								label="Client address"
								:show-label="true"
								input-label="Client address"
								placeholder="Complete mailing address" />
						</div>
					</div>

					<div v-else class="projects-home__profile-section">
						<h4 class="projects-home__profile-section-title">
							Project Location
						</h4>
						<div class="projects-home__profile-grid projects-home__profile-grid--single">
							<NcTextField
								v-model="projectProfileDraft.loc_street"
								label="Street address"
								:show-label="true"
								input-label="Street address"
								placeholder="e.g., 123 Main Street" />
							<NcTextField
								v-model="projectProfileDraft.loc_city"
								label="City"
								:show-label="true"
								input-label="City"
								placeholder="e.g., New York" />
							<NcTextField
								v-model="projectProfileDraft.loc_zip"
								label="ZIP / Postal code"
								:show-label="true"
								input-label="ZIP / Postal code"
								placeholder="e.g., 10001" />
						</div>
					</div>
				</div>

				<p v-if="projectProfileError" class="projects-home__inline-error projects-home__inline-error--left">
					{{ projectProfileError }}
				</p>

				<div class="projects-home__profile-actions">
					<NcButton
						type="secondary"
						:disabled="projectProfileSaving"
						@click="cancelProjectProfileEdit">
						Cancel
					</NcButton>
					<NcButton
						type="primary"
						:disabled="projectProfileSaving"
						@click="saveProjectProfile">
						{{ projectProfileSaving ? 'Saving...' : 'Save changes' }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Floating Scroll to Top Button -->
		<ScrollToTopButton
			:target="getMainContainer"
			:enabled="canShowScrollTop" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import Account from 'vue-material-design-icons/Account.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountOff from 'vue-material-design-icons/AccountOff.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ChartGantt from 'vue-material-design-icons/ChartGantt.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import Draw from 'vue-material-design-icons/Draw.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FilterRemove from 'vue-material-design-icons/FilterRemove.vue'
import History from 'vue-material-design-icons/History.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import MenuClose from 'vue-material-design-icons/MenuClose.vue'
import MenuOpen from 'vue-material-design-icons/MenuOpen.vue'
import NoteText from 'vue-material-design-icons/NoteText.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'

import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { createClient } from 'webdav'

import { PROJECT_TYPES } from '../macros/project-types.js'
import {
	CLIENT_ROLE_OPTIONS,
	formatClientRoles,
	getClientRoleOptions,
	getClientRoleValues,
	normalizeClientRoles,
} from '../macros/client-roles.js'
import {
	PROJECT_STATUS_FILTER_OPTIONS,
	PROJECT_STATUS_OPTIONS,
	getProjectStatusBadgeClass,
	getProjectStatusLabel,
	getProjectStatusPillClass,
	getProjectStatusShortLabel,
	matchesProjectStatusFilter,
	normalizeProjectStatus,
} from '../constants/project-statuses.js'
import { ProjectsService } from '../Services/projects.js'

import DeckAnalytics from './ProjectDeck/DeckAnalytics.vue'
import DeckBoard from './ProjectDeck/DeckBoard.vue'
import GanttChart from './ProjectTimeline/GanttChart.vue'
import TimelineSummary from './ProjectTimeline/TimelineSummary.vue'
import ProjectFilesBrowser from './ProjectFiles/ProjectFilesBrowser.vue'
import WhiteboardBoard from './ProjectWhiteboard/WhiteboardBoard.vue'
import ProjectCreator from './ProjectCreator.vue'
import ProjectLocationMap from './ProjectLocationMap.vue'
import ProjectNotesList from './ProjectNotesList.vue'
import ProjectCardVisibilityTab from './ProjectCardVisibilityTab.vue'
import ProjectActivity from './ProjectActivity/ProjectActivity.vue'
import ProjectCalendar from './ProjectCalendar.vue'
import ScrollToTopButton from './ScrollToTopButton.vue'

const projectsService = ProjectsService.getInstance()
const webdavClient = createClient(generateRemoteUrl('dav'))
const PROJECT_TABS = ['overview', 'whiteboard', 'notes', 'timeline', 'deck', 'cardVisibility', 'files', 'members', 'activity', 'calendar']

export default {
	name: 'ProjectsHome',
	components: {
		Account,
		AccountMultiple,
		AccountOff,
		AlertCircle,
		ChartGantt,
		Chat,
		ChevronLeft,
		DeckAnalytics,
		DeckBoard,
		Delete,
		Draw,
		Download,
		FilterRemove,
		FolderOpen,
		FolderOutline,
		GanttChart,
		TimelineSummary,
		Magnify,
		MapMarker,
		MenuClose,
		MenuOpen,
		NcButton,
		NcEmptyContent,
		NcTextField,
		NcSelect,
		NcModal,
		NcLoadingIcon,
		NoteText,
		OfficeBuilding,
		OpenInNew,
		Pencil,
		Plus,
		ProjectFilesBrowser,
		WhiteboardBoard,
		ProjectCreator,
		ProjectLocationMap,
		ProjectNotesList,
		ProjectCardVisibilityTab,
		ProjectActivity,
		History,
		ViewDashboard,
		Calendar,
		ProjectCalendar,
		ScrollToTopButton,
	},
	data() {
		return {
			context: null,
			contextError: '',
			filesError: '',
			filesLoading: false,
			statusUpdating: false,
			statusUpdateError: '',
			projectDeleting: false,
			projectDeleteError: '',
			showCreateModal: false,
			activeTab: 'overview',
			sortKey: 'default',
			isNarrow: false,
			mobilePane: 'list',
			isSidebarCollapsed: false,
			loadedMembersProjectId: null,
			loadedFilesProjectId: null,
			loading: false,
			projectFiles: {
				private: [],
				shared: [],
			},
			projectScope: 'all',
			projects: [],
			searchQuery: '',
			selectedProject: null,
			selectedProjectId: null,
			statusFilter: 'all',
			projectMembers: [],
			membersLoading: false,
			membersError: '',
			memberInviteSelection: null,
			memberInviteOptions: [],
			memberSearchLoading: false,
			memberInviteLoading: false,
			memberInviteMessage: '',
			memberSearchTimeout: null,
			memberInviteRoles: [],
			memberInviteFunctionalRoles: [],
			memberRoleUpdating: {},
			functionalRoleOptions: [],
			CLIENT_ROLE_OPTIONS,
			drasciRoleOptions: [
				{ value: 'driver', label: 'Driver' },
				{ value: 'responsible', label: 'Responsible' },
				{ value: 'accountable', label: 'Accountable' },
				{ value: 'supportive', label: 'Supportive' },
				{ value: 'consulted', label: 'Consulted' },
				{ value: 'informed', label: 'Informed' },
				{ value: 'verifier', label: 'Verifier' },
				{ value: 'signer', label: 'Signer' },
			],
			showProjectProfileModal: false,
			exportRequesting: false,
			exportQueuedMessage: '',
			projectProfileDraft: {
				name: '',
				description: '',
				client_name: '',
				client_role: [],
				client_phone: '',
				client_email: '',
				client_address: '',
				loc_street: '',
				loc_city: '',
				loc_zip: '',
			},
			projectProfileSection: null,
			projectProfileSaving: false,
			projectProfileError: '',
			projectProfileMessage: '',
		}
	},
	computed: {
		selectedProfileClientRoles: {
			get() {
				return getClientRoleOptions(this.projectProfileDraft.client_role)
			},
			set(options) {
				this.projectProfileDraft.client_role = getClientRoleValues(options)
			},
		},
		hasProjectAccess() {
			if (this.context === null) {
				return true
			}
			return this.context.isGlobalAdmin || this.context.organizationId !== null
		},
		isOrganizationAdmin() {
			return this.context?.organizationRole === 'admin' && !this.context?.isGlobalAdmin
		},
		canManageProjects() {
			return !!(this.context?.isGlobalAdmin || this.context?.organizationRole === 'admin')
		},
		canEditProjectStatus() {
			if (this.canManageProjects) {
				return true
			}
			const ownerId = String(this.selectedProject?.ownerId || '').trim()
			const currentUserId = String(this.context?.userId || '').trim()
			return ownerId !== '' && currentUserId !== '' && ownerId === currentUserId
		},
		canManageMembers() {
			return this.canEditProjectStatus
		},
		projectStatusOptions() {
			return PROJECT_STATUS_OPTIONS
		},
		projectStatusFilterOptions() {
			return PROJECT_STATUS_FILTER_OPTIONS
		},
		selectedProjectStatusValue() {
			return normalizeProjectStatus(this.selectedProject?.status)
		},
		canManageTimelineItems() {
			return this.hasProjectAccess && !!this.selectedProject
		},
		canEditPreparationWeeks() {
			if (this.canManageProjects) {
				return true
			}
			const ownerId = String(this.selectedProject?.ownerId || '').trim()
			const currentUserId = String(this.context?.userId || '').trim()
			return ownerId !== '' && currentUserId !== '' && ownerId === currentUserId
		},
		canEditProjectTitle() {
			if (this.canManageProjects) {
				return true
			}
			const ownerId = String(this.selectedProject?.ownerId || '').trim()
			const currentUserId = String(this.context?.userId || '').trim()
			return ownerId !== '' && currentUserId !== '' && ownerId === currentUserId
		},
		canDeleteSelectedProject() {
			if (!this.selectedProject) {
				return false
			}
			if (this.canManageProjects) {
				return true
			}
			const ownerId = String(this.selectedProject?.ownerId || '').trim()
			const currentUserId = String(this.context?.userId || '').trim()
			return ownerId !== '' && currentUserId !== '' && ownerId === currentUserId
		},
		canEditSelectedProjectDetails() {
			return this.hasProjectAccess && !!this.selectedProject
		},
		isSelectedProjectCombi() {
			return Number(this.selectedProject?.type) === 0
		},
		scopeLabel() {
			if (this.context?.isGlobalAdmin) {
				return 'Global admin view'
			}
			if (this.context?.organizationRole === 'admin') {
				return 'Organization admin view'
			}
			return 'My projects view'
		},
		filteredProjects() {
			const search = this.searchQuery.trim().toLowerCase()
			return this.projects.filter((project) => {
				if (!matchesProjectStatusFilter(this.statusFilter, project.status)) {
					return false
				}
				if (search === '') {
					return true
				}
				const name = (project.name || '').toLowerCase()
				const number = (project.number || '').toLowerCase()
				return name.includes(search) || number.includes(search)
			})
		},
		visibleProjects() {
			const list = (this.filteredProjects || []).slice()
			if (this.sortKey === 'name') {
				return list.sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }))
			}
			if (this.sortKey === 'number') {
				return list.sort((a, b) => String(a.number || '').localeCompare(String(b.number || ''), undefined, { sensitivity: 'base' }))
			}
			return list
		},
		canClearFilters() {
			return this.searchQuery.trim() !== '' || this.statusFilter !== 'all' || this.sortKey !== 'default'
		},
		canShowScrollTop() {
			return Boolean(this.selectedProject) && (!this.isNarrow || this.mobilePane === 'details')
		},
	},
	async mounted() {
		this.updateNarrowState()
		window.addEventListener('resize', this.updateNarrowState)
		window.addEventListener('popstate', this.handlePopState)

		await this.loadContext()
		if (!this.hasProjectAccess) {
			return
		}

		if (this.context?.organizationRole === 'member') {
			this.projectScope = 'my'
		}
		await this.loadProjects()

		await this.handleUrlNavigation()
	},
	beforeDestroy() {
		window.removeEventListener('resize', this.updateNarrowState)
		window.removeEventListener('popstate', this.handlePopState)
	},
	methods: {
		formatClientRoles,
		getMainContainer() {
			return this.$refs.mainContainer
		},
		// Sidebar collapse toggle
		toggleSidebar() {
			this.isSidebarCollapsed = !this.isSidebarCollapsed
		},
		// Get project initials for collapsed sidebar
		projectInitials(project) {
			const name = project.name || ''
			return name
				.split(' ')
				.map(word => word.charAt(0).toUpperCase())
				.slice(0, 2)
				.join('')
		},
		// Get member initials
		memberInitials(member) {
			const name = member.displayName || member.id || ''
			return name
				.split(' ')
				.map(word => word.charAt(0).toUpperCase())
				.slice(0, 2)
				.join('')
		},
		// Short status label for sidebar
		statusLabelShort(status) {
			return getProjectStatusShortLabel(status)
		},
		// Short type label
		typeLabelShort(typeId) {
			const match = PROJECT_TYPES.find((type) => type.id === typeId)
			if (!match) return 'Unknown'
			// Return abbreviated version
			const abbreviations = {
				Construction: 'Const.',
				Renovation: 'Reno.',
				Consulting: 'Consult.',
			}
			return abbreviations[match.label] || match.label
		},
		// Status badge class
		statusBadgeClass(status) {
			return getProjectStatusBadgeClass(status)
		},
		getProjectProfileDraftFromSelected() {
			const selected = this.selectedProject || {}
			return {
				name: selected.name || '',
				description: selected.description || '',
				client_name: selected.client_name || '',
				client_role: normalizeClientRoles(selected.client_role),
				client_phone: selected.client_phone || '',
				client_email: selected.client_email || '',
				client_address: selected.client_address || '',
				loc_street: selected.loc_street || '',
				loc_city: selected.loc_city || '',
				loc_zip: selected.loc_zip || '',
			}
		},
		resolveProjectProfileSection(section = null) {
			const requested = typeof section === 'string' ? section.trim() : ''
			const allowed = ['project', 'client', 'location']
			const defaultSection = this.canEditProjectTitle ? 'project' : 'client'
			const normalized = allowed.includes(requested) ? requested : defaultSection

			if (normalized === 'project' && !this.canEditProjectTitle) {
				return 'client'
			}

			return normalized
		},
		resetProjectProfileEditor(clearMessage = true) {
			this.showProjectProfileModal = false
			this.projectProfileSaving = false
			this.projectProfileError = ''
			if (clearMessage) {
				this.projectProfileMessage = ''
			}
			this.projectProfileDraft = {
				name: '',
				client_name: '',
				client_role: [],
				client_phone: '',
				client_email: '',
				client_address: '',
				loc_street: '',
				loc_city: '',
				loc_zip: '',
			}
			this.projectProfileSection = null
		},
		startProjectProfileEdit(section = null) {
			if (!this.canEditSelectedProjectDetails) {
				return
			}
			this.projectProfileError = ''
			this.projectProfileMessage = ''
			this.projectProfileDraft = this.getProjectProfileDraftFromSelected()
			this.projectProfileSection = this.resolveProjectProfileSection(section)
			this.showProjectProfileModal = true
			this.$nextTick(() => {
				window.requestAnimationFrame(() => {
					this.focusActiveProjectProfileTab()
				})
			})
		},
		focusActiveProjectProfileTab() {
			const tabRefs = {
				project: 'projectProfileProjectTab',
				client: 'projectProfileClientTab',
				location: 'projectProfileLocationTab',
			}

			const tabRef = tabRefs[this.projectProfileSection]
			const tab = tabRef ? this.$refs[tabRef] : null
			if (tab && typeof tab.focus === 'function') {
				tab.focus({ preventScroll: true })
			}
		},
		cancelProjectProfileEdit() {
			this.projectProfileError = ''
			this.showProjectProfileModal = false
			this.projectProfileSection = null
		},
		async saveProjectProfile() {
			if (!this.selectedProject) {
				return
			}
			const projectId = Number(this.selectedProject.id)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			this.projectProfileSaving = true
			this.projectProfileError = ''
			this.projectProfileMessage = ''
			const payload = {
				client_name: this.projectProfileDraft.client_name,
				client_role: this.projectProfileDraft.client_role,
				client_phone: this.projectProfileDraft.client_phone,
				client_email: this.projectProfileDraft.client_email,
				client_address: this.projectProfileDraft.client_address,
				loc_street: this.projectProfileDraft.loc_street,
				loc_city: this.projectProfileDraft.loc_city,
				loc_zip: this.projectProfileDraft.loc_zip,
			}
			if (this.canEditProjectTitle) {
				payload.name = this.projectProfileDraft.name
				payload.description = this.projectProfileDraft.description
			}
			try {
				const updated = await projectsService.update(projectId, payload)
				if (updated && typeof updated === 'object') {
					this.selectedProject = {
						...this.selectedProject,
						...updated,
					}
				} else {
					this.selectedProject = {
						...this.selectedProject,
						...payload,
					}
				}
				const projectIndex = this.projects.findIndex((project) => Number(project.id) === projectId)
				if (projectIndex !== -1) {
					this.projects.splice(projectIndex, 1, {
						...this.projects[projectIndex],
						...this.selectedProject,
					})
				}
				this.projectProfileDraft = this.getProjectProfileDraftFromSelected()
				this.showProjectProfileModal = false
				this.projectProfileSection = null
				this.projectProfileMessage = 'Project details updated successfully.'
			} catch (error) {
				this.projectProfileError = error?.response?.data?.message || 'Could not update project details.'
			} finally {
				this.projectProfileSaving = false
			}
		},
		updateNarrowState() {
			this.isNarrow = window.matchMedia ? window.matchMedia('(max-width: 900px)').matches : (window.innerWidth <= 900)
			if (!this.isNarrow) {
				this.mobilePane = 'list'
				return
			}
			this.mobilePane = this.selectedProjectId ? 'details' : 'list'
		},
		clearFilters() {
			this.searchQuery = ''
			this.statusFilter = 'all'
			this.sortKey = 'default'
		},
		statusPillClass(status) {
			return getProjectStatusPillClass(status)
		},
		setActiveTab(tab) {
			const normalized = this.normalizeTab(tab)
			this.activeTab = normalized
			if (!this.selectedProjectId) {
				this.syncUrl(null, null, true)
				return
			}
			const projectId = Number(this.selectedProjectId)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			this.applyTabSideEffects(projectId, normalized)
			this.syncUrl(projectId, normalized, false)
		},
		async ensureMembersLoaded(projectId) {
			if (this.loadedMembersProjectId === projectId && !this.membersError) {
				return
			}
			await this.loadProjectMembers(projectId)
			this.loadedMembersProjectId = projectId
		},
		async ensureFilesLoaded(projectId) {
			if (this.loadedFilesProjectId === projectId && !this.filesError) {
				return
			}
			await this.loadProjectFiles(projectId)
			this.loadedFilesProjectId = projectId
		},
		async updateSelectedProjectStatus(nextStatusRaw) {
			if (!this.selectedProject) {
				return
			}
			const projectId = Number(this.selectedProject.id)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			const nextStatus = normalizeProjectStatus(nextStatusRaw)
			if (normalizeProjectStatus(this.selectedProject.status) === nextStatus) {
				return
			}
			this.statusUpdateError = ''
			this.statusUpdating = true
			try {
				const updated = await projectsService.update(projectId, { status: nextStatus })
				const resolvedStatus = normalizeProjectStatus(updated?.status ?? nextStatus)
				this.selectedProject = {
					...this.selectedProject,
					...(updated && typeof updated === 'object' ? updated : {}),
					status: resolvedStatus,
				}
				const projectIndex = this.projects.findIndex((project) => Number(project.id) === projectId)
				if (projectIndex !== -1) {
					this.projects.splice(projectIndex, 1, {
						...this.projects[projectIndex],
						...this.selectedProject,
					})
				}
			} catch (error) {
				this.statusUpdateError = error?.response?.data?.message || 'Could not update project status.'
			} finally {
				this.statusUpdating = false
			}
		},
		async deleteSelectedProject() {
			if (!this.selectedProject || this.projectDeleting || !this.canDeleteSelectedProject) {
				return
			}

			const projectId = Number(this.selectedProject.id)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}

			const projectName = String(this.selectedProject.name || 'this project').trim() || 'this project'
			const confirmed = window.confirm(`Delete "${projectName}"? This also removes its shared files, private folders, board, notes, timeline, and membership group.`)
			if (!confirmed) {
				return
			}

			this.projectDeleting = true
			this.projectDeleteError = ''

			try {
				await projectsService.delete(projectId)
				this.clearSelectedProject()
				this.syncUrl(null, null, true)
				await this.loadProjects()
			} catch (error) {
				this.projectDeleteError = error?.response?.data?.message || 'Could not delete project.'
			} finally {
				this.projectDeleting = false
			}
		},
		async loadContext() {
			this.contextError = ''
			try {
				this.context = await projectsService.context()
			} catch (error) {
				this.context = null
				this.contextError = error?.response?.data?.message || 'Unable to load project context.'
			}
		},
		async loadProjects() {
			this.loading = true
			try {
				const userId = this.context?.userId || null
				if (this.isOrganizationAdmin && this.projectScope === 'my' && userId) {
					this.projects = await projectsService.fetchProjectsByUser(userId)
				} else {
					this.projects = await projectsService.list()
				}
			} finally {
				this.loading = false
			}
		},
		async selectProject(project, options = {}) {
			const tab = this.normalizeTab(options.tab || 'overview')
			const updateUrl = options.updateUrl !== false
			const fetched = await projectsService.get(project.id)
			this.activeTab = tab
			this.statusUpdateError = ''
			this.projectDeleteError = ''
			this.exportQueuedMessage = ''
			this.memberInviteMessage = ''
			this.membersError = ''
			this.resetProjectProfileEditor()
			this.resetMembersState()
			this.resetFilesState()
			this.selectedProjectId = fetched.id
			this.selectedProject = fetched
			this.projectProfileDraft = this.getProjectProfileDraftFromSelected()
			this.loadedMembersProjectId = null
			this.loadedFilesProjectId = null
			if (this.isNarrow) {
				this.mobilePane = 'details'
			}
			this.applyTabSideEffects(fetched.id, tab)
			if (this.$refs.mainContainer) {
				this.$refs.mainContainer.scrollTop = 0
			}
			if (updateUrl) {
				this.syncUrl(fetched.id, tab, false)
			}
		},
		startCreateProject() {
			this.showCreateModal = true
		},
		closeCreateModal() {
			this.showCreateModal = false
		},
		async handleProjectCreated(payload) {
			const createdProjectId = payload?.projectId ?? null
			await this.loadProjects()
			this.showCreateModal = false
			if (createdProjectId === null) {
				return
			}
			const createdProject = this.projects.find((project) => project.id === createdProjectId)
			if (createdProject) {
				await this.selectProject(createdProject)
			}
		},
		getUrlBase() {
			const path = window.location.pathname.replace(/\/+$/, '')
			return path.replace(/\/\d+$/, '')
		},
		getUrlProjectId() {
			const match = window.location.pathname.match(/\/(\d+)\/?$/)
			if (match) {
				const id = parseInt(match[1], 10)
				return Number.isFinite(id) && id > 0 ? id : null
			}
			return null
		},
		normalizeTab(tab) {
			return PROJECT_TABS.includes(tab) ? tab : 'overview'
		},
		applyTabSideEffects(projectId, tab) {
			if (tab === 'members' || tab === 'overview') {
				this.ensureMembersLoaded(projectId)
			}
			if (tab === 'files' || tab === 'overview') {
				this.ensureFilesLoaded(projectId)
			}
		},
		clearSelectedProject() {
			this.resetProjectProfileEditor()
			this.resetMembersState()
			this.resetFilesState()
			this.exportQueuedMessage = ''
			this.selectedProject = null
			this.selectedProjectId = null
			this.activeTab = 'overview'
			this.loadedMembersProjectId = null
			this.loadedFilesProjectId = null
			if (this.isNarrow) {
				this.mobilePane = 'list'
			}
		},
		getUrlTab() {
			const params = new URLSearchParams(window.location.search || '')
			return this.normalizeTab(params.get('tab'))
		},
		syncUrl(projectId, tab, replace = false) {
			const base = this.getUrlBase()
			let url = base
			if (projectId) {
				url += '/' + projectId
			}
			if (tab && tab !== 'overview') {
				url += '?tab=' + tab
			}
			if (replace) {
				history.replaceState(null, '', url)
			} else {
				history.pushState(null, '', url)
			}
		},
		async handlePopState() {
			const urlProjectId = this.getUrlProjectId()
			const urlTab = this.getUrlTab()

			if (!urlProjectId) {
				this.clearSelectedProject()
				this.syncUrl(null, null, true)
				return
			}

			try {
				const project = this.projects.find(p => Number(p.id) === urlProjectId)
				if (project) {
					await this.selectProject(project, { tab: urlTab, updateUrl: false })
				} else {
					await this.selectProject({ id: urlProjectId }, { tab: urlTab, updateUrl: false })
				}
			} catch (e) {
				this.clearSelectedProject()
				this.syncUrl(null, null, true)
			}
		},
		async handleUrlNavigation() {
			const urlProjectId = this.getUrlProjectId()
			const urlTab = this.getUrlTab()
			if (!urlProjectId) {
				return
			}

			try {
				const project = this.projects.find(p => Number(p.id) === urlProjectId)
				if (project) {
					await this.selectProject(project, { tab: urlTab, updateUrl: false })
				} else {
					await this.selectProject({ id: urlProjectId }, { tab: urlTab, updateUrl: false })
				}

				this.syncUrl(urlProjectId, urlTab, true)
			} catch (e) {
				this.clearSelectedProject()
				this.syncUrl(null, null, true)
			}
		},
		typeLabel(typeId) {
			const match = PROJECT_TYPES.find((type) => type.id === typeId)
			return match ? match.label : 'Unknown'
		},
		statusLabel(status) {
			return getProjectStatusLabel(status)
		},
		async loadProjectFiles(projectId) {
			this.filesError = ''
			this.filesLoading = true
			try {
				this.projectFiles = await projectsService.getFiles(projectId)
			} catch (error) {
				this.filesError = 'Could not load project files.'
				this.projectFiles = { private: [], shared: [] }
			} finally {
				this.filesLoading = false
			}
		},
		async refreshProjectFiles() {
			const projectId = Number(this.selectedProject?.id)
			if (!Number.isFinite(projectId) || projectId <= 0) {
				return
			}
			await this.loadProjectFiles(projectId)
			this.loadedFilesProjectId = projectId
		},
		resetFilesState() {
			this.filesError = ''
			this.filesLoading = false
			this.projectFiles = { private: [], shared: [] }
		},
		resetMembersState() {
			this.projectMembers = []
			this.membersLoading = false
			this.membersError = ''
			this.memberInviteSelection = null
			this.memberInviteRoles = []
			this.memberInviteFunctionalRoles = []
			this.functionalRoleOptions = []
			this.memberInviteOptions = []
			this.memberSearchLoading = false
			this.memberInviteLoading = false
			this.memberInviteMessage = ''
			this.memberRoleUpdating = {}
			if (this.memberSearchTimeout) {
				clearTimeout(this.memberSearchTimeout)
				this.memberSearchTimeout = null
			}
		},
		getMemberSearchOrganizationId() {
			const selectedProjectOrg = Number(this.selectedProject?.organization_id)
			if (Number.isFinite(selectedProjectOrg) && selectedProjectOrg > 0) {
				return selectedProjectOrg
			}
			const contextOrg = Number(this.context?.organizationId)
			if (Number.isFinite(contextOrg) && contextOrg > 0) {
				return contextOrg
			}
			return null
		},
		getDrasciRoleOptions(roleValues, legacyRole = null) {
			const selected = new Set(Array.isArray(roleValues) ? roleValues : (legacyRole ? [legacyRole] : []))
			return this.drasciRoleOptions.filter((option) => selected.has(option.value))
		},
		getFunctionalRoleOptions(roleKeys) {
			const selected = new Set(Array.isArray(roleKeys) ? roleKeys : [])
			return this.functionalRoleOptions.filter((option) => selected.has(option.value))
		},
		async loadProjectMembers(projectId) {
			this.membersLoading = true
			this.membersError = ''
			try {
				const result = await projectsService.listMembersWithRoles(projectId)
				this.projectMembers = result.members
				this.functionalRoleOptions = result.functionalRoles.map((role) => ({
					value: role.key,
					label: role.name,
				}))
			} catch (error) {
				this.projectMembers = []
				this.functionalRoleOptions = []
				this.membersError = error?.response?.data?.message || 'Could not load project members.'
			} finally {
				this.membersLoading = false
			}
		},
		searchMemberCandidates(query) {
			if (this.memberSearchTimeout) {
				clearTimeout(this.memberSearchTimeout)
			}
			if (!query || !this.selectedProject?.id) {
				this.memberInviteOptions = []
				this.memberSearchLoading = false
				return
			}
			this.memberSearchLoading = true
			this.memberSearchTimeout = setTimeout(async () => {
				try {
					const organizationId = this.getMemberSearchOrganizationId()
					if (this.loadedMembersProjectId !== Number(this.selectedProject?.id)) {
						await this.loadProjectMembers(Number(this.selectedProject?.id))
						this.loadedMembersProjectId = Number(this.selectedProject?.id)
					}
					const users = await projectsService.searchUsers(query, organizationId)
					const existingIds = new Set(this.projectMembers.map((member) => String(member.id)))
					this.memberInviteOptions = users
						.filter((user) => !existingIds.has(String(user.id)))
						.map((user) => ({
							id: user.id,
							label: user.displayName || user.label || user.id,
							subname: user.subname || user.id,
						}))
				} catch (error) {
					this.memberInviteOptions = []
					this.membersError = error?.response?.data?.message || 'Could not search organization users.'
				} finally {
					this.memberSearchLoading = false
				}
			}, 250)
		},
		async inviteSelectedMember() {
			if (!this.selectedProject?.id || !this.memberInviteSelection || this.memberInviteRoles.length === 0 || this.memberInviteFunctionalRoles.length === 0) {
				return
			}
			const userId = typeof this.memberInviteSelection === 'string'
				? this.memberInviteSelection
				: this.memberInviteSelection.id
			if (!userId) {
				return
			}
			const drasciRoles = this.memberInviteRoles.map((option) => typeof option === 'string' ? option : option.value)
			const functionalRoleKeys = this.memberInviteFunctionalRoles.map((option) => typeof option === 'string' ? option : option.value)
			this.memberInviteLoading = true
			this.memberInviteMessage = ''
			this.membersError = ''
			try {
				const result = await projectsService.addMember(this.selectedProject.id, String(userId), drasciRoles, functionalRoleKeys)
				await this.loadProjectMembers(this.selectedProject.id)
				this.loadedMembersProjectId = Number(this.selectedProject.id)
				this.memberInviteMessage = result?.alreadyMember
					? 'This user is already a project member.'
					: 'Member added to project successfully.'
				this.memberInviteSelection = null
				this.memberInviteRoles = []
				this.memberInviteFunctionalRoles = []
				this.memberInviteOptions = []
			} catch (error) {
				this.membersError = error?.response?.data?.message || 'Could not add member to project.'
			} finally {
				this.memberInviteLoading = false
			}
		},
		async updateMemberDrasciRoles(member, roleSelection) {
			const roles = Array.isArray(roleSelection)
				? roleSelection.map((option) => typeof option === 'string' ? option : option.value).filter(Boolean)
				: []
			if (!this.selectedProject?.id || !member?.id) {
				return
			}
			if (roles.length === 0) {
				this.membersError = 'Every project member must have at least one DRASCIVS role.'
				return
			}
			this.memberRoleUpdating = {
				...this.memberRoleUpdating,
				[member.id]: true,
			}
			try {
				const result = await projectsService.updateMemberRole(
					this.selectedProject.id,
					member.id,
					roles,
					member.functionalRoleKeys || [],
				)
				const updatedMember = result?.member
				this.projectMembers = this.projectMembers.map((existing) =>
					String(existing.id) === String(member.id)
						? { ...existing, ...updatedMember }
						: existing,
				)
			} catch (error) {
				this.membersError = error?.response?.data?.message || 'Could not update member role.'
			} finally {
				this.memberRoleUpdating = {
					...this.memberRoleUpdating,
					[member.id]: false,
				}
			}
		},
		async updateMemberFunctionalRoles(member, roleSelections) {
			const functionalRoleKeys = (roleSelections || []).map((selection) => typeof selection === 'string' ? selection : selection.value)
			if (!this.selectedProject?.id || !member?.id || functionalRoleKeys.length === 0) {
				return
			}
			this.memberRoleUpdating = {
				...this.memberRoleUpdating,
				[member.id]: true,
			}
			try {
				const result = await projectsService.updateMemberRole(
					this.selectedProject.id,
					member.id,
					undefined,
					functionalRoleKeys,
				)
				const updatedMember = result?.member
				this.projectMembers = this.projectMembers.map((existing) =>
					String(existing.id) === String(member.id)
						? { ...existing, ...updatedMember }
						: existing,
				)
			} catch (error) {
				this.membersError = error?.response?.data?.message || 'Could not update functional project roles.'
			} finally {
				this.memberRoleUpdating = {
					...this.memberRoleUpdating,
					[member.id]: false,
				}
			}
		},
		openDeck(project) {
			if (!project.boardId) {
				return
			}
			const url = generateUrl(`/apps/deck/board/${project.boardId}`)
			window.open(url, '_blank')
		},
		openChat(project) {
			if (!project?.talk_url) {
				return
			}
			window.open(project.talk_url, '_blank', 'noopener')
		},
		openFolder(project) {
			if (!project.folderPath) {
				return
			}
			const directory = project.folderPath.startsWith('/')
				? project.folderPath
				: `/${project.folderPath}`
			const url = generateUrl(`/apps/files/?dir=${encodeURIComponent(directory)}`)
			window.open(url, '_blank')
		},
		openWhiteboard(project) {
			if (!project?.id) {
				return
			}
			const url = generateUrl(`/apps/projectcreatoraio/?popout=whiteboard&projectId=${encodeURIComponent(String(project.id))}`)
			window.open(url, '_blank', 'noopener')
		},
		downloadProject(project) {
			if (!project.folderPath) {
				return
			}
			const path = this.normalizedPath(project.folderPath)
			const downloadUrl = new URL(webdavClient.getFileDownloadLink(path))
			downloadUrl.searchParams.append('accept', 'zip')
			this.triggerDownload(downloadUrl.href)
		},
		async requestProjectDownload() {
			if (!this.selectedProject?.id || this.exportRequesting) {
				return
			}
			this.exportRequesting = true
			this.exportQueuedMessage = ''
			try {
				const result = await projectsService.requestDownload(this.selectedProject.id)
				this.exportQueuedMessage = result?.message || 'Export is being prepared. You will be notified when it is ready.'
			} catch (error) {
				this.exportQueuedMessage = error?.response?.data?.message || 'Could not start export.'
			} finally {
				this.exportRequesting = false
			}
		},
		triggerDownload(href) {
			const link = document.createElement('a')
			link.href = href
			link.style.display = 'none'
			document.body.appendChild(link)
			link.click()
			link.remove()
		},
		normalizedPath(path) {
			const parts = path.split('/')
			if (parts.length >= 3) {
				const tmp = parts[1]
				parts[1] = parts[2]
				parts[2] = tmp
			}
			return parts.join('/')
		},
	},
}
</script>

<style scoped>
/* Layout Base */
.projects-home-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 48px 16px;
	min-height: 100%;
}

.projects-home {
	position: relative;
	display: grid;
	grid-template-columns: 360px minmax(0, 1fr);
	gap: 0;
	width: 100%;
	height: 100%;
	min-height: 0;
	box-sizing: border-box;
	background: var(--color-background-plain, var(--color-main-background));
	overflow: hidden;
}

/* Collapsed Sidebar State */
.projects-home--sidebar-collapsed {
	grid-template-columns: 64px minmax(0, 1fr);
}

/* Sidebar */
.projects-home__sidebar {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	background: var(--color-main-background);
	border-right: 1px solid var(--color-border);
	overflow: hidden;
	position: sticky;
	top: 0;
	transition: width 0.2s ease;
}

.projects-home__sidebar--collapsed {
	width: 64px;
}

.projects-home__sidebar-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
	min-height: 60px;
}

.projects-home__sidebar-brand {
	min-width: 0;
	flex: 1;
}

.projects-home__sidebar-actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.projects-home__sidebar-collapsed-controls {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 12px 0;
}

/* Main Content Area */
.projects-home__main {
	height: 100%;
	min-height: 0;
	padding: 20px;
	overflow: auto;
	display: flex;
	flex-direction: column;
}

.projects-home__main-content {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.projects-home__empty-main {
	margin: auto;
}

/* Typography */
.projects-home__title {
	margin: 0;
	font-size: 18px;
	font-weight: 700;
	line-height: 1.3;
}

.projects-home__subtitle {
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 1.4;
}

/* Controls */
.projects-home__controls {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.projects-home__control-row {
	display: grid;
	grid-template-columns: auto 1fr;
	align-items: center;
	gap: 10px;
}

.projects-home__control-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.projects-home__filters-row {
	display: grid;
	grid-template-columns: 1fr 1fr auto;
	gap: 10px;
	align-items: end;
}

.projects-home__filter {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.projects-home__filter-select {
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	padding: 8px 10px;
	font: inherit;
	font-size: 13px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.projects-home__filter-select:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.projects-home__clear {
	height: 36px;
	width: 36px;
	padding: 0;
}

.projects-home__count-row {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding-top: 4px;
}

/* Project List */
.projects-home__list {
	flex: 1;
	min-height: 0;
	overflow: auto;
}

.projects-home__items {
	list-style: none;
	padding: 0;
	margin: 0;
}

.projects-home__project-item {
	width: 100%;
	text-align: left;
	border: 0;
	background: transparent;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 16px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);
	position: relative;
	transition: background 0.15s ease;
}

.projects-home__project-item:hover {
	background: var(--color-background-hover);
}

.projects-home__project-item:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.projects-home__project-item--active {
	background: var(--color-primary-element-light);
}

.projects-home__project-item--active::before {
	content: '';
	position: absolute;
	left: 0;
	top: 8px;
	bottom: 8px;
	width: 3px;
	border-radius: 0 99px 99px 0;
	background: var(--color-primary-element);
}

.projects-home__project-main {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.projects-home__project-title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.projects-home__project-name {
	font-weight: 600;
	font-size: 14px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	flex: 1;
}

.projects-home__project-name--collapsed {
	font-size: 12px;
	font-weight: 700;
	text-align: center;
}

.projects-home__status-pill {
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 10px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	flex-shrink: 0;
}

.projects-home__status-pill--active {
	background: rgba(30, 127, 45, 0.12);
	color: #1e7f2d;
}

.projects-home__status-pill--waiting {
	background: rgba(181, 106, 0, 0.16);
	color: #b56a00;
}

.projects-home__status-pill--hold {
	background: rgba(92, 92, 122, 0.15);
	color: #54546b;
}

.projects-home__status-pill--done {
	background: rgba(12, 107, 74, 0.14);
	color: #0c6b4a;
}

.projects-home__status-pill--archived {
	background: rgba(120, 120, 120, 0.14);
	color: var(--color-text-maxcontrast);
}

.projects-home__project-meta {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.projects-home__meta-dot {
	opacity: 0.5;
}

/* Hero Section */
.projects-home__hero {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	border-radius: 12px;
	background:
		radial-gradient(circle at 0% 0%, rgba(36, 153, 255, 0.12), transparent 50%),
		radial-gradient(circle at 100% 20%, rgba(255, 166, 0, 0.15), transparent 35%),
		var(--color-main-background);
	border: 1px solid var(--color-border-dark);
}

.projects-home__hero-mobile {
	display: flex;
}

.projects-home__back {
	--border-radius: 10px;
}

.projects-home__hero-main {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.projects-home__hero-info {
	flex: 1;
	min-width: 0;
}

.projects-home__hero-title-row {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 6px;
}

.projects-home__details-title {
	margin: 0;
	font-size: 22px;
	font-weight: 700;
	line-height: 1.3;
}

.projects-home__title-edit-btn {
	--button-size: 30px;
	min-width: 30px;
}

.projects-home__details-subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	line-height: 1.5;
}

.projects-home__hero-badges {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.projects-home__hero-actions {
	display: flex;
	align-items: flex-end;
	gap: 10px;
	flex-shrink: 0;
}

.projects-home__status-editor {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 180px;
}

.projects-home__status-editor-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.projects-home__status-select {
	min-width: 180px;
}

.projects-home__delete-button {
	--button-size: 36px;
	--button-border-radius: 10px;
	align-self: flex-end;
	min-height: 36px;
	padding: 0 12px;
	border: 1px solid #e11919;
	background: #e11919;
	color: #fff;
	font-size: 12px;
	font-weight: 700;
	line-height: 1;
	box-shadow: 0 1px 2px rgba(148, 23, 23, 0.18);
}

.projects-home__delete-button:hover,
.projects-home__delete-button:focus-visible {
	border-color: #c91515;
	background: #c91515;
	color: #fff;
}

.projects-home__delete-button:active {
	border-color: #b51212;
	background: #b51212;
}

.projects-home__delete-button:disabled {
	border-color: rgba(225, 25, 25, 0.45);
	background: rgba(225, 25, 25, 0.45);
	color: rgba(255, 255, 255, 0.9);
	box-shadow: none;
}

/* Badges */
.projects-home__badge {
	padding: 4px 10px;
	border-radius: 999px;
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
	font-size: 12px;
	font-weight: 600;
}

.projects-home__badge--success {
	background: rgba(30, 127, 45, 0.12);
	border-color: rgba(30, 127, 45, 0.25);
	color: #1e7f2d;
}

.projects-home__badge--secondary {
	background: rgba(36, 153, 255, 0.1);
	border-color: rgba(36, 153, 255, 0.2);
	color: var(--color-primary-element);
}

.projects-home__badge--warning {
	background: rgba(181, 106, 0, 0.12);
	border-color: rgba(181, 106, 0, 0.24);
	color: #b56a00;
}

.projects-home__badge--muted {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Tabs */
.projects-home__tabs {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	padding: 4px;
	background: var(--color-background-dark);
	border-radius: 10px;
}

.projects-home__tab {
	appearance: none;
	border: 1px solid transparent;
	background: transparent;
	color: var(--color-main-text);
	border-radius: 8px;
	padding: 8px 14px;
	font: inherit;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	transition: all 0.15s ease;
	position: relative;
}

.projects-home__tab:hover {
	background: var(--color-background-hover);
}

.projects-home__tab:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.projects-home__tab--active {
	background: var(--color-main-background);
	border-color: var(--color-border-dark);
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.projects-home__tab-icon {
	flex-shrink: 0;
	opacity: 0.7;
}

.projects-home__tab--active .projects-home__tab-icon {
	opacity: 1;
}

.projects-home__tab-badge {
	padding: 1px 6px;
	border-radius: 999px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 10px;
	font-weight: 700;
	margin-left: 2px;
}

/* Tab Panel */
.projects-home__tab-panel {
	flex: 1;
	min-height: 0;
}

.projects-home__tab-section {
	border: 1px solid var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-main-background);
	padding: 20px;
}

.projects-home__tab-section--full {
	padding: 0;
	border: none;
	background: transparent;
}

.projects-home__tab-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.projects-home__section-title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.projects-home__section-subtitle {
	margin: 4px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.projects-home__tab-toolbar {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	margin-bottom: 16px;
}

.projects-home__tab-toolbar-left {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.projects-home__tab-toolbar-actions {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 12px;
	flex-wrap: wrap;
}

/* Detail Grid */
.projects-home__detail-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px;
}

.projects-home__detail-grid--single {
	grid-template-columns: minmax(0, 1fr);
	max-width: 600px;
}

.projects-home__card {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: 10px;
}

.projects-home__card-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.projects-home__card-subtitle {
	margin: 0;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.projects-home__kv-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.projects-home__kv {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.projects-home__label {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.06em;
}

.projects-home__value {
	font-size: 14px;
	color: var(--color-main-text);
	word-break: break-word;
}

/* Split View for Timeline & Deck */
.projects-home__split-view {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	height: 100%;
	min-height: 500px;
}

.projects-home__split-panel {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-main-background);
	overflow: hidden;
}

.projects-home__deck-sections {
	display: grid;
	grid-template-columns: 1fr;
	gap: 16px;
	height: 100%;
	min-height: 500px;
}

.projects-home__panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
}

.projects-home__panel-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.projects-home__panel-subtitle {
	margin: 0 0 8px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.projects-home__panel-content {
	flex: 1;
	overflow: auto;
	padding: 12px;
}

/* Members */
.projects-home__member-invite-row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.projects-home__member-invite-select {
	flex: 2 1 250px;
	min-width: 0;
}

.projects-home__member-invite-role-select {
	flex: 1 1 180px;
	min-width: 0;
}

.projects-home__member-invite-functional-select {
	flex: 1 1 220px;
	min-width: 0;
}

.projects-home__member-invite-button {
	flex: 0 0 auto;
	min-width: 0;
}

.projects-home__member-invite-button {
	white-space: nowrap;
}

.projects-home__member-invite-row :deep(.vs__dropdown-toggle),
.projects-home__member-invite-row :deep(.vs__selected-options),
.projects-home__member-invite-row :deep(.vs__search) {
	min-width: 0;
}

.projects-home__members-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.projects-home__member-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 10px;
	background: var(--color-main-background);
	transition: background 0.15s ease;
}

.projects-home__member-item:hover {
	background: var(--color-background-hover);
}

.projects-home__member-avatar {
	flex-shrink: 0;
}

.projects-home__avatar-placeholder {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-size: 12px;
	font-weight: 700;
}

.projects-home__member-main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.projects-home__member-name {
	font-weight: 600;
	font-size: 14px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.projects-home__member-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.projects-home__member-badges {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.projects-home__member-badge {
	padding: 3px 10px;
	border-radius: 999px;
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
	font-size: 11px;
	font-weight: 600;
}

.projects-home__member-badge--owner {
	background: rgba(36, 153, 255, 0.1);
	border-color: rgba(36, 153, 255, 0.2);
	color: var(--color-primary-element);
}

.projects-home__member-badge--muted {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.projects-home__member-role {
	flex: 0 0 180px;
}

.projects-home__member-role-select {
	min-width: 160px;
}

.projects-home__member-functional-role {
	flex: 0 0 220px;
}

.projects-home__member-functional-role-select {
	min-width: 200px;
}

/* States */
.projects-home__centered {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 32px;
	color: var(--color-text-maxcontrast);
}

.projects-home__loading-state,
.projects-home__empty-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.projects-home__empty-state p {
	margin: 0;
}

/* Messages */
.projects-home__inline-error {
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-error-text);
	text-align: right;
}

.projects-home__inline-error--left {
	text-align: left;
}

.projects-home__inline-success {
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-success, #1e7f2d);
}

/* Unified Location & Client Sheet */
.projects-home__overview-section--editable {
	position: relative;
	transition: border-color 0.2s ease;
}

.projects-home__overview-section--editable:hover {
	border-color: var(--color-primary-element);
}

.projects-home__section-edit-btn {
	opacity: 0.5;
	transition: opacity 0.2s ease, background-color 0.2s ease;
}

.projects-home__overview-section--editable:hover .projects-home__section-edit-btn {
	opacity: 1;
}

.projects-home__unified-sheet {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: 10px;
	overflow: hidden;
}

.projects-home__location-map {
	margin-top: 16px;
}

.projects-home__sheet-col {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.projects-home__sheet-divider {
	width: 1px;
	background: var(--color-border-dark);
}

/* Profile Modal */
.projects-home__profile-modal {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 24px;
	max-width: 600px;
}

/* Overview Tab - Combined Sections */
.projects-home__overview {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.projects-home__overview-section {
	border: 1px solid var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-main-background);
	padding: 20px;
}

.projects-home__overview-section .projects-home__tab-section-header {
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.projects-home__overview-section .projects-home__split-view {
	grid-template-columns: 1fr;
	min-height: 400px;
}

.projects-home__profile-modal-title {
	margin: 0;
	font-size: 18px;
	font-weight: 700;
}

.projects-home__profile-modal-subtitle {
	margin: -12px 0 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.projects-home__profile-nav {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-background-hover);
}

.projects-home__profile-nav-btn {
	border: 1px solid transparent;
	background: transparent;
	color: var(--color-text-maxcontrast);
	padding: 8px 12px;
	border-radius: 8px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	transition: background-color 120ms ease, border-color 120ms ease;
}

.projects-home__profile-nav-btn:hover:not(.projects-home__profile-nav-btn--active) {
	background: var(--color-background-dark);
}

.projects-home__profile-nav-btn:focus {
	outline: none;
}

.projects-home__profile-nav-btn:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.projects-home__profile-nav-btn--active {
	background: var(--color-main-background);
	border-color: var(--color-border-dark);
}

.projects-home__profile-sections {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.projects-home__profile-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.projects-home__profile-section-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.projects-home__profile-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

.projects-home__profile-grid--single {
	grid-template-columns: 1fr;
}

.projects-home__profile-actions {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 10px;
	margin-top: 8px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 1100px) {
	.projects-home__member-invite-select {
		flex: 1 1 100%;
	}

	.projects-home__member-invite-role-select {
		flex: 1 1 0px;
		min-width: 150px;
	}

	.projects-home__member-invite-functional-select {
		flex: 1 1 220px;
	}

	.projects-home__member-invite-button {
		flex: 0 0 auto;
	}
}

/* Mobile Responsive */
@media (max-width: 900px) {
	.projects-home {
		grid-template-columns: 1fr;
		height: auto;
		min-height: auto;
		overflow: visible;
	}

	.projects-home__sidebar {
		position: static;
		height: auto;
		border-right: none;
		border-bottom: 1px solid var(--color-border);
	}

	.projects-home__main {
		padding: 16px;
		overflow: visible;
	}

	.projects-home__hero-main {
		flex-direction: column;
		gap: 12px;
	}

	.projects-home__hero-title-row {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.projects-home__hero-badges {
		justify-content: flex-start;
	}

	.projects-home__tabs {
		gap: 2px;
	}

	.projects-home__tab {
		padding: 6px 10px;
		font-size: 12px;
	}

	.projects-home__tab-label {
		display: none;
	}

	.projects-home__tab-icon {
		margin: 0;
	}

	.projects-home__detail-grid {
		grid-template-columns: 1fr;
	}

	.projects-home__unified-sheet {
		grid-template-columns: 1fr;
	}

	.projects-home__sheet-divider {
		width: 100%;
		height: 1px;
	}

	.projects-home__tab-toolbar {
		flex-direction: column;
		align-items: stretch;
	}

	.projects-home__tab-toolbar-actions {
		justify-content: stretch;
	}

	.projects-home__tab-toolbar-actions :deep(button) {
		width: 100%;
	}

	.projects-home__split-view {
		grid-template-columns: 1fr;
	}

	.projects-home__member-invite-row {
		flex-direction: column;
		align-items: stretch;
	}

	.projects-home__member-invite-select,
	.projects-home__member-invite-role-select,
	.projects-home__member-invite-functional-select,
	.projects-home__member-invite-button {
		flex: 1 1 auto;
		width: 100%;
	}

	.projects-home__member-invite-button :deep(button) {
		width: 100%;
	}

	.projects-home__member-item {
		flex-wrap: wrap;
	}

	.projects-home__member-role {
		flex: 0 0 100%;
	}

	.projects-home__member-role-select {
		min-width: 100%;
	}

	.projects-home__member-functional-role {
		flex: 0 0 100%;
	}

	.projects-home__member-functional-role-select {
		min-width: 100%;
	}

	.projects-home__member-badges {
		width: 100%;
		justify-content: flex-start;
		margin-top: 8px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	.projects-home__profile-grid {
		grid-template-columns: 1fr;
	}

	.projects-home__profile-nav {
		flex-wrap: wrap;
	}

	.projects-home__inline-error {
		text-align: left;
	}
}

@media (max-width: 480px) {
	.projects-home__tabs {
		overflow-x: auto;
		flex-wrap: nowrap;
		-webkit-overflow-scrolling: touch;
		scrollbar-width: none;
	}

	.projects-home__tabs::-webkit-scrollbar {
		display: none;
	}

	.projects-home__tab {
		white-space: nowrap;
		flex-shrink: 0;
	}
}
</style>
