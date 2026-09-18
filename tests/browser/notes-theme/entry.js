import Vue from '/app/node_modules/vue/dist/vue.esm.js'
import Notes from '/app/src/components/ProjectNotesList.vue'
import '/app/src/new/new-ui.css'
window._oc_webroot=''
Vue.mixin({methods:{t:window.t,n:window.n}})
window.fixture=new Vue({el:'#app',render:h=>h('main',{class:'pc-new'},[h('header',{class:'pc-project-header'},[h('h1','Riverside renovation'),h('p','Notes · Project #1042')]),h('section',{class:'pc-module'},[h(Notes,{class:'pc-notes-theme',props:{projectId:1042,currentUserId:'emma',talkConversationToken:'team',talkUrl:'/talk/team',members:[{id:'emma',displayName:'Emma'},{id:'thomas',displayName:'Thomas Jansen'}]}})])])})
