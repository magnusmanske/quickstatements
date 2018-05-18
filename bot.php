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

while ( 1 ) {
	$worked = iterate() ;
	if ( $worked == 0 ) sleep ( 5 ) ;
	else if ( $worked == 1 ) sleep ( 1 ) ;
	else sleep ( 1 ) ;
}

?>