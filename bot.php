#!/usr/bin/php
<?PHP

require_once ( __DIR__ . '/public_html/quickstatements.php' ) ;

function iterate() {
	$ret = 0 ;
	$qs = new QuickStatements ;
	$db = $qs->getDB() ;
	$sql = "SELECT id,status FROM batch WHERE status IN ('INIT','RUN')" ;
	if(!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']');
	while($o = $result->fetch_object()){
		$qs2 = new QuickStatements ;
		if ( $o->status == 'INIT' ) {
			if ( !$qs2->startBatch ( $o->id ) ) {
				print $qs2->last_error_message."\n" ;
				continue ;
			}
		}
		if ( !$qs2->runNextCommandInBatch ( $o->id ) ) print $qs2->last_error_message."\n" ;
		else $ret++ ;
	}
	return $ret ;
}

if ( isset($argv[1]) and $argv[1] == 'single_batch' ) {
	$min_sec_inactive = 60 * 60 ; # 1h
	$qs = new QuickStatements ;
	$db = $qs->getDB() ;

	$sql = "SELECT * FROM batch WHERE status IN ('INIT','RUN') ORDER BY ts_last_change" ;
	if ( isset($argv[2]) ) {
		$sql = "SELECT * FROM batch WHERE id=".($argv[2]*1) ;
	}
	if(!$result = $db->query($sql)) die('There was an error running the query [' . $db->error . ']');
	while ( $o = $result->fetch_object() ) {
		$ts_last_change = $o->ts_last_change ;
		if ( !preg_match ( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/' , $ts_last_change , $m ) ) continue ; #die ( "Bad time format in ts_last_change batch #{$o->id}\n" ) ;
		$ts_last_change = $m[1].'-'.$m[2].'-'.$m[3].' '.$m[4].':'.$m[5].':'.$m[6] ;
		$diff_sec = time() - strtotime ( $ts_last_change ) ;
	#print "{$ts_last_change}\n{$diff_sec}\n" ;
		if ( $diff_sec < $min_sec_inactive and $o->status != 'INIT' ) continue ; #exit ( 0 ) ; # Oldest batch is still too young
		print "Using {$o->id}\n" ;

		if ( $o->status == 'INIT' ) {
			if ( !$qs->startBatch ( $o->id ) ) {
				print "{$o->id}: " . $qs->last_error_message."\n" ;
				exit(0) ;
			}
		}

		while ( 1 ) {
			$qs2 = new QuickStatements ;
			if ( !$qs2->runNextCommandInBatch ( $o->id ) ) break ;
			$status = $qs2->getBatchStatus ( [$o->id] ) ;
			if ( $status[$o->id]['batch']->status != 'RUN' ) break ;
		}
		break ;
	}

	exit ( 0 ) ;
}

while ( 1 ) {
	$worked = iterate() ;
	if ( $worked == 0 ) sleep ( 5 ) ;
	else if ( $worked == 1 ) sleep ( 1 ) ;
	else sleep ( 1 ) ;
}

?>