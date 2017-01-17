#!/usr/bin/php
<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);

require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;

function iterate() {
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
	}
}

while ( 1 ) {
	iterate() ;
	sleep ( 1 ) ;
}

?>