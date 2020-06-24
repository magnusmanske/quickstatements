<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR); // 
ini_set('display_errors', 'On');

if ( !isset($_REQUEST['openpage']) ) {
	header('Content-type: application/json; charset=UTF-8');
	header("Cache-Control: no-cache, must-revalidate");
}

require_once ( 'quickstatements.php' ) ;

@ini_set( 'upload_max_size' , '64M' );
@ini_set( 'post_max_size', '64M');
#@ini_set( 'max_execution_time', '300' )

function fin ( $status = '' ) {
	global $out ;
	if ( $status != '' ) $out['status'] = $status ;
	print json_encode ( $out ) ; // , JSON_PRETTY_PRINT 
	exit ( 0 ) ;
}

function get_origin() {
	$origin = '' ;
	if (array_key_exists('HTTP_ORIGIN', $_SERVER)) $origin = $_SERVER['HTTP_ORIGIN'];
	else if (array_key_exists('HTTP_REFERER', $_SERVER)) $origin = $_SERVER['HTTP_REFERER'];
	else $origin = $_SERVER['REMOTE_ADDR'];
	return $origin ;
}

function validate_origin() {
	global $qs ;
	if ( !isset($qs->config) ) return ;
	if ( !isset($qs->config->valid_origin) ) return ;
	if ( $qs->config->valid_origin == '' ) return ;
	$valid_origin = $qs->config->valid_origin ;
	if ( !is_array($valid_origin) ) $valid_origin = [ $valid_origin ] ;
	$origin = get_origin() ;
	if ( in_array($origin,$valid_origin) ) return ; // OK
	fin('Invalid origin');
}

$qs = new QuickStatements ;
$out = [ 'status' => 'OK' ] ;
$action = get_request ( 'action' , '' ) ;

if ( isset ( $_REQUEST['oauth_verifier'] ) ) {
	$oa = $qs->getOA() ; // Answer to OAuth
	header( "Location: " . $qs->getToolBase() );
	exit(0) ;
}

