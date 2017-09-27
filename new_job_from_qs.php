#!/usr/bin/php
<?PHP

#error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
$user_id_magnus = 4420 ;

require_once ( '/data/project/quickstatements/public_html/quickstatements.php' ) ;

if ( !isset($argv[1]) ) die ( "Requires QS file as parameter\n" ) ;
$fn = $argv[1] ;
if ( !file_exists($fn) ) die ( "File $fn does not exist\n" ) ;
$commands = file_get_contents ( $fn ) ;

$batch_name = isset($argv[2]) ? $argv[2] : '' ;

$qs = new QuickStatements ;
$qs->use_command_compression = true ;
$j = $qs->importData ( $commands , 'V1' , true ) ;
$batch_id = $qs->addBatch ( $j['data']['commands'] , $user_id_magnus , $batch_name ) ;
print "Now as batch #$batch_id with " . count($j['data']['commands']) . " commands\n" ;

?>