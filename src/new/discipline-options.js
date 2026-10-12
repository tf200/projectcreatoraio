// The discipline chips with their icons, for the Vue views (disciplines.js
// stays free of components so Node tests can load it).
import CableData from 'vue-material-design-icons/CableData.vue'
import Fire from 'vue-material-design-icons/Fire.vue'
import FlashOutline from 'vue-material-design-icons/FlashOutline.vue'
import Pipe from 'vue-material-design-icons/Pipe.vue'
import SignalVariant from 'vue-material-design-icons/SignalVariant.vue'
import WaterOutline from 'vue-material-design-icons/WaterOutline.vue'
import { DISCIPLINES } from './disciplines.js'

const ICONS = { electricity: FlashOutline, water: WaterOutline, gas: Fire, coax: CableData, fiber_optic: SignalVariant, sewerage: Pipe }

// Frozen, so Vue doesn't make the icon components reactive.
export const DISCIPLINE_OPTIONS = Object.freeze(DISCIPLINES.map(discipline => Object.freeze({ ...discipline, icon: ICONS[discipline.value] })))
