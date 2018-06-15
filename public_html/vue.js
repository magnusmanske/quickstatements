'use strict';

let router ;
let app ;
let wd = new WikiData() ;

let config = {} ;
let prop_map = {} ;

$(document).ready ( function () {
    vue_components.toolname = 'quickstatements' ;
//    vue_components.components_base_url = 'https://tools.wmflabs.org/magnustools/resources/vue/' ; // For testing; turn off to use tools-static
    Promise.all ( [
            vue_components.loadComponents ( ['wd-date','wd-link','tool-translate','tool-navbar','commons-thumbnail',
                'vue_components/user.html',
                'vue_components/batch_access_mixin.html',
                'vue_components/main-page.html',
                'vue_components/command.html',
                'vue_components/batch-commands.html',
                'vue_components/batch.html',
/*                'classifier.html',
                'property-list.html',
                'coordinates.html',
                'claim.html',
                'snak.html',
                'reasonator-link.html',
                'sidebar.html'*/
                ] ) ,
            new Promise(function(resolve, reject) {
                $.get ( './config.json' , function (d) {
                    config = d ;
                    resolve() ;
                } , 'json' ) ;
            } )
    ] ) .then ( () => {
        wd_link_base = config.sites[config.site].pageBase ;
        wd_link_wd = wd ;
        wd.api = config.sites[config.site].api + '?callback=?' ;

        const routes = [
          { path: '/', component: MainPage , props:true },
          { path: '/batch', component: BatchPage , props:true },
          { path: '/batch/:batch', component: BatchPage , props:true },
        ] ;
        router = new VueRouter({routes}) ;
        app = new Vue ( { router } ) .$mount('#app') ;
    } ) ;
} ) ;

