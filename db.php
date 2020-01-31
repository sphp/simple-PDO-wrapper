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
	private $bindValue = false;	// Set true/false for bind binds just a value, it's like a a hard copy.
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
							self::log('Error code1: ', $e->getCode(),'. ',$e->getMessage());
						}
					}
				}
				break;
			case in_array(self::$method, ['query','exec','sql']) : self::$query=$args; break;
			case 'table' : if(empty($this->fields) && $this->isTable($args)) $this->fields($args); break;
			default :
				if($this->pdo && method_exists($this->pdo, self::$method)) return call_user_func_array(array($this->pdo, self::$method), $args);
				break;
		}
	}
	static function __callStatic($name, $args){
		self::$method = $name;
		self::$args = isone($args);
		return new self;
	}
	function prepareParam($val){
		if($this->prepare && isAssoc($val)){
			$keys = self::wrap(array_keys($val), ':*');
			$vals = arr(isone(array_values($val)));
			if(count($keys)==1){
				$key = isone($keys);
				if(count($vals)>1) foreach($vals as $k => $v) $keys[$k] = $key.$k;
				else{
					$val  = isone($vals);
					while(array_key_exists($key, $this->params)) $key=uKey($key);
					$keys = [$key];
				}
			}
			$this->params = array_merge($this->params, array_combine($keys, $vals));
			return $keys;
		}
		return isone(array_values($val));
	}
	function __call($name, $args){
		if(method_exists($this->pdo, $name)) return call_user_func_array(array($this->pdo, $name), $args);
		$args  = isone(array_filter($args));
		$table = self::$table;
		switch($name){
			case 'sql' 		: $this->sql=$args; $this->run(); break;
			case 'table'	: if(empty($this->fields) && !empty($args) && $this->isTable($args)) $this->fields($args); break;
			case 'select'	:
				$this->cols = !empty($args)&&$args!=1 ? self::wrap($args) : '*';
				$this->sql  = 'SELECT '. str($this->cols) . ' FROM ' . $table;
				break;
			case 'insert'	:
				$items = isAssoc($args) ? [$args] : $args;
				if(!$this->isTable($table)) $this->createTable($items[0]);
				if($this->transaction) $this->beginTransaction();
				foreach($items as $item){
					$keys = self::wrap(array_keys($item));
					$vals = $this->prepareParam($item);
					$this->sql = 'INSERT INTO `' . $table . '` ('.str($keys).') VALUES ('.str($vals).')';
					$this->run();
				}
				if($this->transaction) $this->commit();
				break;
			case 'update'	:
				if(!empty($args)){
					$keyval = !isAssoc($args) ? $args[0] : $args;
					$arr=[];
					foreach ($keyval as $k => $v) $arr[] = " `$k` = ". str($this->prepareParam([$k=>$v]));
					$this->sql = 'UPDATE `'.$table.'` SET '. str($arr);
					$this->run();
				}
				break;
			case 'delete'	:
				if($this->where===null && !empty($args)) $this->where($args);
				$this->sql='DELETE FROM `'.$table.'`'. $this->sql;
				$this->run();
				break;
			case 'limit'	:
				$this->where .= ' LIMIT '. str($this->prepareParam(['limit'=>$args]));
				break;
			case 'order_by' :
				$args = self::wrap($args);
				$this->where .= ' ORDER BY '. implode(' ', $args);
				break;
			case 'count'	:
				if($this->sql===null) $this->select(); 
				$this->sql = str_ireplace($this->cols, 'COUNT(*)', $this->sql);
				return $this->run() ? $this->stmt->fetchColumn() : 0;
			case 'tables'	:
				$sql = $this->sqlite() ? "SELECT name FROM sqlite_master WHERE type='table'" : 'SHOW TABLES';
				return $this->query($sql)->fetchAll(PDO::FETCH_COLUMN);//PDO::FETCH_NUM
			case 'lastRow'	:
				$lastitem = $this->order_by('id,desc')->limit(1)->row($args);
				return !empty($args) ? $lastitem[$args] : $lastitem;
			case 'fields'	:
				if(!empty($args)) $table=$args;
				$sql   = $this->sqlite() ? "PRAGMA table_info (`$table`)" : "SHOW FIELDS FROM `$table`";
				$stmt  = $this->query($sql);
				$rows  = $stmt ? $stmt->fetchAll() : null;
				if($rows){
					foreach($rows as $row){
						$name = $this->sqlite() ? 'name':'Field';
						$type = $this->sqlite() ? 'type':'Type';
						if(!empty($row[$name]) && !empty($row[$type])) $this->fields[$row[$name]] = $row[$type];
					}
				}
				return $args ? $this->fields : $rows;
			case 'reset':
				if(!empty($args)) $table = $args;
				if($this->isTable($table)){
					$sql = self::sqlite() ? "DELETE FROM `$table`;UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME='$table';" : 'TRUNCATE TABLE '.$table;
                	return $this->exec($sql);
				}
			case 'drop' :
				if(!empty($args)) $table = $args;
				return $this->isTable($table) ? $this->exec("DROP TABLE `$table`") : false;
			default     :
		}
		return $this;
	}
	function where(){
		$args = isone(func_get_args());
		$sql  = $this->where;
		if(!empty($args)){
			if(is_string($args)){
				if(strpos($args,' ')){
					$part = explode(' ', $args, 2);
					$args = [$part[0] => str_replace(' ', ',', $part[1])];
				}
				else parse_str($args, $args); //parse query values of url 
			}
			else if(is_numeric($args)) $args = ['id' => $args]; //single numaric argument will consider as table id.
			else{
				$value = count($args)>1 ? array_pop($args) : []; 
				$param = arr(array_shift($args),' ');
				$cond  = empty($param[1]) ? array_shift($args) : '=';
				$args  = [$param[0] => $cond.','.str($value)];
			}
			if(is_array($args)){
				foreach($args as $key=>$val){
					if(array_key_exists($key, $this->fields)){
						$sql .= (!stripos($sql, 'where') ? ' WHERE ' : ' AND ') . str(self::wrap($key));
						$vals = arr($val);
						$cond = (!empty($vals[0])) ? array_shift($vals) : '=';
						$sql .= ' ' . trim(str_replace('_' , ' ', strtoupper($cond)));
						if(is_array($vals)){
							if(strpos($cond,  'between')!==false) $vals = array_slice($vals, 0, 2);
							else if(strpos($cond,'like')!==false) $vals = str_replace('_', '%', $vals);
							if(strpos($cond,  'between')!==false) $sql .= ' ' . str($this->prepareParam([$key=>$vals]),' AND ');
							else if(strpos($cond,  'in')!==false) $sql .= ' ('. str($this->prepareParam([$key=>$vals])) .')';
							else $sql .= ' '. str($this->prepareParam([$key=>$vals]));
						}
						else $sql .= ' '. str($this->prepareParam([$key=>$vals]));
					}
				}
			}
		}
		$this->where = $sql;
		return $this;
	}
	function facobj($ot){return(is_bool($ot)?$ot:$this->facobj) ? PDO::FETCH_OBJ:PDO::FETCH_ASSOC;}
	function  col(){return $this->run(func_get_args()) ? $this->stmt->fetch( PDO::FETCH_COLUMN ):null;}
	function cols(){return $this->run(func_get_args()) ? $this->stmt->fetchAll( PDO::FETCH_COLUMN ):null;}
	function  row(){$args=func_get_args();return $this->run(reset($args)) ? $this->stmt->fetch($this->facobj(end($args))):null;}
	function rows(){$args=func_get_args();return $this->run(reset($args)) ? $this->stmt->fetchAll($this->facobj(end($args))):null;}
	function run(){
		if(!$this->pdo) throw new Exception("Error Processing Request! PDO object not found please set valid configure for DB::config()'",1);
		$args = func_get_args();
		if(self::$query) $this->sql = self::$query;
		if(!$this->sql)  $this->select($args);
		if($this->where) $this->sql = $this->sql . $this->where;
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
	function createTable($args){
		if($args && !empty(self::$table)){
			$sql  = 'CREATE TABLE IF NOT EXISTS '. self::$table;
			$item = isAssoc($args) ? $args :  $args[0]; unset($item['id']); //field id must require so remove it.
			foreach ($item as $k => $v){
				$fields[] = str_replace("-", "_", str(self::wrap($k))) .' '.$this->fieldType($v);
			}
			$sql.= $this->sqlite() ? '(`id` INTEGER PRIMARY KEY AUTOINCREMENT, ':'(`id` INT PRIMARY KEY AUTO_INCREMENT, ';
			$sql.= implode(', ', $fields).')';
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
		if(is_readable(self::$logpath))  $newlog .= '\r\n'. file_get_contents(self::$logpath);
		error_log($newlog, 3, self::$logpath);
	}
}
if(!function_exists('varName')){function varName($val){foreach($GLOBALS as $k=>$v) if($v===$val)return $k;return false;}}
if(!function_exists('isone')){function isone($v){return (is_array($v)&&!isAssoc($v)&&count($v)===1)?isone($v[0]):$v;}}
if(!function_exists('uKey')){function uKey($key){$p=arr($key,'_');$i=(int)end($p); return $p[0].'_'.++$i;}}
if(!function_exists('matches')){function matches($p,$s){return preg_match_all($p,$s,$match)?$match:false;}}
if(!function_exists('isAssoc')){function isAssoc($v){return !empty($v)?is_string(array_keys($v)[0]):$v;}}
if(!function_exists('pre')){function pre($v,$x=0){echo '<pre>';print_r($v);echo'</pre>';if($x)exit;}}
if(!function_exists('arr')){function arr($v, $d=','){return !is_array($v)?explode($d,$v):$v;}}
if(!function_exists('str')){function str($v, $d=','){return  is_array($v)?implode($d,$v):$v;}}
if(!function_exists('cut')){function cut(&$arr,$k){$v=$arr[$k];unset($arr[$k]);return $v;}}