if ( $action == 'import' ) {

	ini_set('memory_limit','2500M');

	$format = get_request ( 'format' , 'v1' ) ;
	$username = get_request ( 'username' , '' ) ;
	$token = get_request ( 'token' , '' ) ;
	$temporary = get_request ( 'temporary' , false ) ;
	$openpage = get_request ( 'openpage' , 0 ) * 1 ;
	$submit = get_request ( 'submit' , false ) ;
	$data = get_request ( 'data' , '' ) ;
	$compress = get_request ( 'compress' , 1 ) * 1 ;
	$site = get_request ( 'site' , '' ) ;
	$out = $qs->importData ( $data , $format , false ) ;
	if ( $compress ) {
		$qs->use_command_compression = true ;
		$out['data']['commands'] = $qs->compressCommands ( $out['data']['commands'] ) ;
	}
	$out['debug']['format'] = $format ;
	$out['debug']['temporary'] = $temporary ;
	$out['debug']['openpage'] = $openpage ;
	if ( $temporary ) {
		$dir = './tmp' ;
		if ( !file_exists($dir) ) mkdir ( $dir ) ;
		$filename = tempnam ( $dir , 'qs_' ) ;
		$handle = fopen($filename, "w");
		fwrite($handle, json_encode($out) );
		fclose($handle);
		$out['data'] = preg_replace ( '|^.+/|' , '' , $filename ) ;

		if ( $openpage ) {
			$url = "./#/batch/?tempfile=" . urlencode ( $out['data'] ) ;
			if ( $site != '' ) $url .= "&site=" . urlencode($site) ;
			print "<html><head><meta http-equiv=\"refresh\" content=\"0;URL='{$url}'\" /></head><body></body></html>" ;
			exit(0);
		}

		fin() ;
	}

	if ( $submit ) {
		$batchname = get_request ( 'batchname' , '' ) ;

		if ( $site != '' ) {
			$qs->config->site = $site ;
			$out['site'] = $site ;
		}
		$user_id = $qs->getUserIDfromNameAndToken ( $username , $token ) ;
		if ( !isset($user_id) ) {
			unset ( $out['data'] ) ;
			fin ( "User name and token do not match" ) ;
		}
		if ( !$qs->fillOA ( $user_id ) ) {
			unset ( $out['data'] ) ;
			fin ( "Problem generating OAuth signature; user '{}' needs to have submitted a batch namually at least once before" ) ;
		}

		$batch_id = $qs->addBatch ( $out['data']['commands'] , $user_id , $batchname , $site ) ;
		unset ( $out['data'] ) ;
		if ( $batch_id === false ) {
			$out['status'] = $qs->last_error_message ;
		} else {
			$out['batch_id'] = $batch_id ;
		}
	}

} else if ( $action == 'oauth_redirect' ) {

	$oa = $qs->getOA() ;
	$oa->doAuthorizationRedirect('https://quickstatements.toolforge.org/api.php') ;
	exit(0) ;

} else if ( $action == 'get_token' ) {

	$force_generate = get_request ( 'force_generate' , 0 ) * 1 ;
	$oa = $qs->getOA() ;
	$ili = $oa->isAuthOK() ; # Is Logged In
	$out['data'] = (object) [] ;
	if ( $ili ) {
		$cr = $oa->getConsumerRights() ;
		$user_name = $cr->query->userinfo->name ;
		$out['data']->token = $qs->generateToken ( $user_name , $force_generate ) ;
	}
	$out['data']->is_logged_in = $ili ;

} else if ( $action == 'is_logged_in' ) {

	$oa = $qs->getOA() ;
	$ili = $oa->isAuthOK() ;
	$out['data'] = (object) [] ;
	if ( $ili ) {
		$out['data'] = $oa->getConsumerRights() ;
	}
	$out['data']->is_logged_in = $ili ;

} else if ( $action == 'get_batch_info' ) {

	$batch = get_request ( 'batch' , '0' ) * 1 ;

	if ( $batch == 0 ) {
		$out['status'] = 'Missing batch number' ;
	} else {
		$out['data'] = $qs->getBatchStatus ( array($batch) ) ;
	}

} else if ( $action == 'get_batches_info' ) {

	$out['debug'] = $_REQUEST ;
	
	$user = get_request ( 'user' , '' ) ;
	$limit = get_request ( 'limit' , '20' ) * 1 ;
	$offset = get_request ( 'offset' , '0' ) * 1 ;
	
	$db = $qs->getDB() ;
	$sql = "SELECT DISTINCT batch.id AS id FROM batch" ;
	if ( $user != '' ) $sql .= ",{$qs->auth_db}.user" ;

	$conditions = [] ;
	if ( $user != '' ) $conditions[] = "user.id=batch.user AND user.name='" . $db->real_escape_string($user) . "'" ;
	if ( count($conditions) > 0 ) $sql .= ' WHERE ' . implode ( ' AND ' , $conditions ) ;

	$sql .= " ORDER BY ts_last_change DESC" ;
	$sql .= " LIMIT $limit" ;
	if ( $offset != 0 ) $sql .= " OFFSET $offset" ;

	if(!$result = $db->query($sql)) {
		$out['status'] = $db->error ;
	} else {
		$batches = [] ;
		while ( $o = $result->fetch_object() ) $batches[] = $o->id ;
		$out['data'] = $qs->getBatchStatus ( $batches ) ;
	}

} else if ( $action == 'get_commands_from_batch' ) {

	$batch_id = get_request ( 'batch' , 0 ) * 1 ;
	$start = get_request ( 'start' , 0 ) * 1 ;
	$limit = get_request ( 'limit' , 0 ) * 1 ;
	$filter = get_request ( 'filter' , '' ) ;

	$db = $qs->getDB() ;
	$sql = "SELECT * FROM command WHERE batch_id={$batch_id} AND num>={$start}" ; // num BETWEEN {$start} AND {$end}
	if ( $filter != '' ) {
		$filter = explode ( ',' , $filter ) ;
		foreach ( $filter AS $k => $v ) {
			$v = $db->real_escape_string ( trim ( strtoupper ( $v ) ) ) ;
			$filter[$k] = $v ;
		}
		$sql .= " AND `status` IN ('" . implode("','",$filter) . "')" ;
	}
	$sql .= " ORDER BY num LIMIT {$limit}" ;

	if(!$result = $db->query($sql)) {
		$out['status'] = $db->error ;
	} else {
		$batches = [] ;
		$out['data'] = [] ;
		while ( $o = $result->fetch_object() ) {
			$o->json = json_decode ( $o->json ) ;
			$out['data'][] = $o ;
		}
	}

} else if ( $action == 'run_single_command' ) {

	validate_origin();
	$site = strtolower ( trim ( get_request ( 'site' , '' ) ) ) ;
	if ( !$qs->setSite ( $site ) ) {
		$out['status'] = "Error while setting site '{$site}': " . $qs->last_error_message ;
	} else {

		$oa = $qs->getOA() ;
		$oa->delay_after_create_s = 0 ;
		$oa->delay_after_edit_s = 0 ;

		$qs->last_item = get_request ( 'last_item' , '' ) ;
		$command = json_decode ( get_request ( 'command' , '' ) ) ;
		if ( $command == null ) {
			$out['status'] = 'Bad command JSON' ;
			$out['debug'] = get_request ( 'command' , '' ) ;
		} else {
			$out['command'] = $qs->runSingleCommand ( $command ) ;
			$out['last_item'] = $qs->last_item ;
		}
	}

} else if ( $action == 'start_batch' or $action == 'stop_batch' ) {

	$batch_id = get_request ( 'batch' , 0 ) * 1 ;
	
	$res = false ;
	if ( $action == 'start_batch' ) $res = $qs->userChangeBatchStatus ( $batch_id , 'INIT' ) ;
	if ( $action == 'stop_batch' )  $res = $qs->userChangeBatchStatus ( $batch_id , 'STOP' ) ;
	
	if ( !$res ) {
		$out['status'] = $qs->last_error_message ;
	}

} else if ( $action == 'run_batch' ) {

	$user_id = $qs->getCurrentUserID() ;
	$name = trim ( get_request ( 'name' , '' ) ) ;
	$site = strtolower ( trim ( get_request ( 'site' , '' ) ) ) ;
	if ( $user_id === false ) {
		$out['status'] = $qs->last_error_message ;
	} else {
		$commands = json_decode ( get_request('commands','[]') ) ;
		$batch_id = $qs->addBatch ( $commands , $user_id , $name , $site ) ;
		if ( $batch_id === false ) {
			$out['status'] = $qs->last_error_message ;
		} else {
			$out['batch_id'] = $batch_id ;
		}
	}
//	$qs->addBatch ( $commands ) ;

} else if ( $action == 'get_batch' ) {

	$id = get_request ( 'id' , '' ) ;
	$out['id'] = $id ;
	$out['data'] = $qs->getBatch ( $id ) ;

} else if ( $action == 'reset_errors' ) {

	$batch_id = get_request ( 'batch_id' , 0 ) * 1 ;
	if ( $batch_id <= 0 ) fin("Bad batch ID #{$batch_id}") ;

	$out['init'] = 0 ;
	$db = $qs->getDB() ;
	$sql = "SELECT * FROM command WHERE batch_id={$batch_id} AND `status`='ERROR'" ;
	if(!$result = $db->query($sql)) fin($db->error) ;

	$ids = [] ;
	while ( $o = $result->fetch_object() ) {
		if ( stristr($o->message,'no-such-entity') ) continue ; // No such item exists, no point in re-trying
		if ( !isset($o->json) ) continue ; // No actual command
		$j = @json_decode ( $o->json ) ;
		if ( !isset($j) or $j === null ) continue ; // Bad JSON
		if ( isset($j->item) and $j->item == 'LAST' ) continue ; // Don't know which item to re-apply for
		if ( isset($j->action) and $j->action == 'CREATE' and !isset($j->data) ) continue ; // Empty CREATE command
		$ids[] = $o->id ;
	}

	if ( count($ids) > 0 ) {
		$sql = "UPDATE command SET `status`='INIT' WHERE id IN (" . implode(',',$ids) . ")" ;
		$out['sql'] = $sql ;
		if(!$result = $db->query($sql)) fin($db->error) ;

		$out['init'] = count($ids) ;
		$res = $qs->userChangeBatchStatus ( $batch_id , 'INIT' ) ;
		if ( !$res ) {
			$out['status'] = $qs->last_error_message ;
		}
	}

}

fin();

?>