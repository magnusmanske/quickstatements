<?PHP

require_once ( 'php/common.php' ) ;

// QuickStatements class

class QuickStatements {

	protected $actions_v1 = array ( 'L'=>'label' , 'D'=>'description' , 'A'=>'alias' , 'S'=>'sitelink' ) ;
	
	public function importData ( $data , $format , $persistent ) {
		$ret = array ( "status" => "OK" ) ;
		// TODO persistent
		if ( $format == 'v1' ) $this->importDataFromV1 ( $data , $ret ) ;
		else $ret['status'] = "ERROR: Unknown format $format" ;
		return $ret ;
	}
	
	
	
	
	protected function importDataFromV1 ( $data , &$ret ) {
		$ret['data']['commands'] = array() ;
		if ( strpos ( $data , "\n" ) === false ) $data = str_replace ( '||' , "\n" , $data ) ;
		if ( strpos ( $data , "\t" ) === false ) $data = str_replace ( '|' , "\t" , $data ) ;
		$rows = explode ( "\n" , $data ) ;
		foreach ( $rows as $row ) {
			$row = trim ( $row ) ;
			$cols = explode ( "\t" , $row ) ;
			$first = strtoupper(trim($cols[0])) ;
			$cmd = array() ;
			if ( count ( $cols ) >= 3 and ( preg_match ( '/^[PQ]\d+$/' , $first ) or $first == 'LAST' ) and preg_match ( '/^([P])(\d+)$/' , $cols[1] ) ) {
				$prop = strtoupper(trim($cols[1])) ;
				$cmd = array ( 'action'=>'add' , 'item'=>$first , 'property'=>$prop , 'what'=>'statement' ) ;
				$this->parseValueV1 ( $cols[2] , $cmd ) ;

				array_shift ( $cols ) ;
				array_shift ( $cols ) ;
				array_shift ( $cols ) ;
				while ( count($cols) >= 2 ) {
					$key = array_shift ( $cols ) ;
					$key = strtoupper ( trim ( $key ) ) ;
					$value = array_shift ( $cols ) ;
					if ( preg_match ( '/^([SP])(\d+)$/i' , $key , $m ) ) {
						$what = $m[1] == 'S' ? 'sources' : 'qualifier' ;
						$num = $m[2] ;
						
						// Store previous one, and reset
						$ret['data']['commands'][] = $cmd ;
						$last_command = $ret['data']['commands'][count($ret['data']['commands'])-1] ;
						
						$cmd = array ( 'action'=>'add' , 'item'=>$first , 'property'=>$prop , 'what'=>$what , 'datavalue'=>$last_command['datavalue'] ) ;
						$dummy = array() ;
						$this->parseValueV1 ( $value , $dummy ) ; // TODO transfer error message
						$dv = array ( 'prop' => 'P'.$num , 'value' => $dummy['datavalue'] ) ;
						if ( $what == 'sources' ) $cmd[$what] = array($dv) ;
						else $cmd[$what] = $dv ;
						if ( $what == 'sources' and $last_command['what'] == $what ) {
							$last_command[$what][] = $cmd[$what][0] ;
						}
					}
				}
				if ( count($cols) != 0 ) $cmd['error'] = 'Incomplete reference/qualifier list' ;
			} else if ( count ( $cols ) === 3 and ( preg_match ( '/^([PQ])(\d+)$/' , $first ) or $first == 'LAST' ) and preg_match ( '/^([LADS])([a-z_-]+)$/i' , $cols[1] , $m ) ) {
				$code = strtoupper ( $m[1] ) ;
				$lang = strtolower ( trim ( $m[2] ) ) ;
				$cmd = array ( 'action'=>'add' , 'what'=>$this->actions_v1[$code] , 'item'=>$first ) ;
				if ( $code == 'S' ) $cmd['site'] = $lang ;
				else $cmd['language'] = $lang ;
				$this->parseValueV1 ( $cols[2] , $cmd ) ;
				$cmd['value'] = $cmd['datavalue']['value'] ;
				unset ( $cmd['datavalue'] ) ;
			} else if ( $first == 'CREATE' ) {
				$cmd = array ( 'action'=>'create' , 'type'=>'item' ) ;
			}

			if ( isset($cmd['action']) ) $ret['data']['commands'][] = $cmd ;
		}
	}
	
	
	
	protected function parseValueV1 ( $v , &$cmd ) {
		$v = trim ( $v ) ;
		
		if ( preg_match ( '/^Q\d+$/i' , $v ) ) { // ITEM
			$cmd['datavalue'] = array ( "type"=>"item" , "value"=>strtoupper($v) ) ;
			return true ;
		}
		
		if ( preg_match ( '/^P\d+$/i' , $v ) ) { // PROPERTY
			$cmd['datavalue'] = array ( "type"=>"property" , "value"=>strtoupper($v) ) ;
			return true ;
		}
		
		if ( preg_match ( '/^"(.*)"$/i' , $v , $m ) ) { // STRING
			$cmd['datavalue'] = array ( "type"=>"string" , "value"=>trim($m[1]) ) ;
			return true ;
		}

		if ( preg_match ( '/^([+-]{0,1})(\d+)-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)Z\/{0,1}(\d*)$/i' , $v , $m ) ) { // TIME
			$prec = 9 ;
			if ( $m[8] != '' ) $prec = $m[8]*1 ;
			$cmd['datavalue'] = array ( "type"=>"time" , "value"=>array(
				'time' => preg_replace ( '/\/\d+$/' , '' , $v ) ,
				'timezone' => 0 ,
				'before' => 0 ,
				'after' => 0 ,
				'precision' => $prec ,
				'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727'
			) ) ;
			return true ;
		}
		
		if ( preg_match ( '/^\@\s*([+-]{0,1}[0-9.]+)\s*\/\s*([+-]{0,1}[0-9.]+)$/i' , $v , $m ) ) { // GPS
			$cmd['datavalue'] = array ( "type"=>"globecoordinate" , "value"=>array(
				'latitude' => $m[1] ,
				'longitude' => $m[2] ,
//				'precision' =>  ,
				'globe' => 'http://www.wikidata.org/entity/Q2'
			) ) ;
			return true ;
		}
		
		
		
		$cmd['datavalue'] = array ( "type"=>"unknown" , "text"=>$v ) ;
		$cmd['error'] = array('PARSE','Unknown V1 value format') ;
	}
	
} ;

?>