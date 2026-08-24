<template>
	<transition name="scroll-top-fade">
		<button
			v-if="isVisible"
			type="button"
			class="scroll-to-top-button"
			:aria-label="title"
			:title="title"
			@click="scrollToTop">
			<ArrowUp :size="20" />
		</button>
	</transition>
</template>

<script>
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'

export default {
	name: 'ScrollToTopButton',
	components: {
		ArrowUp,
	},
	props: {
		/**
		 * Target scroll container element, ref, or function returning the element.
		 */
		target: {
			type: [Object, Function],
			default: null,
		},
		/**
		 * Scroll threshold in pixels before showing the button.
		 */
		threshold: {
			type: Number,
			default: 250,
		},
		/**
		 * Controls whether the button is allowed to be visible.
		 */
		enabled: {
			type: Boolean,
			default: true,
		},
		/**
		 * Accessibility label and tooltip text.
		 */
		title: {
			type: String,
			default: 'Scroll to top',
		},
	},
	data() {
		return {
			isScrolled: false,
			scrollContainer: null,
			ticking: false,
		}
	},
	computed: {
		isVisible() {
			return this.enabled && this.isScrolled
		},
	},
	watch: {
		target: {
			handler() {
				this.unbindScrollListener()
				this.bindScrollListener()
			},
		},
		enabled(val) {
			if (val) {
				this.checkScrollPosition()
			}
		},
	},
	mounted() {
		this.$nextTick(() => {
			this.bindScrollListener()
		})
	},
	beforeDestroy() {
		this.unbindScrollListener()
	},
	methods: {
		resolveTarget() {
			if (typeof this.target === 'function') {
				return this.target()
			}
			if (this.target && this.target.$el) {
				return this.target.$el
			}
			if (this.target && this.target instanceof HTMLElement) {
				return this.target
			}
			return this.$el ? this.$el.parentElement : null
		},
		bindScrollListener() {
			const container = this.resolveTarget()
			if (container) {
				this.scrollContainer = container
				this.scrollContainer.addEventListener('scroll', this.handleScroll, { passive: true })
				this.checkScrollPosition()
			}
		},
		unbindScrollListener() {
			if (this.scrollContainer) {
				this.scrollContainer.removeEventListener('scroll', this.handleScroll)
				this.scrollContainer = null
			}
		},
		handleScroll() {
			if (!this.ticking) {
				window.requestAnimationFrame(() => {
					this.checkScrollPosition()
					this.ticking = false
				})
				this.ticking = true
			}
		},
		checkScrollPosition() {
			const container = this.scrollContainer || this.resolveTarget()
			if (!container) {
				this.isScrolled = false
				return
			}
			const scrollTop = container.scrollTop || 0
			this.isScrolled = scrollTop > this.threshold
		},
		scrollToTop() {
			const container = this.scrollContainer || this.resolveTarget()
			if (!container) {
				return
			}
			const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
			try {
				container.scrollTo({
					top: 0,
					behavior: prefersReducedMotion ? 'auto' : 'smooth',
				})
			} catch (e) {
				container.scrollTop = 0
			}
		},
	},
}
</script>

<style scoped>
.scroll-to-top-button {
	position: absolute;
	right: 24px;
	bottom: 24px;
	z-index: 100;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 42px;
	height: 42px;
	padding: 0;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: 50%;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 4px 14px rgba(0, 0, 0, 0.16);
	cursor: pointer;
	outline: none;
	transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.scroll-to-top-button:hover {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	transform: translateY(-3px);
	box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
}

.scroll-to-top-button:active {
	transform: translateY(-1px);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
}

.scroll-to-top-button:focus-visible {
	box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--color-primary-element);
}

/* Transitions */
.scroll-top-fade-enter-active,
.scroll-top-fade-leave-active {
	transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1), transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.scroll-top-fade-enter,
.scroll-top-fade-leave-to {
	opacity: 0;
	transform: translateY(12px) scale(0.9);
}

@media (max-width: 768px) {
	.scroll-to-top-button {
		right: 18px;
		bottom: 18px;
		width: 38px;
		height: 38px;
	}
}
</style>
