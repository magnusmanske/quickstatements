<?PHP

require_once ( 'php/common.php' ) ;
require_once ( 'php/wikidata.php' ) ;
require_once ( 'php/oauth.php' ) ;
require_once ( '/data/project/quickstatements/vendor/autoload.php' ) ;

// QuickStatements class

class QuickStatements {

	public $last_item = '' ;
	public $wd ;
	public $oa ;
	public $use_oauth = true ;

	protected $bot_config_file = '/data/project/quickstatements/bot.ini' ;
	protected $actions_v1 = array ( 'L'=>'label' , 'D'=>'description' , 'A'=>'alias' , 'S'=>'sitelink' ) ;
	protected $site = 'wikidata' ;
	protected $sites ;
	
	public function QuickStatements () {
		$this->sites = json_decode ( file_get_contents ( 'https://tools.wmflabs.org/quickstatements/sites.json' ) ) ;
		$this->wd = new WikidataItemList () ;
	}
	
	protected function isBatchRun () {
		return false ; // TODO FIXME
	}
	
	public function getOA() {
		if ( !isset($this->oa) ) {
			$this->oa = new MW_OAuth ( 'quickstatements' , 'wikidata' , 'wikidata' ) ;
		}
		return $this->oa ;
	}
	
	public function importData ( $data , $format , $persistent ) {
		$ret = array ( "status" => "OK" ) ;
		// TODO persistent
		if ( $format == 'v1' ) $this->importDataFromV1 ( $data , $ret ) ;
		else $ret['status'] = "ERROR: Unknown format $format" ;
		return $ret ;
	}
	
	
	protected function createNewItem ( $command ) {
		$this->runAction ( array (
			'action' => 'wbeditentity' ,
			'new' => $command->type ,
			'data' => '{}' , //json_encode ( (object) array() ) ,
			'summary' => ''
		) , $command ) ;
		if ( $command->status != 'done' ) return $command ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function isProperty ( $p ) {
		if ( !isset($p) ) return false ;
		if ( !preg_match ( '/^[P]\d+$/i' , $p ) ) return false ;
		return true ;
	}
	
	protected function getStatementID ( $command ) {
		if ( !$this->isProperty ( $command->property ) ) return ;
		if ( !isset($command->datavalue) ) return ;
		$q = $command->item ;
		$this->wd->loadItem ( $q ) ;
		if ( !$this->wd->hasItem($q) ) return ;
		$i = $this->wd->getItem ( $q ) ;
		$claims = $i->getClaims ( $command->property ) ;
		foreach ( $claims AS $c ) {
			if ( $this->compareDatavalue ( $c->mainsnak->datavalue , $command->datavalue ) ) return $c->id ;
		}
	}
	
	// Return true if both datavalues are the same (for any given value of same...), or false otherwise
	protected function compareDatavalue ( $d1 , $d2 ) {
		if ( $d1->type != $d2->type ) return false ;
		if ( $d1->type == 'string' ) return $d1->value == $d2->value ;
		if ( $d1->type == 'quantity' ) return $d1->value->amount*1 == $d2->value->amount*1 ;
		if ( $d1->type == 'time' ) return $d1->value->time == $d2->value->time and $d1->value->calendarmodel == $d2->value->calendarmodel and $d1->value->precision == $d2->value->precision ;
		if ( $d1->type == 'globecoordinate' ) return $d1->value->latitude == $d2->value->latitude and $d1->value->longitude == $d2->value->longitude and $d1->value->globe == $d2->value->globe ;
		if ( $d1->type == 'monolingualtext' ) return $d1->value->text == $d2->value->text and $d1->value->language == $d2->value->language ;

		$et = 'entity-type' ;
		$nid = 'numeric-id' ;
		if ( $d1->type == 'wikibase-entityid' ) {
			if ( $d1->value->$et != $d2->value->$et ) return false ;
			if ( isset($d1->value->$nid) and isset($d2->value->$nid) AND $d1->value->$nid==$d2->value->$nid ) return true ;
			if ( isset($d1->value->id) and isset($d2->value->id) AND $d1->value->id==$d2->value->id ) return true ;
			return false ;
		}
		
		return false ; // Can't determine type
	}
	
	protected function getSite () {
		$site = $this->site ;
		return $this->sites->$site ;
	}
	
	protected function getBotAPI () {
		if ( isset($this->bot_api) and $this->bot_api->isLoggedIn() ) return $this->bot_api ;

		$api_url = 'https://' . $this->getSite()->server . '/w/api.php' ;
		$config = parse_ini_file ( $this->bot_config_file ) ;
		$api = new \Mediawiki\Api\MediawikiApi( $api_url );
		if ( !$api->isLoggedin() ) {
			$x = $api->login( new \Mediawiki\Api\ApiUser( $config['user'], $config['pass'] ) );
			if ( !$x ) return false ;
		}
		return $api ;
		
	}
	
	protected function runBotAction ( $params_orig ) {
		$params = array() ;
		foreach ( $params_orig AS $k => $v ) $params[$k] = $v ; // Copy to array, and for safekeeping original
		$this->last_result = (object) array() ;
		if ( !isset($params['action']) ) return false ;
		$action = $params['action'] ;
		unset ( $params['action'] ) ;

		$api = $this->getBotAPI() ;
		$params['token'] = $api->getToken() ;

		try {
			$x = $api->postRequest( new \Mediawiki\Api\SimpleRequest( $action, $params ) );
			if ( isset($x) ) {
				$this->last_result = json_decode ( json_encode ( $x ) ) ; // Casting to object
			}
//			} else return false ; // TODO is that correct?
		} catch (Exception $e) {
			$this->last_result->error = (object) array ( 'info' => $e->getMessage() ) ;
			return false ;
		}
		return true ;
	}
	
	protected function runAction ( $params , &$command ) {
		$params = (object) $params ;
		$summary = '#quickstatements' ;
		if ( isset($params->summary) and $params->summary != '' ) $summary .= '; ' . $params->summary ;
		$params->summary = $summary ;
		$params->bot = 1 ;
		
		$result = (object) array() ;
		$status = false ;
		if ( $this->use_oauth ) {
			$oa = $this->getOA() ;
			$status = $oa->genericAction ( $params ) ;
			if ( isset($oa->last_res) ) $result = $oa->last_res ;
		} else {
			$status = $this->runBotAction ( $params ) ;
			if ( isset($this->last_result) ) $result = $this->last_result ;
		}
		
		$command->run = $params ; // DEBUGGING INFO
		if ( $status ) {
			$command->status = 'done' ;
			$new = 'new' ;
			if ( $params->action == 'wbeditentity' and isset($params->$new) ) {
				$command->item = $result->entity->id ; // "Last item"
			}
		} else {
			$command->status = 'error' ;
			if ( isset($result->error) and isset($result->error->info) ) $command->message = $result->error->info ;
			else $command->message = json_encode ( $result ) ;
		}
	}
	
	protected function getDatatypeForProperty ( $property ) {
		$this->wd->loadItem ( $property ) ;
		if ( !$this->wd->hasItem($property) ) return ;
		$i = $this->wd->getItem ( $property ) ;
		return $i->j->datatype ;
	}
	
	protected function commandError ( $command , $message ) {
		$command->status = 'error' ;
		if ( isset($message) and $message != '' ) $command->message = $message ;
		return $command ;
	}
	
	protected function commandDone ( $command , $message ) {
		$command->status = 'done' ;
		if ( isset($message) and $message != '' ) $command->message = $message ;
		return $command ;
	}
	
	protected function getSnakType ( $datavalue ) {
		return 'value' ; // TODO novalue/somevalue
	}
	
	protected function getPrefixedID ( $q ) {
		$q = trim ( strtoupper ( $q ) ) ;
		// TODO generic
		if ( preg_match ( '/^P\d+$/' , $q ) ) return "Property:$q" ;
		return $q ;
	}
	
	protected function commandAddStatement ( $command , $i , $statement_id ) {
		// Paranoia
		if ( isset($statement_id) ) return $this->commandDone ( $command , "Statement already exists as $statement_id" ) ;

		// Execute!
		$this->runAction ( array (
			'action' => 'wbcreateclaim' ,
			'entity' => $command->item ,
			'snaktype' => $this->getSnakType ( $command->datavalue ) ,
			'property' => $command->property ,
			'value' => json_encode ( $command->datavalue->value ) ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function commandAddQualifier ( $command , $i , $statement_id ) {
		// Paranoia
		if ( !isset($command->qualifier) ) return $this->commandError ( $command , "Incomplete command parameters" ) ;
		if ( !isset($command->qualifier->prop) ) return $this->commandError ( $command , "Incomplete command parameters" ) ;
		if ( !preg_match ( '/^P\d+$/' , $command->qualifier->prop ) ) return $this->commandError ( $command , "Invalid qualifier property {$command->qualifier->prop}" ) ;

		// Execute!
		$this->runAction ( array (
			'action' => 'wbsetqualifier' ,
			'claim' => $statement_id ,
			'property' => $command->qualifier->prop ,
			'value' => json_encode ( $command->qualifier->value->value ) ,
			'snaktype' => $this->getSnakType ( $command->qualifier->value ) ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function commandAddSources ( $command , $i , $statement_id ) {
		// Paranoia
		if ( !isset($command->sources) ) return $this->commandError ( $command , "Incomplete command parameters" ) ;
		if ( count($command->sources) == 0 ) return $this->commandError ( $command , "No sources to add" ) ;
		
		// Prep
		$snaks = array() ;
		foreach ( $command->sources AS $source ) {
			$s = array(
				'snaktype' => $this->getSnakType ( $source->value ) ,
				'property' => $source->prop ,
				'datavalue' => $source->value
			) ;
			$snaks[$source->prop][] = $s ;
		}

		// Execute!
		$this->runAction ( array (
			'action' => 'wbsetreference' ,
			'statement' => $statement_id ,
			'snaks' => json_encode ( $snaks ) ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function commandSetLabel ( $command , $i ) {
		// Paranoia
		if ( $i->getLabel ( $command->language , true ) == $command->value ) return $this->commandDone ( $command , 'Already has that label for {$command->language}' ) ;
		
		// Execute!
		$this->runAction ( array (
			'action' => 'wbsetlabel' ,
			'id' => $this->getPrefixedID ( $command->item ) ,
			'language' => $command->language ,
			'value' => $command->value ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function commandAddAlias ( $command , $i ) {
		// Paranoia TODO
		
		// Execute!
		$this->runAction ( array (
			'action' => 'wbsetaliases' ,
			'id' => $this->getPrefixedID ( $command->item ) ,
			'language' => $command->language ,
			'add' => $command->value ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function commandSetDescription ( $command , $i ) {
		// Paranoia
		if ( $i->getDesc ( $command->language , true ) == $command->value ) return $this->commandDone ( $command , 'Already has that description for {$command->language}' ) ;
		
		// Execute!
		$this->runAction ( array (
			'action' => 'wbsetdescription' ,
			'id' => $this->getPrefixedID ( $command->item ) ,
			'language' => $command->language ,
			'value' => $command->value ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	protected function commandSetSitelink ( $command , $i ) {
		// Paranoia
		$sl = $i->getSitelink ( $command->site ) ;
		if ( isset($sl) and str_replace(' ','_',$sl) == str_replace(' ','_',$command->value) ) return $this->commandDone ( $command , 'Already has that sitelink for {$command->site}' ) ;
		
		// Execute!
		$this->runAction ( array (
			'action' => 'wbsetsitelink' ,
			'id' => $this->getPrefixedID ( $command->item ) ,
			'linksite' => $command->site ,
			'linktitle' => $command->value ,
			'summary' => '' ,
			'baserevid' => $i->j->lastrevid
		) , $command ) ;
		if ( !$this->isBatchRun() ) $this->wd->updateItem ( $command->item ) ;
		return $command ;
	}
	
	public function runSingleCommand ( $command ) {
		$command->status = 'working' ;
		if ( isset($command->error) ) unset ( $command->error ) ;
		if ( $command->action == 'create' ) {
			return $this->createNewItem ( $command ) ;
		} else {
			$q = trim($command->item) ;
			if ( strtolower($q) == 'last' ) $q = $this->last_item ;
			if ( $q == '' ) return $this->commandError ( $command , 'No last item available' ) ;
			$command->item = $q ;
			$to_load = array ( $q ) ;
			if ( isset($command->property) and $this->isProperty($command->property) ) $to_load[] = $command->property ;
			$this->wd->loadItems ( $to_load ) ;
			if ( !$this->wd->hasItem($q) ) return $this->commandError ( $command , "Item $q is not available" ) ;
			$i = $this->wd->getItem ( $q ) ;
			
			if ( $command->action == 'add' ) {
			
				if ( $command->what == 'label' ) return $this->commandSetLabel ( $command , $i ) ;
				if ( $command->what == 'alias' ) return $this->commandAddAlias ( $command , $i ) ;
				if ( $command->what == 'description' ) return $this->commandSetDescription ( $command , $i ) ;
				if ( $command->what == 'sitelink' ) return $this->commandSetSitelink ( $command , $i ) ;

				$statement_id = $this->getStatementID ( $command ) ;
				if ( $command->what == 'statement' ) return $this->commandAddStatement ( $command , $i , $statement_id ) ;

				// THE FOLLOWING DEPEND ON AN EXISTING STATEMENT
				if ( !isset($statement_id) ) return $this->commandError ( $command , "Base statement not found" ) ;
				if ( $command->what == 'qualifier' ) return $this->commandAddQualifier ( $command , $i , $statement_id ) ;
				if ( $command->what == 'sources' ) return $this->commandAddSources ( $command , $i , $statement_id ) ;
				
			}
			
		}
		$command->status = 'error' ;
		$command->message = 'Incomplete or unknown command' ;
		return $command ;
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
	
	
	protected function getEntityType ( $q ) {
		$q = strtoupper ( trim ( $q ) ) ;
		if ( preg_match ( '/^Q\d+$/' , $q ) ) return 'item' ;
		if ( preg_match ( '/^P\d+$/' , $q ) ) return 'property' ;
		return 'unknown' ;
	}
	
	protected function parseValueV1 ( $v , &$cmd ) {
		$v = trim ( $v ) ;
		
		if ( preg_match ( '/^[PQ]\d+$/i' , $v ) ) { // ITEM/PROPERTY TODO generic
			$cmd['datavalue'] = array ( "type"=>"wikibase-entityid" , "value"=>array("entity-type"=>$this->getEntityType($v),"id"=>strtoupper($v)) ) ;
			return true ;
		}
		
		if ( preg_match ( '/^"(.*)"$/i' , $v , $m ) ) { // STRING
			$cmd['datavalue'] = array ( "type"=>"string" , "value"=>trim($m[1]) ) ;
			return true ;
		}

		if ( preg_match ( '/^([a-z_-]+):"(.*)"$/i' , $v , $m ) ) { // MONOLINGUALTEXT
			$cmd['datavalue'] = array ( "type"=>"monolingualtext" , "value"=>array("language"=>$m[1],"text"=>trim($m[2])) ) ;
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
				'latitude' => $m[1]*1 ,
				'longitude' => $m[2]*1 ,
				'precision' => 0.000001 ,
				'globe' => 'http://www.wikidata.org/entity/Q2'
			) ) ;
			return true ;
		}
		
		if ( preg_match ( '/^[\+\-]{0,1}\d+(\.\d+){0,1}$/' , $v ) ) { // Quantity
			$cmd['datavalue'] = array ( "type"=>"quantity" , "value"=>array(
				"amount" => $v*1 ,
				"unit" => "1"
			) ) ;
			return true ;
		}
		
		
		$cmd['datavalue'] = array ( "type"=>"unknown" , "text"=>$v ) ;
		$cmd['error'] = array('PARSE','Unknown V1 value format') ;
	}
	
} ;

?>