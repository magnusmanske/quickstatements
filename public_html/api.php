<?PHP

error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
ini_set('display_errors', 'On');
header('Content-type: application/json; charset=UTF-8');
header("Cache-Control: no-cache, must-revalidate");

require_once ( 'quickstatements.php' ) ;

$qs = new QuickStatements ;
$out = array ( 'status' => 'OK' ) ;
$action = get_request ( 'action' , '' ) ;

if ( $action == 'import' ) {
	$format = get_request ( 'format' , '' ) ;
	$persistent = get_request ( 'persistent' , false ) ;
	$data = get_request ( 'data' , '' ) ;
	$out = $qs->importData ( $data , $format , $persistent ) ;
}

print json_encode ( $out , JSON_PRETTY_PRINT ) ; // FIXME

?>