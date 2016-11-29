<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR); // 
ini_set('display_errors', 'On');
header('Content-type: application/json; charset=UTF-8');
header("Cache-Control: no-cache, must-revalidate");

require_once ( 'quickstatements.php' ) ;

$qs = new QuickStatements ;
$out = array ( 'status' => 'OK' ) ;
$action = get_request ( 'action' , '' ) ;

if ( isset ( $_REQUEST['oauth_verifier'] ) ) {
	$oa = $qs->getOA() ; // Answer to OAuth
	header( "Location: https://tools.wmflabs.org/quickstatements" );
	exit(0) ;
}

if ( $action == 'import' ) {

	$format = get_request ( 'format' , '' ) ;
	$persistent = get_request ( 'persistent' , false ) ;
	$data = get_request ( 'data' , '' ) ;
	$out = $qs->importData ( $data , $format , $persistent ) ;

} else if ( $action == 'oauth_redirect' ) {

	$oa = $qs->getOA() ;
	$oa->doAuthorizationRedirect('api.php') ;
	exit(0) ;

} else if ( $action == 'is_logged_in' ) {

	$oa = $qs->getOA() ;
	$ili = $oa->isAuthOK() ;
	$out['data'] = (object) array() ;
	if ( $ili ) {
		$out['data'] = $oa->getConsumerRights() ;
	}
	$out['data']->is_logged_in = $ili ;
	

} else if ( $action == 'run_single_command' ) {

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

print json_encode ( $out , JSON_PRETTY_PRINT ) ; // FIXME

?>