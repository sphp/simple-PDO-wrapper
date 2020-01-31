<?php
function pre($v,$x=0){echo('<pre>');print_r($v);echo('</pre>');if($x)exit;}

DB::config('sqlite:info.db');
$conns= [
          'mydb1'=>['mysql:host=localhost;dbname=test','root',''],
          'mydb2'=>['sqlite:info2.db']
       ];
DB::config($conns);
DB::table('users')->insert([
	['name' => 'shamim','email' => 'shamim@gmail.com', 'votes' => 1],
	['name' => 'fatih','email' => 'fatih@gmail.com', 'votes' => 2],
	['name' => 'aarian','email' => 'aarian@gmail.com', 'votes'  => 3]
]);
DB::table('users')->populate(100);
pre(DB::table('users')->count());
pre(DB::table('users')->rows());
pre(DB::conn('main')->table('users')->row());

class DB{
	static  $config, $conn, $conns, $driv, $query, $table, $args;
	private $pdo, $sql, $stmt, $where;
	private $cols  =[];
	private $params=[];
	private $values=[];
	private $fields=[];
	private $bind	= true;
	private $prepare= true; //true;
	private $facobj	= false; //set true to return object OR false to return array.
	private $noCheckCol	 = false; //set true to return object OR false to return array.
	private $transaction = false; //set true to return object OR false to return array.

