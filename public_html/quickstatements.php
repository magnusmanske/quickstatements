<?PHP

require_once ( 'php/common.php' ) ;

// QuickStatements class

class QuickStatements {
	
	public function importData ( $data , $format , $persistent ) {
		$ret = array ( "status" => "OK" ) ;
		// TODO persistent
		if ( $format == 'v1' ) $this->importDataFromV1 ( $data , $ret ) ;
		else $ret['status'] = "ERROR: Unknown format $format" ;
		return $ret ;
	}
	
	
	
	
	protected function importDataFromV1 ( $data , &$ret ) {
		$ret['data']['commands'] = array() ;
		$rows = explode ( "\n" , $data ) ;
		foreach ( $rows as $row ) {
			$row = trim ( $row ) ;
			$cols = explode ( "\t" , $row ) ;
			$first = strtoupper(trim($cols[0])) ;
			$cmd = array() ;
			if ( preg_match ( '/^([PQ])(\d+)$/' , $first ) ) {
				$cmd = array ( 'action'=>'add' , 'item'=>$first , 'property'=>strtoupper(trim($cols[1])) , 'value'=>$this->parseValueV1($cols[2]) ) ;
			} else if ( $first == 'CREATE' ) {
				$cmd = array ( 'action'=>'create' , 'type'=>'item' ) ;
			}
		}
		if ( isset($cmd['action']) ) $ret['data']['commands'][] = $cmd ;
	}
	
	protected function parseValueV1 ( $v ) {
		$v = trim ( $v ) ;
		if ( preg_match ( '/^Q\d+$/i' , $v ) ) return array ( "type"=>"item" , "value"=>strtoupper($v) ) ;
		if ( preg_match ( '/^P\d+$/i' , $v ) ) return array ( "type"=>"property" , "value"=>strtoupper($v) ) ;
		if ( preg_match ( '/^"(.*)"$/i' , $v , $m ) ) return array ( "type"=>"string" , "value"=>trim($m[1]) ) ;
		return array ( "type"=>"unknown" , "text"=>$v ) ;
	}
	
} ;

?>