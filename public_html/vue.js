'use strict';

let router ;
let app ;
let wd = new WikiData() ;

let config = {} ;
let prop_map = {} ;
let working = false ;

$(document).ready ( function () {
    vue_components.toolname = 'quickstatements' ;
//    vue_components.components_base_url = 'https://tools.wmflabs.org/magnustools/resources/vue/' ; // For testing; turn off to use tools-static
    Promise.all ( [
            vue_components.loadComponents ( ['wd-date','wd-link','tool-translate','tool-navbar','commons-thumbnail',
                'vue_components/batch_access_mixin.html',
                'vue_components/user.html',
                'vue_components/main-page.html',
                'vue_components/command.html',
                'vue_components/batch-commands.html',
                'vue_components/batch.html',
                'vue_components/batches.html',
                'vue_components/user-page.html',
                ] ) ,
            new Promise(function(resolve, reject) {
                $.get ( './config.json' , function (d) {
                    config = d ;
                    resolve() ;
                } , 'json' ) ;
            } )
    ] ) .then ( () => {
        const siteConfig = config.sites[config.site] ;
        const apiUrl = siteConfig.publicApi || siteConfig.api ;
        wd_link_base = siteConfig.pageBase ;
        wd_link_wd = wd ;
        wd.api = apiUrl + '?callback=?' ;
        wd_ns_prefixes = {} ;
        for ( var letter in siteConfig.types )
            wd_ns_prefixes[letter] = siteConfig.types[letter].ns_prefix ;

        const routes = [
          { path: '/', component: MainPage , props:true },
          { path: '/batches', component: BatchesPage , props:true },
          { path: '/batches/:user_name', component: BatchesPage , props:true },
          { path: '/batch', component: BatchPage , props:true },
          { path: '/batch/:batch', component: BatchPage , props:true },
          { path: '/user', component: UserPage , props:true },
          { path: '/user/:given_user_name', component: UserPage , props:true },
          { path: '/:url_params', component: MainPage , props:true },
        ] ;
        router = new VueRouter({routes}) ;
        app = new Vue ( { router } ) .$mount('#app') ;
    } ) ;
} ) ;

