// For data tables, see https://datatables.net/examples/api/add_row.html

var QuickStatements = {

	api : './api.php' ,
	params : {} ,
	data : {} ,
	oauth : {} ,
	sites : {} , // Loaded from sites.json
	types : {} ,
	run_state : { running:false } ,
	batch_update_interval : 5000 ,

	init : function () {
		var me = this ;
		
		var running = 3 ;
		function fin () {
			running-- ;
			if ( running > 0 ) return ;

			me.tt.addILdropdown ( $('#interface_language_wrapper') ) ;
			me.setSite ( 'wikidata' ) ;
			me.updateUserInfo() ;
			me.params = me.getUrlVars() ;
		
			$('#import_v1_dialog').on('shown.bs.modal', function () { $('#v1_commands').val('').focus() })
			$('#v1_import').click ( function(){me.onImportV1(); $('#import_v1_dialog').modal('hide') } ) ;
		
			$('#main_table').DataTable ( {
				ordering:false,
				info:false
			} );
	
			$('#link_import_qs1').click ( me.onClickImportV1 ) ;
			$('#run').click ( function () { me.run ( false ) ; return false } ) ;
			$('#run_background').click ( function () { me.run ( true ) ; return false } ) ;
			$('#stop').click ( function () { me.stop() ; return false } ) ;
		
			if ( typeof me.params.v1 != 'undefined' ) me.importFromV1 ( me.params.v1 ) ;

			me.updateUnlabeledItems() ;
			
			if ( me.params.mode == 'batch' ) me.run_state.batch_id = me.params.batch ;
			if ( me.params.mode == 'batches' ) {
				me.run_state.filters = {} ;
				if ( typeof me.params.user != 'undefined' ) me.run_state.filters.user = me.params.user ;
			}
			me.switchMode ( me.params.mode ) ;
		}
		
		me.tt = new ToolTranslation ( { tool:'quickstatements' , language:me.lang() , fallback:'en' , callback : function () { fin() } } ) ;
		
		$.get ( 'sites.json' , function ( d ) {
			me.sites = d ;
			fin() ;
		} , 'json' ) ;

		me.oauth = { is_logged_in:false } ;
		$.post ( me.api , { action:'is_logged_in' }, 'json' )
			.then( function( d ) { me.oauth = d.data; } )
			.always( fin );
	} ,

	setSite : function ( site ) {
		var me = this ;
		me.site = site ;
		me.types = me.sites[me.site].types ;
	} ,

	getSiteAPI : function () {
		var me = this ;
		return 'https://' + me.sites[me.site].server + '/w/api.php' ;
	} ,
	
	getSitePageURL : function ( page ) {
		var me = this ;
		return '//' + me.sites[me.site].server + '/wiki/' + encodeURIComponent ( page.replace(/ /g,'_') ) ;
	} ,
	
	lang : function () {
		return 'en' ; // FIXME current language
	} ,
	
	stop : function () {
		var me = this ;
		me.run_state.running = false ;
		if ( typeof me.run_state.batch_watcher != 'undefined' ) clearInterval ( me.run_state.batch_watcher ) ;
		$('#stop_buttons button').prop ( 'disabled' , true ) ;
		$('#run_buttons button').prop ( 'disabled' , false ) ;
		$('#stop_buttons').hide() ;
	} ,
	
	ts2string : function ( ts ) {
		return ts.substr(0,4) + '-' + ts.substr(4,2) + '-' + ts.substr(6,2) + ' ' + ts.substr(8,2) + ':' + ts.substr(10,2) + ':' + ts.substr(12,2) ;
	} ,
	
	canUserStopBatch : function ( batch_user_name ) {
		var me = this ;
		if ( typeof me.oauth.query == 'undefined' ) return false ;
		if ( typeof me.oauth.query.userinfo == 'undefined' ) return false ;
		if ( me.oauth.query.userinfo.name == batch_user_name ) return true ;
		if ( typeof me.oauth.query.userinfo.groups == 'undefined' ) return false ;
		var ret = false ;
		$.each ( me.oauth.query.userinfo.groups , function ( k , v ) {
			if ( v == 'sysop' ) ret = true ;
		} ) ;
		return ret ;
	} ,
	
	safeHTML : function ( h ) {
		return h.replace(/</,'&lt').replace(/>/,'&gt').replace(/&/,'&amp;')
	} ,
	
	getBatchButtons : function ( d2 ) {
		var me = this ;
		var h = '' ;
		var batch_id = d2.batch.id ;
		if ( me.canUserStopBatch(d2.batch.user_name) ) {
			h += "<div>" ;

			h += "<button batch='"+batch_id+"' class='stop-batch btn btn-danger btn-lg'" ;
			if ( d2.batch.status != 'RUN' ) h += " style='display:none'" ;
			h += ">STOP THIS BATCH RUN NOW!</button>" ;

			h += "<button batch='"+batch_id+"' class='start-batch btn btn-success btn-lg'" ;
			if ( d2.batch.status != 'STOP' ) h += " style='display:none'" ;
			h += ">(Re-)start this batch</button>" ;

			h += "</div>" ;
		}
		return h ;
	} ,
	
	updateBatchStatus : function () {
		var me = this ;
		if ( typeof me.run_state.batch_id == 'undefined' ) return ;
		$('#single_batch_busy').show() ;
		$.post ( me.api , {
			action:'get_batch_info',
			batch:me.run_state.batch_id
		} , function ( d ) {
			var batch_id = me.run_state.batch_id ;
			$('#single_batch_busy').hide() ;
			var d2 = d.data[batch_id] ;
			var h = '' ;
			if ( d2.name != '' ) h += "<h2>" + me.safeHTML(d2.batch.name) + "</h2>" ;
			h += "<p>User: <a href='https://www.wikidata.org/wiki/User:" + encodeURIComponent(d2.batch.user_name) + "' target='_blank'>" + me.safeHTML(d2.batch.user_name) + "</a>" ;
			h += " <small>(<a href='/quickstatements/#mode=batches&user="+encodeURIComponent(d2.batch.user_name)+"' onclick='QuickStatements.batchesByUser(\""+encodeURIComponent(d2.batch.user_name)+"\");return false'>batches by this user</a>)</small>" ;
			h += "</p>" ;
			h += "<p>Status: <b>" + d2.batch.status + "</b> <small>" + me.safeHTML(d2.batch.message) + "</small></p>" ;
			h += "<p>Created: " + me.ts2string(d2.batch.ts_created) + "</p>" ;
			h += "<p>Last change: " + me.ts2string(d2.batch.ts_last_change) + "</p>" ;
			h += "<table class='table table-condensed table-striped'>" ;
			h += "<thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>" ;
			$.each ( d2.commands , function ( k , v ) {
				h += "<tr><td>" + k + "</td><td>" + v + "</td></tr>" ;
			} ) ;
			h += "</tbody></table>" ;
			
			h += me.getBatchButtons ( d2 ) ;
			
			$('#single_batch_status').html ( h ) ;
			
			$('button.stop-batch').click ( function () {
				var button = $(this) ;
				var batch_id = button.attr('batch') ;
				$.post ( me.api , {
					action:'stop_batch',
					batch:batch_id
				} , function ( d ) {
					console.log ( d ) ;
					button.hide() ;
					$('button.start-batch[batch="'+batch_id+'"]').show() ;
				} ) ;
			} ) ;

			$('button.start-batch').click ( function () {
				var button = $(this) ;
				var batch_id = button.attr('batch') ;
				$.post ( me.api , {
					action:'start_batch',
					batch:batch_id
				} , function ( d ) {
					console.log ( d ) ;
					button.hide() ;
					$('button.stop-batch[batch="'+batch_id+'"]').show() ;
				} ) ;
			} ) ;

		} ) ;
	} ,
	
	showBatches : function () {
		var me = this ;

		var p = [ 'mode=batches' ] ;
		$.each ( me.run_state.filters , function ( k , v ) { p.push ( k+'='+encodeURIComponent(v) ) ; } ) ;
		location.hash = p.join('&') ;

		var params = { action:'get_batches_info' } ;
		$.each ( me.run_state.filters , function ( k , v ) { params[k] = v ; } ) ;
		$.post ( me.api , params , function ( d ) {
			var tmp = [] ;
			$.each ( d.data , function ( batch_id , d2 ) {
				tmp.push ( d2 ) ;
			} ) ;
			tmp.sort ( function ( a , b ) {
				return b.batch.ts_last_change*1 - a.batch.ts_last_change*1 ;
			} ) ;
			var h = "<table class='table table-condensed table-striped'>" ;
			h += "<thead><tr><th>#</th><th>User</th><th>Name</th><th>Status</th><th>Last change</th><th>Actions</th></tr></thead>" ;
			h += "<tbody>" ;
			$.each ( tmp , function ( row , d2 ) {
				var batch_id = d2.batch.id ;
				h += "<tr>" ;
				h += "<td><a href='#mode=batch&batch="+batch_id+"'>"+batch_id+"</td>" ;
				h += "<td nowrap><a href='https://www.wikidata.org/wiki/User:" + encodeURIComponent(d2.batch.user_name) + "' target='_blank'>" + me.safeHTML(d2.batch.user_name) + "</a></td>" ;
				h += "<td>" + me.safeHTML(d2.batch.name) + "</td>" ;
				h += "<td>" ;
				h += "<div>" + me.safeHTML(d2.batch.status) + " <small>" + me.safeHTML(d2.batch.message) + "</small></div>" ;
				var stati = [] ;
				$.each ( d2.commands , function ( k , v ) {
					stati.push ( k+':'+v ) ;
				} ) ;
				h += "<div style='font-size:9pt'>" + stati.sort().join(' | ') + "</div>" ;
				h += "</td>" ;
				h += "<td nowrap>" + me.ts2string(d2.batch.ts_last_change) + "</td>" ;
				h += "<td>" + me.getBatchButtons(d2) + "</td>" ;
				h += "</tr>" ;
			} ) ;
			h += "</tbody></table>" ;
			$('#multi_batch_status').html(h) ;
		} ) ;
	} ,
	
	batchesByUser : function ( user_name_encoded ) {
		var me = this ;
		me.run_state.filters = { user:decodeURIComponent(user_name_encoded) } ;
		me.switchMode ( 'batches' ) ;
	} ,
	
	showBatch : function () {
		var me = this ;
		location.hash = 'mode=batch&batch=' + me.run_state.batch_id ;
		$('#single_batch_number').text ( me.run_state.batch_id ) ;
		me.run_state.batch_watcher = setInterval ( function () { me.updateBatchStatus() ; } , me.batch_update_interval ) ;
		me.updateBatchStatus()
	} ,
	
	switchMode : function ( new_mode ) {
		var me = this ;
		if ( typeof new_mode == 'undefined' ) return ;
		me.mode = new_mode ;
		$('div.mode').hide() ;
		$('#mode_'+new_mode).show() ;
		if ( typeof me.run_state.batch_watcher != 'undefined' ) clearInterval ( me.run_state.batch_watcher ) ;
		if ( new_mode == 'batch' ) me.showBatch () ;
		if ( new_mode == 'batches' ) me.showBatches () ;
		if ( new_mode == 'token' ) me.getToken () ;
	} ,
	
	getToken : function () {
		var me = this ;
		var params = { action:'get_token' } ;
		console.log ( params ) ;
		$.post ( me.api , params , function ( d ) {
			$('#token').text ( d.data.token ) ;
		} ) ;
	} ,
	
	runInBackground : function () {
		var me = this ;
		var name = '' ;
		var res = prompt ( 'Enter a name for this batch (optional)' , '' ) ;
		if ( res != '' ) name = res ;
		$.post ( me.api , {
			action:'run_batch',
			name:name,
			commands : JSON.stringify(me.data.commands)
		} , function ( d ) {
		
			if ( d.status != 'OK' ) {
				alert ( d.status ) ;
				console.log ( d ) ;
				return ;
			}

			me.run_state = {
				running : true ,
				last_item : '' ,
				commands : { pending:0 , done:0 } ,
				batch_id : d.batch_id
			} ;
			
			me.switchMode ( 'batch' ) ;

		} ) ;
	} ,
	
	run : function ( in_background ) {
		var me = this ;
		if ( me.run_state.running ) return ; // Already running
		if ( typeof me.run_state.batch_watcher != 'undefined' ) clearInterval ( me.run_state.batch_watcher ) ;
		if ( in_background ) {
			me.runInBackground () ;
//			alert ( "Not implemented yet" ) ;
			return ;
		}
		me.run_state = {
			running : true ,
			last_item : '' ,
			commands : { pending:0 , done:0 }
		}
		$('#run_status').text ( '' ) ;
		$('#run_status_wrapper').show() ;
		$.each ( me.data.commands , function ( num , cmd ) {
			var s = cmd.status ;
			if ( typeof s == 'undefined' || s == '' ) s = 'pending' ;
			else {
				if ( s != 'done' ) { // Reset previous errors
					cmd.status = '' ;
					cmd.message = '' ;
//					var tabs = me.getCommandRowTabs ( cmdnum , cmd , dt ) ; // TODO visual update
//					dt.row(cmdnum).data(tabs).draw() ;
				}
			}
			if ( typeof me.run_state.commands[s] == 'undefined' ) me.run_state.commands[s] = 0 ;
			me.run_state.commands[s]++ ;
		} ) ;
		$('#stop_buttons button').prop ( 'disabled' , false ) ;
		$('#run_buttons button').prop ( 'disabled' , true ) ;
		$('#stop_buttons').show() ;
		if ( in_background ) {
//			alert ( "Not implemented yet" ) ;
		} else {
			me.runNextCommand() ;
		}
	} ,
	
	runNextCommand : function () {
		var me = this ;
		if ( !me.run_state.running ) return ; // Stopped
		var cmdnum ;
		$.each ( me.data.commands , function ( num , cmd ) {
			if ( typeof cmd.status != 'undefined' && cmd.status != '' ) return ;
			cmdnum = num ;
			return false ;
		} ) ;
		if ( typeof cmdnum == 'undefined' ) return me.stop() ; // All done
		me.runSingleCommand ( cmdnum ) ;
	} ,
	
	setCommandStatus : function ( cmdnum , status ) {
		var me = this ;
		var cmd = me.data.commands[cmdnum] ;
		cmd.status = 'running' ;
		me.updateCommandRow ( cmdnum ) ;
	} ,
	
	updateCommandRow : function ( cmdnum ) {
		var me = this ;
		var dt = $('#main_table').DataTable() ;
		var cmd = me.data.commands[cmdnum] ;
		var tabs = me.getCommandRowTabs ( cmdnum , cmd , dt ) ;
		dt.row(cmdnum).data(tabs).draw() ;
		me.tt.updateInterface ( dt ) ;
	} ,
	
	updateRunStatus : function () {
		var me = this ;
		var out = [] ;
		$.each ( me.run_state.commands , function ( status , count ) {
			out.push ( status+':'+count ) ;
		} ) ;
		var h = out.join('; ') ;
		$('#run_status').text ( h ) ;
	} ,
	
	runSingleCommand : function ( cmdnum ) {
		var me = this ;
		var cmd = me.data.commands[cmdnum] ;
		me.setCommandStatus ( cmdnum , 'running' ) ;
		me.updateRunStatus() ;
		$.post ( me.api , {
			action:'run_single_command',
			command : JSON.stringify(cmd) ,
			last_item : me.run_state.last_item
		} , function ( d ) {
			me.data.commands[cmdnum] = d.command ;
			if ( typeof d.command.item != 'undefined' ) me.run_state.last_item = d.command.item ;
			me.run_state.commands.pending-- ;
			if ( d.status == 'OK' ) {
				me.run_state.commands.done++ ;
			} else {
				if ( typeof me.run_state.commands[d.status] == 'undefined' ) me.run_state.commands[d.status] = 0 ;
				me.run_state.commands[d.status]++ ;
			}
			me.updateRunStatus() ;
			me.updateCommandRow ( cmdnum ) ;
			me.runNextCommand() ;
		} , 'json' ) ;
	} ,
	
	updateUserInfo : function () {
		var me = this ;
		var h = '' ;
		if ( me.oauth.is_logged_in ) {
			var username = me.oauth.query.userinfo.name ;
			h = "<span tt='welcome' tt1='" + me.htmlSafe(username) + "'></span>" ;
			$('#logged_in_actions').show() ;
			$('a[tt="show_your_last_batches"]').show().click ( function () {
				me.run_state.filters = { user:username } ;
				me.switchMode ( 'batches' ) ;
				return false ;
			} ) ;
		} else {
			h += "<a href='"+me.api+"?action=oauth_redirect' target='_blank' tt='login'></a> <span tt='login2'></span>" ;
		}
		$('#userinfo').html ( h ) ;
		me.tt.updateInterface($('#userinfo')) ;
	} ,
	
	showLastBatches : function () {
		var me = QuickStatements ;
		me.run_state.filters = {} ;
		me.switchMode ( 'batches' ) ;
	} ,
	
	getUrlVars : function () {
		var vars = {} ;
		var hash = window.location.href.slice(window.location.href.indexOf('#') + 1) ;
		if ( hash.match(/.3D/) ) hash = hash.replace(/\.3D/g,'=').replace(/\.26/g,'&') ; // Hack around MediaWiki botching the URL encoding when using [[:toollabs:]] syntax
		var hashes = hash.split('&');
		$.each ( hashes , function ( i , j ) {
			var hash = j.split('=');
			hash[1] += '' ;
			vars[hash[0]] = decodeURI(hash[1]).replace(/_/g,' ');
		} ) ;
		return vars;
	} ,
	
	onClickImportV1 : function () {
		var me = QuickStatements ;
		me.switchMode ( 'commands' ) ;
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
		$('span.update_label').each ( function () {
			var o = $(this) ;
			if ( o.text() != '' ) return ;
			var t = o.attr('tt') ;
			o.text ( me.tt.t(t) ) ;
		} ) ;
		$('a.wd_unlabeled').each ( function () {
			var a = $(this) ;
			var pq = a.attr('pq') ;
			if ( !pq.match(/^[PQ]\d+$/i) ) return ; // Paranoia
			to_update.push ( pq ) ;
			a.removeClass('wd_unlabeled').addClass('wd_pq').attr({title:pq}) ;
			if ( to_update.length >= 50 ) return false ; // Max
		} ) ;
		
		$('a.pq_edit_unlinked').each ( function () {
			var a = $(this) ;
			a.removeClass('pq_edit_unlinked') ;
			a.click ( function() { me.onClickEditPQ($(a.parents('div.pq_container').get(0))) ; return false } ) ;
		} ) ;
		
		if ( to_update.length > 0 ) {
			$.getJSON ( me.getSiteAPI()+'?action=wbgetentities&ids='+to_update.join('|')+"&format=json&callback=?" , function ( d ) {
				$.each ( d.entities , function ( pq , v ) {
					if ( typeof v.missing != 'undefined' ) {
						$('a.wd_pq[pq="'+pq+'"]').addClass('red') ;
						return ;
					}
					var label ;
					if ( typeof v.labels != 'undefined' ) {
						if ( typeof v.labels['en'] != 'undefined' ) {
							label = v.labels['en'].value ;
						}
					}
					if ( typeof label == 'undefined' ) return ;
					$('a.wd_pq[pq="'+pq+'"]').removeClass('red').text(label) ;
				} ) ;
			} ) ;
		}
		
		setTimeout ( function () { me.updateUnlabeledItems() } , 500 ) ;
	} ,
	
	onClickEditPQ : function ( container ) {
		var me = this ;
		$('button.cancel').click() ; // close all other open edit forms
		var pq = container.attr ( 'pq' ) ;
		container.find('div.pq_value').hide() ;
		container.find('div.pq_button').hide() ;
		var title = $(container.find('div.pq_value a')).text() ;
		var form = $(container.find('div.pq_form')) ;
		var type = me.types[pq.substr(0,1).toUpperCase()].type ;
		var h = '' ;
		h += "<div class='pq_typeahead' type='"+type+"' style='width:340px'>" ;
		h += "<div>" ;
		h += "<form class='form form-inline'>" ;
		h += "<input type='text' class='typeahead_input' style='width:250px' value='"+me.htmlSafe(title)+"' pq='"+pq+"'/>" ;
		h += "<input type='submit' tt_value='set' class='btn btn-primary' />" ;
		h += "<button class='cancel btn btn-default' tt='cancel_short'></button>" ;
		h += "</form>" ;
		h += "</div>" ;
		h += "<div><ul style='width:100%;overflow:auto;max-height:200px' class='pq_dropdown'></ul></div>" ;
		h += "</div>" ;
		form.html(h).show() ;
		me.tt.updateInterface(form) ;
		
		me.addTypeahead ( $(container.find('div.pq_typeahead')) ) ;
		$(container.find('form')).submit ( function () { me.onSubmitPQ ( container , true ) ; return false } ) ;
		$(container.find('button.cancel')).click ( function () { me.onSubmitPQ ( container , false ) ; return false } ) ;
	} ,
	
	updateRef : function ( json_string , value ) {
		var me = this ;
		if ( typeof json_string == 'undefined' ) return ;
		if ( json_string == '' ) return ;
		var j = JSON.parse ( json_string ) ;
		if ( typeof j == 'undefined' ) return ;
		if ( typeof j.cmdnum != 'undefined' ) {
			var i = me.data.commands[j.cmdnum] ;
			while ( ( m = j.attr.match ( /^(.+?)\.(.+)$/ ) ) != null ) {
				i = i[m[1]] ;
				j.attr = m[2] ;
			}
			i[j.attr] = value ;
		} else {
			console.log ( "COUND NOT STORE REF" , j , value ) ;
		}
	} ,
	
	onSubmitPQ : function ( container , do_store ) {
		var me = this ;
		var ta = $(container.find('div.pq_typeahead')) ;
		var input = $(container.find('input.typeahead_input')) ;
		var pq = input.attr('pq') ;
		var title = input.val() ;
		var pqv = container.find('div.pq_value') ;
		if ( do_store && pq != '' ) {
			me.updateRef ( container.attr('ref') , pq ) ; // store in data structure
			pqv.html ( me.renderPQvalue ( pq ) ) ;
			container.attr ( { pq:pq } ) ;
		}
		ta.html('').hide() ;
		pqv.show() ;
		container.find('div.pq_button').show() ;
	} ,
	
	addTypeahead : function ( o ) {
		var me = this ;
		me.lastTypeahead = '' ;
		var input = $(o.find('input.typeahead_input')) ;
		var select = $(o.find('ul.pq_dropdown')) ;
		input.keyup ( function () {
			me.typeAhead ( o , input , select ) ;
		} ) ;
		input.focus() ;
		me.typeAhead ( o , input , select ) ;
	} ,
	
	typeAhead : function ( o , input , select ) {
		var me = this ;
		var type = o.attr('type') ;
		var l = me.lang() ;
		var s = input.val() ;
		if ( s == me.lastTypeahead ) return ;
		me.lastTypeahead = s ;
		select.html ( '' ) ;
		$.getJSON ( me.getSiteAPI()+"?callback=?" , {
			action:'wbsearchentities',
			search:s,
			language:l,
			type:type,
			format:'json'
		} , function ( d ) {
			var h = '' ;
			$.each ( d.search , function ( k , v ) {
				h += "<li style='cursor:pointer' pq='" + v.title + "'>" ;
				h += "<div><b>" + me.htmlSafe(v.label) + "</b> <small>(" + v.title + ")</small></div>" ;
				h += "<div><small>" + me.htmlSafe(v.description||'') + "</small></div>" ;
				h += "</li>" ;
			} ) ;
			select.html ( h ) ;
			select.find('li').click ( function () {
				var a = $(this) ;
				var pq = a.attr('pq').replace(/^.+:/,'') ;
				var container = $(input.parents('div.pq_container').get(0)) ;
				input.attr ( { pq:pq } ) ;
				me.onSubmitPQ ( container , true ) ;
			} ) ;
		} ) ;
	} ,

	importFromV1 : function ( v1 ) {
		var me = this ;
		if ( v1.length < 1000 ) location.hash = 'v1='+v1 ;
		$.post ( me.api , {
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
	
	renderPQvalue : function ( i ) {
		var me = this ;
		var html = '???' ;
		if ( i == 'LAST' ) {
			html = '<i>LAST ITEM</i>' ;
		} else if ( i.match ( /^[PQ]\d+$/i ) ) { // PQ
			
			var letter = i.substr(0,1).toUpperCase() ;
			html = "<a class='wd_unlabeled' pq='"+i+"' href='" + me.getSitePageURL(me.types[letter].ns_prefix+i) + "' target='_blank'>" + i + "</a> <small>[" + i + "]</small>"

		} else { // DUNNO
			html = me.htmlSafe ( i ) ;
		}
		return html ;
	} ,
	
	renderPQ : function ( i , ref ) {
		var me = this ;
		html = "<div class='pq_container' pq='"+me.htmlSafe(i)+"'" ;
		if ( typeof ref != 'undefined' ) html += " ref='" + me.htmlSafe(JSON.stringify(ref)) + "'" ;
		else console.log ( "NO PQ REF" , i ) ;
		html += "><div class='pq_value'>" + me.renderPQvalue(i) + "</div>" ;
		html += "<div class='pq_button'><a class='pq_edit_unlinked'><img src='https://upload.wikimedia.org/wikipedia/commons/thumb/4/43/PICOL_Edit.svg/16px-PICOL_Edit.svg.png' border=0 /></a></div>" ;
		html += "<div class='pq_form'>FORM</div>" ;
		html += "</div>" ;
		return html ;
	} ,
	
	htmlSafe : function ( s ) {
		var me = this ;
		if ( typeof s == 'undefined' ) return '' ;
		var html_safe = (''+s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;').replace(/\//g,'&#x2F;') ;
		return html_safe ;
	} ,
	
	renderString : function ( s , ref ) {
		var me = this ;
		var h = '' ;
		h += "<div class='string_container'" ;
		if ( typeof ref != 'undefined' ) h += " ref='" + me.htmlSafe(JSON.stringify(ref)) + "'" ;
		h += ">" ;
		h += me.htmlSafe ( s ) ;
		h += "</div>" ;
		return h ;
	} ,
	
	renderTime : function ( v , ref ) {
		var me = this ;
		var h = '' ;
		h += "<div class='time_container'" ;
		if ( typeof ref != 'undefined' ) h += " ref='" + me.htmlSafe(JSON.stringify(ref)) + "'" ;
		h += ">" ;
		h += v.time + "/" + v.precision ; // TODO
		h += "</div>" ;
		return h ;
	} ,
	
	renderCoordinate : function ( v , ref ) {
		var me = this ;
		var h = '' ;
		h += "<div class='coordinate_container'" ;
		if ( typeof ref != 'undefined' ) h += " ref='" + me.htmlSafe(JSON.stringify(ref)) + "'" ;
		h += ">" ;
		h += v.latitude + '/' + v.longitude  ; // TODO
		h += "</div>" ;
		return h ;
	} ,
	
	renderQuantity : function ( v , ref ) {
		var me = this ;
		var h = '' ;
		h += "<div class='quantity_container'" ;
		if ( typeof ref != 'undefined' ) h += " ref='" + me.htmlSafe(JSON.stringify(ref)) + "'" ;
		h += ">" ;
		h += me.htmlSafe(v.amount) ; // TODO
		var range = '' ;
		if ( typeof v.lowerBound != 'undefined' && v.amount*1 != v.lowerBound*1 ) range += me.htmlSafe(v.lowerBound)+'&ndash;' ;
		if ( typeof v.upperBound != 'undefined' && v.amount*1 != v.upperBound*1 ) range += '&ndash;'+me.htmlSafe(v.upperBound) ;
		range = range.replace ( /(&ndash;)+/ , '&ndash;' ) ;
		if ( range != '' ) h += '&nbsp;(' + range + ')' ;
		h += "</div>" ;
		return h ;
	} ,
	
	renderMonolingualtext : function ( v , ref ) {
		var me = this ;
		var h = '' ;
		h += "<div class='monolingualtext_container'" ;
		if ( typeof ref != 'undefined' ) h += " ref='" + me.htmlSafe(JSON.stringify(ref)) + "'" ;
		h += ">" ;
		h += me.htmlSafe(v.language) + ": " + me.htmlSafe(v.text) ;
		h += "</div>" ;
		return h ;
	} ,
	
	renderValue : function ( v , ref ) {
		var me = this ;
		if ( typeof v.type == 'undefined' ) return "<i>" + JSON.stringify(v) + "</i>" ;
		if ( v.type == 'wikibase-entityid' ) {
			ref.attr += '.value.id' ;
			return me.renderPQ ( v.value.id , ref ) ;
		}
		if ( v.type == 'string' ) return me.renderString ( v.value , ref ) ;
		if ( v.type == 'time' ) return me.renderTime ( v.value , ref ) ;
		if ( v.type == 'globecoordinate' ) return me.renderCoordinate ( v.value , ref ) ;
		if ( v.type == 'quantity' ) return me.renderQuantity ( v.value , ref ) ;
		if ( v.type == 'monolingualtext' ) return me.renderMonolingualtext ( v.value , ref ) ;
		return "<i>" + JSON.stringify(v) + "</i>" ;
	} ,
	
	renderAction : function ( cmd ) {
		var ret = cmd.action.toUpperCase() ;
		if ( typeof cmd.what != 'undefined' && cmd.what != 'statement' ) ret += " " + cmd.what.toUpperCase() ;
		return ret ;
	} ,
	
	renderQualifier : function ( qualifier , ref ) {
		var me = this ;
		var h = '' ;
		ref.attr = 'qualifier.prop' ;
		h += "<div>" + me.renderPQ ( qualifier.prop , ref ) + "</div>" ;
		ref.attr = 'qualifier.value' ;
		h += "<div>" + me.renderValue ( qualifier.value , ref ) + "</div>" ;
		return h ;
	} ,
	
	renderSources : function ( sources , ref ) {
		var me = this ;
		var h = '' ;
		$.each ( sources , function ( k , v ) {
			if ( k > 0 ) h += "<hr/>" ;
			ref.attr = 'sources.'+k+'.prop' ;
			h += "<div>" + me.renderPQ ( v.prop , ref ) + "</div>" ;
			ref.attr = 'sources.'+k+'.value' ;
			h += "<div>" + me.renderValue ( v.value , ref ) + "</div>" ;
		} ) ;
		return h ;
	} ,
	
	wrapStatusAlert : function ( s , key , msg ) {
		var me = this ;
		var ret = '<div class="alert alert-'+key+'" style="padding:2px;margin:0px" role="alert"' ;
		if ( typeof msg != 'undefined' && msg != '' ) {
			ret += ' title="'+me.htmlSafe(msg)+'"' ;
			s += ' <sup>*</sup>' ;
		}
		ret += '>'+s+'</div>' ;
		return ret ;
	} ,
	
	renderStatus : function ( command ) {
		var me = this ;
		if ( typeof command.status == 'undefined' || command.status == '' ) return me.wrapStatusAlert ( "<span tt='pending' class='update_label'></span>" , 'info' , command.message ) ;
		var s = me.htmlSafe(command.status) ;
		if ( s == 'done' ) {
			s = me.wrapStatusAlert ( s , 'success' , command.message ) ;
		} else if ( s == 'error' ) {
			s = me.wrapStatusAlert ( s , 'danger' , command.message ) ;
		} else {
			s = me.wrapStatusAlert ( s , 'warning' , command.message ) ;
		}
		return s ;
	} ,
	
	getCommandRowTabs : function ( cmdnum , cmd , dt ) {
		var me = this ;
		var tabs = [ '' , '<span tt="unknown_command"></span>' , '' , '' , '' , '' ] ;
		tabs[0] = me.renderStatus ( cmd ) ;
		if ( (cmd.action=='add'||cmd.action=='remove') && ( cmd.what=='statement' || cmd.what=='qualifier' || cmd.what=='sources' ) ) {
			tabs[1] = me.renderAction ( cmd ) ;
			tabs[2] = me.renderPQ ( cmd.item , {cmdnum:cmdnum,attr:'item'} ) ;
			tabs[3] = me.renderPQ ( cmd.property , {cmdnum:cmdnum,attr:'property'} ) ;
			tabs[4] = me.renderValue ( cmd.datavalue , {cmdnum:cmdnum,attr:'datavalue'} ) ;
			if ( cmd.what == 'qualifier' ) tabs[5] = me.renderQualifier ( cmd.qualifier , {cmdnum:cmdnum} ) ;
			if ( cmd.what == 'sources' ) tabs[5] = me.renderSources ( cmd.sources , {cmdnum:cmdnum} ) ;
		} else if ( (cmd.action=='add'||cmd.action=='remove') && ( cmd.what=='label' || cmd.what=='description' || cmd.what=='alias' || cmd.what=='sitelink' ) ) {
			tabs[1] = me.renderAction ( cmd ) ;
			tabs[2] = me.renderPQ ( cmd.item , {cmdnum:cmdnum,attr:'item'} ) ;
			if ( cmd.what == 'sitelink' ) {
				tabs[3] = me.renderString ( cmd.site , {cmdnum:cmdnum,attr:'site'} ) ;
			} else {
				tabs[3] = me.renderString ( cmd.language , {cmdnum:cmdnum,attr:'language'} ) ;
			}
			tabs[4] = me.renderString ( cmd.value , {cmdnum:cmdnum,attr:'value'} ) ;
		} else if ( cmd.action == 'create' ) {
			tabs[1] = me.renderAction ( cmd ) ;
			tabs[2] = cmd.type ;
		} else { // Unknown
			tabs[5] = JSON.stringify(cmd) ;
		}
		return tabs ;
	} ,
	
	addCommandToTable : function ( cmdnum , cmd , dt ) {
		var me = this ;
		var tabs = me.getCommandRowTabs ( cmdnum , cmd , dt ) ;
		dt.row.add ( tabs ) ;
	} ,
	
	setupTableFromCommands : function () {
		var me = this ;
		var dt = $('#main_table').DataTable() ;
		$.each ( me.data.commands , function ( cmdnum , cmd ) {
			me.addCommandToTable ( cmdnum , cmd , dt ) ;
		} ) ;
		dt.draw(false) ;
		me.tt.updateInterface ( dt ) ;
	} ,

	fin:''
}


$(document).ready ( function () {
	QuickStatements.init() ;
} ) ;
