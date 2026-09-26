import { build } from '/app/node_modules/vite/dist/node/index.js'
import vue from '/app/node_modules/@vitejs/plugin-vue2/dist/index.mjs'
await build({configFile:false,root:'/check',plugins:[vue()],resolve:{alias:{vue:'/app/node_modules/vue/dist/vue.esm.js',path:'/app/node_modules/path-browserify/index.js'}},build:{outDir:'/check/dist'}})
