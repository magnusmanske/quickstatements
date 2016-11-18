// For data tables, see https://datatables.net/examples/api/add_row.html

var QuickStatements = {

	api : './api.php' ,
	params : {} ,
	data : {} ,

	init : function () {
		this.params = this.getUrlVars() ;
		
		$('#main_table').DataTable ( {
			ordering:false,
			info:false
		} );
	
		$('#link_import_qs1').click ( this.onClickImportV1 ) ;
		
		if ( typeof this.params.v1 != 'undefined' ) this.importFromV1 ( this.params.v1 ) ;

		this.updateUnlabeledItems() ;
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
		alert ( "TODO" ) ;
	} ,
	
	updateUnlabeledItems : function () {
		var me = this ;
		var to_update = [] ;
		$('a.wd_unlabeled').each ( function () {
			var a = $(this) ;
			var pq = a.attr('pq') ;
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
		$.post ( me.api , {
			action:'import',
			data:v1,
			format:'v1',
			persistent:0,
		} , function ( d ) {
			// TODO status/error check
			console.log ( d ) ;
			me.data = d.data ;
			me.setupTableFromCommands() ;
		} , 'json' ) ;
	} ,
	
	renderPQ : function ( i ) {
		if ( i.match ( /^Q\d+$/ ) ) { // Q
			return "<a class='wd_unlabeled' pq='"+i+"' href='//www.wikidata.org/wiki/" + i + "' target='_blank'>" + i + "</a>" ;
		} else { // P
			return "<a class='wd_unlabeled' pq='"+i+"' href='//www.wikidata.org/wiki/Property:" + i + "' target='_blank'>" + i + "</a>" ;
		}
	} ,
	
	renderString : function ( s ) {
		return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;').replace(/\//g,'&#x2F;') ;
	} ,
	
	renderValue : function ( v ) {
		var me = this ;
		if ( v.type == 'item' || v.type == 'property' ) return me.renderPQ ( v.value ) ;
		if ( v.type == 'string' ) return me.renderString ( v.value ) ;
		return "<i>" + v.type + "</i>" ;
	} ,
	
	renderAction : function ( action ) {
		return action.toUpperCase() ;
	} ,
	
	addCommandToTable : function ( cmdnum , cmd , dt ) {
		var me = this ;
		var tabs = [ 'Unknown command' , '' , '' , '' , '' ] ;
		if ( cmd.action == 'add' ) {
			tabs[0] = me.renderAction ( cmd.action ) ;
			tabs[1] = me.renderPQ ( cmd.item ) ;
			tabs[2] = me.renderPQ ( cmd.property ) ;
			tabs[3] = me.renderValue ( cmd.value ) ;
		} else if ( cmd.action == 'create' ) {
			tabs[0] = me.renderAction ( cmd.action ) ;
			tabs[1] = cmd.type ;
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