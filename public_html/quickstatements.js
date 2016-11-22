// For data tables, see https://datatables.net/examples/api/add_row.html

var QuickStatements = {

	api : './api.php' ,
	params : {} ,
	data : {} ,
	widar : {} ,

	init : function () {
		var me = this ;
		me.widar = new WiDaR ( function () { me.updateUserInfo() } ) ;
		me.params = me.getUrlVars() ;
		
		$('#import_v1_dialog').on('shown.bs.modal', function () { $('#v1_commands').val('').focus() })
		$('#v1_import').click ( function(){me.onImportV1(); $('#import_v1_dialog').modal('hide') } ) ;
		
		$('#main_table').DataTable ( {
			ordering:false,
			info:false
		} );
	
		$('#link_import_qs1').click ( me.onClickImportV1 ) ;
		
		if ( typeof me.params.v1 != 'undefined' ) me.importFromV1 ( me.params.v1 ) ;

		me.updateUnlabeledItems() ;
	} ,
	
	updateUserInfo : function () {
		var me = this ;
		var h = '' ;
		if ( me.widar.isLoggedIn() ) {
			h = "Welcome, " + me.widar.getUserName() ;
		} else {
			h += "<a href='/widar' target='_blank'>Log in</a> to use this tool" ;
		}
		$('#userinfo').html ( h ) ;
	} ,
	
	getUrlVars : function () {
		var vars = {} ;
		var hashes = window.location.href.slice(window.location.href.indexOf('#') + 1).split('&');
		$.each ( hashes , function ( i , j ) {
			var hash = j.split('=');
			hash[1] += '' ;
			vars[hash[0]] = decodeURI(hash[1]).replace(/_/g,' ');
		} ) ;
		return vars;
	} ,
	
	onClickImportV1 : function () {
		$('#import_v1_dialog').modal('show') ;
	} ,
	
	onImportV1 : function () {
		var me = this ;
		var text = $('#v1_commands').val() ;
		$('#v1_commands').val('') ;
		me.importFromV1 ( text ) ;
	} ,
	
	updateUnlabeledItems : function () {
		var me = this ;
		var to_update = [] ;
		$('a.wd_unlabeled').each ( function () {
			var a = $(this) ;
			var pq = a.attr('pq') ;
			if ( !pq.match(/^[PQ]\d+$/i) ) return ; // Paranoia
			to_update.push ( pq ) ;
			a.removeClass('wd_unlabeled').addClass('wd_pq').attr({title:pq}) ;
			if ( to_update.length >= 50 ) return false ; // Max
		} ) ;
		
		if ( to_update.length > 0 ) {
			$.getJSON ( 'https://www.wikidata.org/w/api.php?action=wbgetentities&ids='+to_update.join('|')+"&format=json&callback=?" , function ( d ) {
				$.each ( d.entities , function ( pq , v ) {
					var label ;
					if ( typeof v.labels != 'undefined' ) {
						if ( typeof v.labels['en'] != 'undefined' ) {
							label = v.labels['en'].value ;
						}
					}
					if ( typeof label == 'undefined' ) return ;
					$('a.wd_pq[pq="'+pq+'"]').text(label) ;
				} ) ;
			} ) ;
		}
		
		setTimeout ( function () { me.updateUnlabeledItems() } , 500 ) ;
	} ,

	importFromV1 : function ( v1 ) {
		var me = this ;
		location.hash = 'v1='+v1 ;
		$.get ( me.api , {
			action:'import',
			data:v1,
			format:'v1',
			persistent:0,
		} , function ( d ) {
			// TODO status/error check
			me.data = d.data ;
			me.setupTableFromCommands() ;
		} , 'json' ) ;
	} ,
	
	renderPQ : function ( i ) {
		if ( i == 'LAST' ) {
			return '<i>LAST ITEM</i>' ;
		} else if ( i.match ( /^Q\d+$/ ) ) { // Q
			return "<a class='wd_unlabeled' pq='"+i+"' href='//www.wikidata.org/wiki/" + i + "' target='_blank'>" + i + "</a>" ;
		} else if ( i.match ( /^P\d+$/ ) ) { // P
			return "<a class='wd_unlabeled' pq='"+i+"' href='//www.wikidata.org/wiki/Property:" + i + "' target='_blank'>" + i + "</a>" ;
		} else { // P
			return me.renderString ( i ) ;
		}
	} ,
	
	renderString : function ( s ) {
		if ( typeof s == 'undefined' ) return '' ;
		return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;').replace(/\//g,'&#x2F;') ;
	} ,
	
	renderValue : function ( v ) {
		var me = this ;
		if ( typeof v.type == 'undefined' ) return "<i>" + JSON.stringify(v) + "</i>" ;
		if ( v.type == 'item' || v.type == 'property' ) return me.renderPQ ( v.value ) ;
		if ( v.type == 'string' ) return me.renderString ( v.value ) ;
		if ( v.type == 'time' ) return v.value.time + '/' + v.value.precision  ;
		if ( v.type == 'globecoordinate' ) return v.value.latitude + '/' + v.value.longitude  ;
		return "<i>" + JSON.stringify(v) + "</i>" ;
	} ,
	
	renderAction : function ( cmd ) {
		var ret = cmd.action.toUpperCase() ;
		if ( typeof cmd.what != 'undefined' && cmd.what != 'statement' ) ret += " " + cmd.what.toUpperCase() ;
		return ret ;
	} ,
	
	renderQualifier : function ( qualifier ) {
		return "<i>QUALIFIER</i>" ;
	} ,
	
	renderSources : function ( sources ) {
		return "<i>SOURCES</i>" ;
	} ,
	
	addCommandToTable : function ( cmdnum , cmd , dt ) {
		var me = this ;
		var tabs = [ 'Unknown command' , '' , '' , '' , '' ] ;
		if ( cmd.action == 'add' && ( cmd.what=='statement' || cmd.what=='qualifier' || cmd.what=='sources' ) ) {
			tabs[0] = me.renderAction ( cmd ) ;
			tabs[1] = me.renderPQ ( cmd.item ) ;
			tabs[2] = me.renderPQ ( cmd.property ) ;
			tabs[3] = me.renderValue ( cmd.datavalue ) ;
			if ( cmd.what == 'qualifier' ) tabs[4] = me.renderQualifier ( cmd.qualifier ) ;
			if ( cmd.what == 'sources' ) tabs[4] = me.renderSources ( cmd.sources ) ;
		} else if ( cmd.action == 'add' && ( cmd.what=='label' || cmd.what=='description' || cmd.what=='alias' || cmd.what=='sitelink' ) ) {
			tabs[0] = me.renderAction ( cmd ) ;
			tabs[1] = me.renderPQ ( cmd.item ) ;
			if ( cmd.what == 'sitelink' ) {
				tabs[2] = me.renderString ( cmd.site ) ;
			} else {
				tabs[2] = me.renderString ( cmd.language ) ;
			}
			tabs[3] = me.renderString ( cmd.value ) ;
		} else if ( cmd.action == 'create' ) {
			tabs[0] = me.renderAction ( cmd.action ) ;
			tabs[1] = cmd.type ;
		} else { // Unknown
			tabs[4] = JSON.stringify(cmd) ;
		}
		
		dt.row.add ( tabs ) ;
	} ,
	
	setupTableFromCommands : function () {
		var me = this ;
		var dt = $('#main_table').DataTable() ;
		$.each ( me.data.commands , function ( cmdnum , cmd ) {
			me.addCommandToTable ( cmdnum , cmd , dt ) ;
		} ) ;
		dt.draw(false) ;
	} ,

	fin:''
}


$(document).ready ( function () {
	QuickStatements.init() ;
} ) ;