	function __construct(){
		if(!empty(self::$config)){
			$cfg = self::$config;
			if(is_string($cfg)) self::$conns['main'] = $this->connect($cfg);
			else if(self::is_array($cfg)){
				if(self::is_assoc($cfg)) foreach ($cfg as $key => $val) self::$conns[$key] = $this->connect($val);
				else self::$conns['main'] = $this->connect($cfg);
			}
		}
		if(self::$table!==null && empty($this->fields)) $this->fields();
		if(self::$conn===null) self::$conn = 'main';
		if(self::$conn && !empty(self::$conns[self::$conn]) ){
			$this->pdo = self::$conns[self::$conn];
			$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			self::$driv = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
		}
		if(self::$query!==null) $this->sql = self::$query;
	}
	function connect(){
		$args = self::one(func_get_args());
		if(is_string($args)) $args = [$args];
		try{
			$this->pdo = new PDO(array_shift($args), array_shift($args), array_shift($args), array_shift($args));
		}
		catch(PDOException $ex){echo 'Error code: ', $ex->getCode(),'. ',$ex->getMessage();}
		return $this->pdo;
	}
	static function __callStatic($name, $args){
		self::$conn  = null;
		self::$$name = self::arrFilter($args);
		return new self;
	}
	function setValues($val, $k=''){
		if($this->prepare){
			if(is_array($val)){
				$this->values=array_merge($this->values, array_values($val));
				$this->params=array_merge($this->params, array_keys($val));
				return array_fill(0, count($val),'?');
			}else{
				$this->values[]=$val; 
				$this->params[]=$k;
				return '?';
			}
		}
		return self::wrap($val,'\'');
	}
	function __call($name, $args){
		if(method_exists($this->pdo, $name)) return call_user_func_array(array($this->pdo, $name), $args);
		$args = self::arrFilter($args);
		$table = self::qut(self::$table);
		
		switch($name){
			case 'sql' 	 : $this->run($args); break;
			case 'table' :
				self::$table  = $args;
				if($this->isTable(self::$table)) $this->fields();
				break;
			case 'select':
				if(empty($this->cols)) $this->cols = !empty($args) ? self::sQuote($args) : ['*'];
				if($this->where===null && !empty($args[1])) $this->where($args[1]);
				$this->sql = 'SELECT '.implode(',', $this->cols).' FROM ' . $table . $this->where;
				break;
			case 'insert':
				$items = self::is_assoc($args) ? [$args] : $args;
				if(!$this->isTable(self::$table)) $this->createTable($items[0]);

				if($this->transaction) $this->beginTransaction();
				foreach($items as $item){
					$keys = implode(',', self::wrap(array_keys($item)));
					$vals = implode(',', $this->setValues($item));
					$this->sql = 'INSERT INTO ' . $table . ' ('.$keys.') VALUES ('.$vals.')';
					$this->run();
				}
				if($this->transaction) $this->commit();
				break; //continue sql...
			case 'update':
			 	$data = [];
			 	foreach ($args as $k => $v) $data[] = self::qut($k)." = ". implode(',', $this->setValues($v,$k));
			 	if($this->where===null && !empty($args[1])) $this->where($args[1]);
                $this->sql = 'UPDATE '.$table.' SET '. implode(', ', $data) . $this->where;
				$this->run();
				break; //continue sql...
			case 'delete':
				if($this->where===null && !empty($args)) $this->where($args);
				$this->sql='DELETE FROM ' . $table . $this->sql;
				$this->run();
				break; //continue sql...
			case 'limit' :
				$this->where .= ' LIMIT '. implode(', ', $this->setValues($args));
				break; //continue sql...
			case 'order_by' :
				$this->where .= ' ORDER BY '.implode(' ', $this->setValues($args));
				break; //continue sql...
			case 'count' :
				if($this->sql===null) $this->select(); 
				$this->sql = str_ireplace($this->cols, 'COUNT(*)', $this->sql);
				try{
					$this->stmt = $this->query($this->sql);
				}catch (PDOException $ex){
					echo 'Error code: ', $ex->getCode(),'. ',$ex->getMessage();
				}
				return $this->stmt ? $this->stmt->fetchColumn() : 0;
			case 'tables':
				$sql = self::sqlite() ? "SELECT name FROM sqlite_master WHERE type='table'" : 'SHOW TABLES';
				return $this->query($sql)->fetchAll(PDO::FETCH_COLUMN);//PDO::FETCH_NUM
			case 'fields':
				$stmt = $this->fields = [];
				$sql  = self::sqlite() ? 'PRAGMA table_info('.$table.')' : 'SHOW FIELDS FROM '.$table;
                try{
                    $stmt = $this->query($sql);
                } catch (PDOException $e) {
                    echo 'Error code: ', $e->getCode(),'. ',$e->getMessage();
                }
                $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach($cols as $col) $this->fields[] = self::sqlite() ? $col['name'] : $col['Field'];
                return $cols;
            case 'reset':
            	if($this->isTable(self::$table)){
            		$sql = self::sqlite() ? "DELETE FROM $table;UPDATE SQLITE_SEQUENCE SET SEQ=0 WHERE NAME=$table;" : 'TRUNCATE TABLE '.$table;
                	return $this->exec($sql);
            	}
			case 'drop' :
				$table = !empty($args) ? $args : $table;
                return $this->isTable($table) ? $this->exec('DROP TABLE '.$table) : false;
			default     :
                if($this->sql!==null && !empty($args)) $this->sql .= ' '.$name.' '.$args;
		}
		return $this;
	}
	function where(){
		$args=self::arrFilter(func_get_args());
		$operators=['=','>','<','<>','!=','>=','>=','!>','!<','in','between','not_in','or_not_in','not_between','or_not_between','like','exists','is_null','not_like','having','not_having','having_in','or_not_having'];
		$name = $cond='';
		$sql  = $this->where;
		if(!empty($args)){
			if(is_string($args)){
				if(strpos($args,' ')){
					$part=explode(' ', $args, 2);
					$args=[$part[0] => str_replace(' ', ',', $part[1])];
				}
				else parse_str($args, $args); //parse query values of url 
			}
			else if(is_numeric($args)) $args=['id' => $args]; //single numaric argument will consider as table id.
			else{
				$value=count($args)>1 ? array_pop($args) : [];
				if(is_array($value)) $value=implode(',', $value);
				$name=array_shift($args);
				if(is_string($name) && strpos($name, ' ')){
					$part=explode(' ',$name,2);
					$name=$part[0];
					$cond=$part[1];
				}
				if(empty($cond) && !empty($args))$cond=$args[0];
				else $cond='=';
				$args = [$name => $cond.','.$value];
			}
			if(is_array($args)){
				foreach($args as $key=>$val){
					if($this->noCheckCol || in_array($key, $this->fields)){
						$sql .= !stripos($sql, 'where') ? ' WHERE `'.$key.'`' : ' AND `'.$key.'`';
						$vals=[];
						$vals=strpos($val, ',') ? explode(',', $val) : $val;
						$cond=!empty($vals[0]) && in_array($vals[0], $operators) ? array_shift($vals) : '=';
						$conj=str_replace('_' , ' ', strtoupper($cond));
						$sql .= ' ' . $conj;
						if(is_array($vals)){
							if(strpos($cond, 'between')!==false) $vals = array_slice($vals, 0, 2);
							else if(strpos($cond, 'like')!==false) $vals = str_replace('_', '%', $vals);
							$vals = self::one($this->setValues($vals, $key));
							if(strpos($cond, 'between')!==false)   $sql .= ' '.implode(' AND ',$vals);
		                    else if(strpos($cond, 'in')!==false)   $sql .= ' ('. implode(', ',  $vals) .')';
							else $sql .= ' '. $vals;
						}
						else $sql .= ' '. $vals;
					}
				}
			}
		}
		$this->where = $sql;
		return $this;
	}
	function col(){
		self::$args = self::arrFilter(func_get_args());
		if($this->sql===null) $this->select();
		return $this->run() ? $this->stmt->fetch( PDO::FETCH_COLUMN ) : null;
	}
	function row(){
		self::$args=self::arrFilter(func_get_args());
		if($this->sql===null) $this->select();
		return $this->run() ? $this->stmt->fetch($this->facobj(array_pop(self::$args))) : null;
	}
	function cols(){
		self::$args = self::arrFilter(func_get_args());
		if($this->sql===null) $this->select();
		return $this->run() ? $this->stmt->fetchAll( PDO::FETCH_COLUMN ) : null;
	}
	function rows(){
		self::$args = self::arrFilter(func_get_args());
		if($this->sql===null) $this->select();
		return $this->run() ? $this->stmt->fetchAll($this->facobj(array_pop(self::$args))) : null;
	}
	function facobj($ot){
		$this->facobj = is_bool($ot) ? $ot : $this->facobj;
		return $this->facobj ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC;
	}
	function run($sql=null){
		if($sql) $this->sql = $sql;
		//pre($this->sql);
		if($this->prepare && empty(self::$query) ){
			try{
                $this->stmt = $this->prepare($this->sql);
            }catch (PDOException $e){
                echo 'Error code: ', $e->getCode(),'. ',$e->getMessage();
            }
            if($this->stmt){
            	if($this->bind){
	                if(!empty($this->values)){
	                    $cols = $this->fields();
	                    for ($i=0; $i < count($this->values); $i++){
	                        $dataType = '';
	                        foreach ($cols  as $col) {
	                        	$name = self::sqlite()? $col['name'] : $col['Field'];
	                            if($this->fields[$i] === $name){
	                                $dataType = self::sqlite() ? $col['type'] : $col['Type']; break;
	                            }
	                        }
	                        try{
	                            $this->stmt->bindParam($i+1, $this->values[$i] , $this->getParamType($dataType) );
	                        }catch (PDOException $e){
	                            echo 'Error code: ', $e->getCode(),'. ',$e->getMessage();
	                        }
	                    }
	                }
	            }
            	try{
                	$this->stmt->execute($this->values);
	            }catch (PDOException $e){
	                echo 'Error code: ', $e->getCode(),'. ',$e->getMessage();
	            }
            }
            
		}
		else{
			try{
				$this->stmt = stripos($this->sql,'SELECT') !== false ? $this->pdo->query($this->sql) : $this->pdo->exec($this->sql);
			}catch (PDOException $e){
				echo 'Error code: ', $e->getCode(),'. ',$e->getMessage();
			}
		}
		$this->values = [];
		return !stripos($this->sql, "insert") ? $this->stmt : $this->lastInsertId();;
	}
	function getParamType($dayatype){
		$dayatype = explode('(', $dayatype)[0];
        switch (strtoupper($dayatype)){
            case "INT"  	: 
            case "INTEGER"  : return PDO::PARAM_INT; break;
            case "BOOLEAN"  : return PDO::PARAM_BOOL; break;
            case "REAL"     : return PDO::PARAM_INT; break;
            case "BLOB"     : return PDO::PARAM_LOB ; break;
            default         : return PDO::PARAM_STR;
        }
    }
	function createTable($args){
		if( !empty(self::$table) && !empty($args)){
			$sql  = 'CREATE TABLE IF NOT EXISTS '. self::wrap(self::$table);
			$item = self::is_assoc($args) ? $args :  $args[0];
			unset($item['id']); //remove id field
			foreach ($item as $k => $v) $fields[] = str_replace("-", "_", self::wrap($k)) .' '.self::dataType($v);
			$sql.= self::sqlite() ? '(`id` INTEGER PRIMARY KEY AUTOINCREMENT, ':'(`id` INT PRIMARY KEY AUTO_INCREMENT, ';
			$sql.= implode(', ', $fields).')';
			return $this->query($sql);
		} 
	}
	static function sqlite(){return self::$driv==='sqlite';}

