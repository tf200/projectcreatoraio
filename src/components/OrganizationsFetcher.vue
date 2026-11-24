<template>
	<NcSelect
		:model-value="selectedOption"
		@update:model-value="emitUpdate"
		:options="options"
		:loading="isLoading"
		:show-label="true"
		:input-label="inputLabel"
		:placeholder="placeholder"
		:multiple="false"
		:searchable="true"
		@search="onSearch"
		clearable />
</template>

<script>
import { CanceledError } from 'axios'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { OrganizationsService } from '../Services/organizations'
import { t } from '../new/l10n.mjs'

function debounce(fn, delay) {
	let timeoutId
	return function (...args) {
		clearTimeout(timeoutId)
		timeoutId = setTimeout(() => {
			fn.apply(this, args)
		}, delay)
	}
}

const organizationsService = OrganizationsService.getInstance()

export default {
	name: 'OrganizationsFetcher',
	emits: ['update:modelValue', 'error'],
	components: {
		NcSelect,
	},
	props: {
		modelValue: {
			type: Number,
			default: null,
		},
		inputLabel: {
			type: String,
			// A factory, so the label is translated when the component is used.
			default: () => t('Organization'),
		},
		placeholder: {
			type: String,
			default: () => t('Search for an organization...'),
		},
	},
	data() {
		return {
			abortController: null,
			isLoading: false,
			options: [],
			selectedOption: null,
		}
	},
	watch: {
		modelValue: {
			immediate: true,
			async handler(newId) {
				if (!newId) {
					this.selectedOption = null
					this.options = []
					return
				}

				if (this.selectedOption && this.selectedOption.id === newId) {
					return
				}

				this.isLoading = true
				try {
					const details = await organizationsService.getDetails(newId)
					if (details) {
						this.selectedOption = details
						this.options = [details]
					}
				} catch (error) {
					this.$emit('error', error)
				} finally {
					this.isLoading = false
				}
			},
		},
	},
	created() {
		this.debouncedSearch = debounce(this.searchOrganizations, 250)
	},
	methods: {
		onSearch(query) {
			if (this.abortController) {
				this.abortController.abort()
			}

			if (!query) {
				this.options = this.selectedOption ? [this.selectedOption] : []
				return
			}

			this.isLoading = true
			this.debouncedSearch(query)
		},
		async searchOrganizations(query) {
			this.abortController = new AbortController()
			try {
				const results = await organizationsService.search(query)
				if (this.selectedOption && !results.find((org) => org.id === this.selectedOption.id)) {
					this.options = [this.selectedOption, ...results]
				} else {
					this.options = results
				}
			} catch (error) {
				if (!(error instanceof CanceledError)) {
					this.$emit('error', error)
				}
			} finally {
				this.isLoading = false
			}
		},
		emitUpdate(selected) {
			this.selectedOption = selected
			this.$emit('update:modelValue', selected ? Number(selected.id) : null)
		},
	},
}
</script>
