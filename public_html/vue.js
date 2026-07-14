'use strict';

let router ;
let app ;
let wd = new WikiData() ;

let config = {} ;
let prop_map = {} ;
let working = false ;

function syncDocumentDirection (selectedLanguage) {
	const rtlLanguages = [
		'ar', 'arc', 'arz', 'ks', 'lrc', 'mzn', 'azb', 'nqo', 'pnb',
		'ps', 'sd', 'ug', 'ur', 'yi', 'ckb', 'dv', 'fa', 'glk', 'he'
	] ;
	const cookieLanguage = document.cookie.match(/(?:^|;\s*)interface_language=([^;]+)/) ;
	const toolLanguage = typeof tt != 'undefined' && tt && tt.language ? tt.language : '' ;
	const savedLanguage = cookieLanguage ? decodeURIComponent(cookieLanguage[1]) : '' ;
	const language = (selectedLanguage || toolLanguage || savedLanguage || document.documentElement.lang || 'en').replace(/-.+$/, '') ;
	const direction = rtlLanguages.indexOf(language) > -1 ? 'rtl' : 'ltr' ;
	document.documentElement.setAttribute('lang', language) ;
	document.documentElement.setAttribute('dir', direction) ;
	document.body.setAttribute('dir', direction) ;
	document.body.style.direction = direction ;
}

$(document).ready ( function () {
	const workingElement = document.getElementById('working') ;
	if (workingElement) {
		const syncWorkingState = function () {
			document.body.classList.toggle('qs-is-working', window.getComputedStyle(workingElement).display != 'none') ;
		} ;
		new MutationObserver(syncWorkingState).observe(workingElement, {attributes:true,attributeFilter:['class','style']}) ;
		syncWorkingState() ;
	}
	syncDocumentDirection () ;
	$(document).on('change.qsDirection', '#tooltranslate_wrapper select', function () {
		syncDocumentDirection(this.value) ;
	}) ;
    vue_components.toolname = 'quickstatements' ;
	// XAMPP serves the magnustools checkout beside QuickStatements. Use those
	// templates for private-network previews, where tools-static may be blocked.
	if ( /^(localhost|127\.0\.0\.1|::1|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(location.hostname) ) {
		vue_components.components_base_url = '../../magnustools/public_html/resources/vue/' ;
		vue_components.serial_loading = true ;
		vue_components.fetch_timeout_ms = 5000 ;
		vue_components.fetch_retries = 2 ;
	}
    $.ajaxSetup ( { cache:false } ) ;
//    vue_components.components_base_url = 'https://tools.wmflabs.org/magnustools/resources/vue/' ; // For testing; turn off to use tools-static
    Promise.all ( [
            vue_components.loadComponents ( ['wd-date','wd-link','tool-translate','commons-thumbnail',
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
		syncDocumentDirection () ;
        $.ajaxSetup ( { cache:true } ) ;
        const siteConfig = config.sites[config.site] ;
        const apiUrl = siteConfig.publicApi || siteConfig.api ;
        wd_link_base = siteConfig.pageBase ;
        wd_link_wd = wd ;
        wd.api = apiUrl + '?callback=?' ;
        wd.main_languages.unshift( tt.language ) ;
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
        app = new Vue ( {
            router,
            data : {
                authLoaded : false,
                isLoggedIn : false,
                userinfo : {}
            }
        } ) .$mount('#app') ;
    } ) .catch ( error => {
		console.error(error) ;
		document.getElementById('app').removeAttribute('v-cloak') ;
		document.querySelector('.qs-loading').style.display = 'none' ;
		document.getElementById('qs-startup-error').hidden = false ;
	} ) ;
} ) ;