	static function dataType($value){
		switch (true){
			case is_bool($value)    : return 'BOOLEAN'; break;
			case is_float($value)   : return self::sqlite() ? 'REAL':'DOUBLE'; break;
			case is_numeric($value) : return self::sqlite() ? 'INTEGER':'INT'; break;
			case is_string($value)  : return self::sqlite() ? 'TEXT':'VARCHAR(255)'; break;
			default                 : return "TEXT";
		}
	}
	static function is_assoc(array $arr){
		return !empty($arr) ? is_string(array_keys($arr)[0]) : $arr;
	}
	static function arrFilter(array $arr, $rmNull=false){
		return !self::is_assoc($arr) ? self::one($rmNull ? array_values(array_filter($arr)) : $arr) : $arr;
	}
	static function one(array $arr){
		return count($arr)===1 ? $arr[0] : $arr;
	}
	static function quote($var){
		$arr = !strpos($var, ',') ? [$var] : explode(',', $var);
		return implode(',', array_map( function($str){ return is_numeric($str) ? $str : "'$str'";}, $arr));
	}
	static function wrap($data, $sym='`'){
	    $arr = is_string($data) ? explode(',', $data) : $data;
	    $arr = array_map( function($str) use ($sym){ return is_numeric($str) ? $str : $sym . $str . $sym; }, $arr);
	    return is_array($data) ? $arr : implode(',', $arr); // return type is similar as input parameter  
	}
	static function qut($var){
		$arr = !strpos($var, ',') ? [$var] : explode(',', $var);
		return implode(',', array_map( function($str){ return is_numeric($str) ? $str : "`$str`";}, $arr));
	}
	static function sQuote($var){
		if(is_string($var)) $var = explode(',',$var);
		return is_array($var) ? array_map(['self','qut'], $var) : self::qut($var);
	}
	function isTable($table){
		try{ $result=$this->query("SELECT 1 FROM $table LIMIT 1"); }
		catch(Exception $e){return false;}
		return $result!==false;
	}
	function populate($n){
        if($n > 500){$n=500; echo "Sqlite max limit 500!\n";}
        $array = [];
        $new_array = [];
        $cols = $this->fields();
        for ($i=0; $i < $n; $i++){
            foreach ($cols as $col){
            	$name = self::sqlite() ? $col['name'] : $col['Field'];
                if($name =='id') continue;
                $array[$name] = $this->demoData(self::sqlite() ? $col['type'] : $col['Type']);
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
    static function var_name($var){
        foreach($GLOBALS as $var_name => $value) if ($value === $var) return $var_name; return false;
    }
    static function log($err){
        $this->log_path = (!is_file($this->log_path)) ? __DIR__ . '/db_log' : $this->log_path;
        date_default_timezone_set('UTC');
        $newErr = '['.date('Y-m-d H:i:s').'] Error : ' . $err[2];   
        if(file_exists($this->log_path))  $newErr = $newErr."\r\n".file_get_contents($this->log_path);
        file_put_contents($this->log_path, $newErr);
    }
}
