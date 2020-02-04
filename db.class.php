<?php
/**
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
*/
class DB{
	static  $method, $args, $conns, $driver, $table, $query, $logpath='/dblog';
	private $pdo, $sql, $stmt, $where;
	private $cols  =[];			// contains targated column names
	private $params=[];			// contains all params value of sql query
	private $values=[];			// contains just query values
	private $fields=[];			// contains fields name of active table
	private $facobj	= false; 	// Set true/false to return output object/array.
	private $prepare = true; 	// Set true/false for prepare pdo.
	private $bindParam = true;	// Set true/false for bind query parameters. if variable got changed the binded value will be changed as well.
	private $bindValue = true;	// Set true/false for bind binds just a value, it's like a a hard copy.
	private $transaction= true;// Set true to return object OR false to return array.
	function __construct(){
		$args = self::$args;
		$conn = self::$method==='conn' && !empty($args) ? $args : 'main';		//default connection name
		if(isset(self::$conns[$conn])){	// Get active pdo connection
			$this->pdo = self::$conns[$conn];
			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);// Set PDO error mode to exception
			$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $this->facobj ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC);
			self::$driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);	// Get the PDO driver name
		}
		switch (self::$method){
			case 'config' :
				if($args){
					if(!is_array($args)) $args = [$args];
					foreach ($args as $key => $val){
						$val = arr($val);
						$key = is_string($key) ? $key : 'main';
						try{
							self::$conns[$key] = new PDO(array_shift($val), array_shift($val), array_shift($val), array_shift($val));
						}catch(PDOException $ex){
							self::log('PDO connection error. Code1: ', $e->getCode(),'. ',$e->getMessage());
						}
					}
				}
				break;
			case in_array(self::$method, ['query','exec','sql']) : self::$query=$args; break;
			case 'table' : if(empty($this->fields) && $this->isTable($args)) $this->fields($args); break;
			default :
				if($this->pdo && method_exists($this->pdo, self::$method)) return call_user_func_array(array($this->pdo, self::$method), $args);
		}
	}
	static function __callStatic($name, $args){
		self::$method = $name;
		self::$args = isone($args);
		return new self;
	}
	function prepareParam(){
		$args=set(func_get_args());
		if($this->prepare && isAssoc($args)){
			$keys = self::wrap(array_keys($args), ':*');
			$vals = arr(isone(array_values($args)));
			if(count($keys)==1){
				$key = isone($keys);
				if(count($vals)>1) foreach($vals as $k => $v) $keys[$k] = $key.$k;
				else{
					$val  = isone($vals);
					while(array_key_exists($key, $this->params)) $key = uKey($key);
					$keys = [$key];
				}
			}
			$this->params = array_merge($this->params, array_combine($keys, $vals));
			return $keys;
		}
		return isone(self::wrap(array_values($args),"'"));
	}
	function where(){
		$args = set(func_get_args());
		$sql  = $this->where;
		if($args){
			if(is_string($args)){
				$args = remSpaces($args);
				if(strpos($args,' ')){
					$part = explode(' ', $args, 2);
					$args = [$part[0] => str_replace(' ', ',', $part[1])];
				}
				else parse_str($args, $args); //parse query values of url 
			}
			else if(is_numeric($args)) $args = ['id' => $args]; //single numaric argument will consider as table id.
			else if(count($args)>1){
					$value = array_pop($args);
					$array = filter(arr(array_shift($args),' '));
					$param = array_shift($array);
					$opera = !empty($args) ? isone($args) : (!empty($array) ? isone($array) : '=');
					$args  = [$param => $opera.','.str($value)];
			}

			if(is_array($args)){
				foreach($args as $key=>$val){
					if(array_key_exists($key, $this->fields)){
						$sql .= (!stripos($sql, 'where') ? ' WHERE ' : ' AND ') . str(self::wrap($key));
						$part = explode(',', $val, 2);
						$vals = end($part);
						$cond = count($part)>1 ? $part[0] : '=';
						$sql .= ' ' . trim(str_replace('_' , ' ', strtoupper($cond)));
						if(strpos($cond,  'between')!==false){
							$vals = array_slice(arr($vals), 0, 2);
							$sql .= ' ' . str($this->prepareParam([$key=>$vals]),' AND ');
						}
						else if(strpos($cond,  'in')!==false) $sql .= ' ('. str($this->prepareParam([$key=>$vals])) .')';
						else if(strpos($cond,'like')!==false) $sql .= ' '. str_replace('_', '%', $vals);
						//str($this->prepareParam([$key=>str_replace('_', '%', str($vals))]));
						else $sql .= ' '. str($this->prepareParam([$key=>$vals]));
					}
				}
			}
		}
		$this->where = $sql;
		return $this;
	}
	function select(){
		$args = set(func_get_args());
		$this->cols = $args && $args!=1 ? self::wrap($args) : '*';
		$this->sql  = 'SELECT '. str($this->cols) . ' FROM ' . self::$table;
		return $this;
	}
	function insert(){
		$args = set(func_get_args());
		if(is_array($args)){
			if(isAssoc($args)) $args = [$args];
			if(!$this->isTable(self::$table)) $this->create($args[0]);
			if($this->transaction) $this->beginTransaction();
			$keys = $vals = [];
			foreach( $args as $item){
				if($this->sql===null){
					$keys = array_keys($item);
					$vals = $this->prepareParam($item);
					$this->sql  = 'INSERT INTO `' . self::$table . '` ('.str(self::wrap($keys)).') VALUES ('.str($vals).')';
					if($this->bindParam){
						$this->stmt = $this->prepare($this->sql);
						foreach ($keys as $i=>$k) $this->stmt->bindParam($vals[$i], $$k);
					}
				}
				if($this->bindParam){
					extract($item);
					$this->stmt->execute();
				}
				else return $this->run();
			}
			if($this->transaction) $this->commit();
			return $this->lastInsertId();
		}
	}
	function update(){
		$args = set(func_get_args());
		if($args){
			foreach ($args as $k=>$v) $arr[] = " `$k` = ". str($this->prepareParam([$k=>$v]));
			$this->sql = 'UPDATE `'.self::$table.'` SET'. str($arr);
			$this->run();
		}
	}
	function delete(){
		$args = set(func_get_args());
		if($args && $this->where===null) $this->where($args);
		$this->sql = 'DELETE FROM `'.self::$table.'`'. $this->sql;
		$this->run();
	}
	function limit(){
		$args=set(func_get_args());
		if($args) $this->where .= ' LIMIT '. str($this->prepareParam(['limit'=>$args]));
		return $this;
	}
	function order_by(){
		$args=set(func_get_args());
		if($args){
			$args = self::wrap($args);
			$this->where .= ' ORDER BY '. implode(' ', $args);	
		}
		return $this;
	}
	
	function top($arg=1){
		if($this->sql===null) $this->select(); 
		if($this->cols) $this->sql = str_ireplace($this->cols, "TOP $arg ".$this->cols, $this->sql);
		return $this;
	}
	function tables(){
		$sql = $this->sqlite() ? "SELECT name FROM sqlite_master WHERE type='table'" : 'SHOW TABLES';
		return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);//PDO::FETCH_NUM
	}
	
	function fields(){
		$args  = set(func_get_args());
		$table = $args ? $args : self::$table;
		$sql   = $this->sqlite() ? "PRAGMA table_info (`$table`)" : "SHOW FIELDS FROM `$table`";
		$stmt  = $this->pdo->query($sql);
		$rows  = $stmt ? $stmt->fetchAll() : null;
		if($rows){
			foreach($rows as $row){
				$name = $this->sqlite() ? 'name':'Field';
				$type = $this->sqlite() ? 'type':'Type';
				if(!empty($row[$name]) && !empty($row[$type])) $this->fields[$row[$name]] = $row[$type];
			}
		}
		return $args===true ? $this->fields : $rows;
	}
	function reset($table=''){
		if(empty($table)) $table = self::$table;
		if($this->isTable($table)){
			$sql = self::sqlite() ? "DELETE FROM `$table`;UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='$table';" : 'TRUNCATE TABLE '.$table;
	    	return $this->pdo->exec($sql);
		}
	}
	function drop($table=''){
		if(empty($table)) $table = self::$table;
		return $this->isTable($table) ? $this->exec("DROP TABLE `$table`") : false;
	}
	function __call($name, $args){
		if(method_exists($this->pdo, $name)) return call_user_func_array(array($this->pdo, $name), $args);
		$args = isone($args);
		switch($name){
			case 'sql' 	: $this->sql=$args; $this->run(); break;
			case 'table': if($args && empty($this->fields) && $this->isTable($args)) $this->fields($args); break;
			case in_array($name, ['count','avg','sum','min','max']) :
				if($this->sql===null) $this->select();
				$this->sql = str_ireplace($this->cols, $name.'('.($args?$args:'*').')', $this->sql);
				return $this->run() ? $this->stmt->fetchColumn() : false;
			default     : break;
		}
		return $this;
	}
	function facobj($ot){return (is_bool($ot) ? $ot : $this->facobj) ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC;}
	function  col(){return $this->run(func_get_args()) ? $this->stmt->fetch( PDO::FETCH_COLUMN ) : null;}
	function cols(){return $this->run(func_get_args()) ? $this->stmt->fetchAll( PDO::FETCH_COLUMN ) : null;}
	function  row(){
		$args=func_get_args();
		if(!empty($id=reset($args)) && is_numeric($id)){
			$tot = $this->count();
			$off = $id<0 ? $tot+$id : $id-1;
			return $this->sql('SELECT * FROM `'.self::$table.'` LIMIT '.$off.',1')->row();
		}
		return $this->run(reset($args)) ? $this->stmt->fetch($this->facobj(end($args))):null;
	}
	function rows(){$args=func_get_args();return $this->run(reset($args))?$this->stmt->fetchAll($this->facobj(end($args))):null;}
	function lastRow($col=''){$row = $this->order_by('id,desc')->limit(1)->row($col);return $col ? $row[$col] : $row;}
	function run(){
		if(!$this->pdo) throw new Exception("Error Processing Request! PDO object not found please set valid configure for DB::config()'",1);
		if(self::$query) $this->sql = self::$query;
		if(!$this->sql)  $this->select(func_get_args());
		if($this->where) $this->sql = $this->sql.$this->where;
		if($this->prepare){
			try{
				$this->stmt = $this->prepare($this->sql);
			}catch (PDOException $e){
				self::log('Error code2: ', $e->getCode(),'. ',$e->getMessage());
			}
			if($this->stmt){
				if($this->bindParam){
					if(!empty($this->params)){
						foreach($this->params as $key => $val){
							try{
								$this->stmt->bindParam($key, $val, $this->bindType($key));
							}catch(PDOException $e){
								self::log('Error code3: ', $e->getCode(),'. ',$e->getMessage());
							}
						}
					}
				}
				try{
					$this->stmt->execute($this->params);
				}catch (PDOException $e){
					self::log('Error code4: ', $e->getCode(),'. ',$e->getMessage());
				}
			}
			$this->params = [];
		}
		else{
			try{
				$this->stmt = stripos($this->sql, 'select')!==false ? $this->query($this->sql) : $this->exec($this->sql);
			}
			catch (PDOException $e){
				self::log('Error code5: ', $e->getCode(),'. ',$e->getMessage());
			}
		}
		return !stripos($this->sql, 'insert') ? $this->stmt : $this->lastInsertId();
	}
	function sqlite(){return self::$driver==='sqlite';}
	function create(){
		$args  = set(func_get_args());
		$table = set(self::$table);
		if($args && $table){
			$item = isIndex($args) ? $args[0] : $args;
			unset($item['id']); //field id must require so remove it.
			$sql='CREATE TABLE IF NOT EXISTS '. $table;
			foreach($item as $k => $v) $cols[] = str_replace("-", "_", str(self::wrap($k))) .' '.$this->fieldType($v);
			$sql.= $this->sqlite()?'(`id` INTEGER PRIMARY KEY AUTOINCREMENT,':'(`id` INT PRIMARY KEY AUTO_INCREMENT, '.implode(',',$cols).')';
			return $this->query($sql);
		}
	}
	function fieldType($value){
		switch ($value){
			case is_bool($value)    : return 'BOOLEAN'; break;
			case is_float($value)   : return $this->sqlite() ? 'REAL':'DOUBLE'; break;
			case is_numeric($value) : return $this->sqlite() ? 'INTEGER':'INT'; break;
			case is_string($value)  : return $this->sqlite() ? 'TEXT':'VARCHAR(255)'; break;
			default                 : return "TEXT";
		}
	}
	function isTable($table){
		self::$table = $table;
		try{
			$stmt = $this->query("SELECT 1 FROM `$table` LIMIT 1");
		}catch(PDOException $e){ return false; }
		return $stmt!==false;
	}
	function bindType($key){
		$key = ltrim(explode('_', $key)[0], ':');
		if(!empty($fields=$this->fields)){
			if(array_key_exists($key, $fields)){
				$val = strtolower(explode('(', $fields[$key])[0]);
				switch ($val){
					case 'int'  	: 
					case 'integer'  : return PDO::PARAM_INT; break;
					case 'boolean'  : return PDO::PARAM_BOOL; break;
					case 'real'     : return PDO::PARAM_INT; break;
					case 'blob'     : return PDO::PARAM_LOB ; break;
					default         : return PDO::PARAM_STR;
				}
			}
			else{
				switch ($key){
					case 'limit'  	: return PDO::PARAM_INT; break;
					default         : return PDO::PARAM_STR;
				}
			} 
		}
		return null;
	}
	function populate($n){
		if($n > 500){$n=500; echo "Sqlite max limit 500!\n";}
		$array = [];
		$new_array = [];
		$cols = $this->fields();
		for ($i=0; $i < $n; $i++){
			foreach ($cols as $col){
				$name = $this->sqlite() ? $col['name'] : $col['Field'];
				if($name =='id') continue;
				$array[$name] = $this->demoData($this->sqlite() ? $col['type'] : $col['Type']);
			}
			$new_array[] = $array;
		}
		return $this->insert($new_array);
	}
	function demoData($var){
		switch ($var){
			case 'INTEGER': return rand(1,100000); break;
			case 'VARCHAR(255)' : return $this->randWord(20); break;
			case 'BOOLEAN' : return true; break;
			case 'REAL' : return round(rand(1,100)/0.333,4); break;
			case 'TIME' : return mt_rand(1,time()); break;
			case 'DATE' : return date('Y-m-d',mt_rand(1,time())); break;
			case 'DATETIME' : return date('Y-m-d H:i:s',mt_rand(1,time())); break;
			case 'BLOB' : return $this->randString(100); break;
			default : return $this->randWord(10);
		}
	}
	function randWord($length=6){
		return substr(str_shuffle("qwertyuiopasdfghjklzxcvbnm"),0,$length);
	}
	function randString($length = 15) {
		return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"),0,$length);
	}
	static function wrap($v, $w='`*`'){
		$w=arr($w,'*');
		return array_map(function($v)use($w){return in_array($v, ['asc','desc']) || is_numeric($v) ? $v : reset($w).$v.end($w);}, arr($v));
	}
	static function log($msg){
		date_default_timezone_set('UTC');
		$newlog = '['.date('Y-m-d H:i:s').'] ' . $msg; echo $newlog;
		//if(is_readable(self::$logpath))  $newlog .= "\r\n". file_get_contents(self::$logpath);
		error_log($newlog, 3, self::$logpath);
	}
}
if(!function_exists('filter')){function filter($v){return isIndex($v)?array_values(array_filter(array_unique($v))):$v;}}
if(!function_exists('varName')){function varName($val){foreach($GLOBALS as $k=>$v) if($v===$val)return $k;return false;}}
if(!function_exists('isone')){function isone($v){return (is_array($v)&&isIndex($v)&&count($v)===1)?isone($v[0]):$v;}}
if(!function_exists('uKey')){function uKey($key){$p=arr($key,'_');$i=(int)end($p); return $p[0].'_'.++$i;}}
if(!function_exists('matches')){function matches($p,$s){return preg_match_all($p,$s,$match)?$match:false;}}
if(!function_exists('isAssoc')){function isAssoc($v){return is_array($v) && is_string(array_keys($v)[0]);}}
if(!function_exists('isIndex')){function isIndex($v){return is_array($v) && array_values($v)===$v;}}
if(!function_exists('remSpaces')){function remSpaces($str){return preg_replace('/\s+/', ' ', $str);}}
if(!function_exists('pre')){function pre($v,$x=0){echo '<pre>';print_r($v);echo'</pre>';if($x)exit;}}
if(!function_exists('arr')){function arr($v, $d=','){return  is_array($v) ? $v : explode($d,$v);}}
if(!function_exists('str')){function str($v, $d=','){return  is_string($v)? $v : implode($d,$v);}}
if(!function_exists('cut')){function cut(&$arr,$k){$v=$arr[$k];unset($arr[$k]);return $v;}}
if(!function_exists('set')){function set($v){return !empty($v=isone($v))?$v:false;}}
