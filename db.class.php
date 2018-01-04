<?php

class db{
	private $con  = null;
	private $query = '';
	private $params = array();
	private static $table = null;
  private static $db_config = [
					'db_type' => "sqlite",
					'db_name' => "db.sqlite",
					'db_host' => "localhost",
					'db_port' => "3306",
					'db_user' => "root",
					'db_pass' => "root",
					'pdo_opt' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_PERSISTENT => true]
					];
	function __construct(){
		if($this->con === NULL){
			extract(self::$db_config);
			switch($db_type){
				case "sqlite": $dsn = "sqlite:$db_name"; break;
				case "memory": $dsn = "sqlite::$db_name:"; break;
				case "mysql" : $dsn = 'mysql:host=$db_host;dbname=$db_name;port=$db_port'; break;
				default: die("Unsuportted DB Driver! Check the configuration.");
			}
			try{
			    $this->con = new PDO( $dsn, $db_user, $db_pass, $pdo_opt );
			}catch(PDOException $e){
				Throw new Exception('PDO connection error: ' . $e->getMessage());
			}
		}
	}
	static function set_config($config){
		self::$db_config = array_merge( self::$db_config, $config);
		return new self;
	}
	static function table($table){
		self::$table = $table;
		return new self;
	}
	public static function raw(){
		return new self;
	}
	
	function query($sql){
		return @$this->con->query($sql);
	}
	function exec($sql){
		return @$this->con->exec($sql);
	}
	function insert($data){
		$keys   = array_keys($data);
		$qmark  = substr(str_repeat('?,',count($keys)), 0, -1);
		$fields = implode(',', array_keys($data));
		$this->query = "INSERT INTO ".self::$table." ($fields) VALUES($qmark)";
		$this->params = array_values($data);
		return $this->run()->rowCount();
	}
	function delete(){
		$args = func_get_args();
		$this->where(array_shift($args), array_shift($args));
		$this->query = "DELETE FROM " . self::$table . $this->query;
		return $this->run()->rowCount();
	}
	function update($data){
		$keys   = array_keys($data);
		$string = "";
		foreach($keys as $key) $string .= "$key=?, ";
		$string = substr($string, 0, -2);
		$this->query =  "UPDATE ".self::$table." SET $string". $this->query;
		if (isset($this->params))
			$this->params = array_merge(array_values($data), $this->params);
		else
			$this->params = array_values($data);
		return $this->run()->rowCount();
	}
	function get($column = '*'){
		$this->select($column);
		return $this->run()->fetchAll(PDO::FETCH_ASSOC);
	}
	function get_obj($column = '*'){
		$this->select($column);
		return $this->run()->fetchAll(PDO::FETCH_OBJ);
	}
	function get_column($column){
		$this->select($column);
		return $this->run()->fetchAll(PDO::FETCH_COLUMN);
	}
	function get_value($column){
		$this->select($column);
		return $this->run()->fetch(PDO::FETCH_COLUMN);
	}
	function select($column = '*'){
		if (isset($this->query))
			$this->query = "SELECT $column FROM " . self::$table . $this->query;
		else
			$this->query = "SELECT $column FROM " . self::$table;
		return $this;
	}
	function where($_condition,$_values){
		if (isset($this->query))
			$this->query .= " WHERE " . $_condition;
		else
			$this->query = " WHERE " . $_condition;
		if(is_array($_values))
			$this->params = array_merge($this->params,$_values);
		else
			$this->params[] = $_values;
		return $this;
	}
	function limit(){
		$args = func_get_args();
		if( count($args) == 2 ) $this->query .= " LIMIT ".$args[0].", ".$args[1];
		else $this->query .= " LIMIT 0, " . $args[0];
		return $this;
	}
	function orderby(){
		$args = func_get_args();
		if( count($args) == 2 ) $this->query .= " ORDER BY ".$args[0]." ".$args[1];
		else $this->query .= " ORDER BY " . $args[0] . "ASC";
		return $this;
	}
	function rowCount(){
		$this->query = "SELECT count(*) FROM ".self::$table; 
		return $this->run()->fetchColumn();
	}
	function run(){
		$res_obj = $this->con->prepare($this->query);
		if (isset($this->params)) 
	        $res_obj->execute($this->params);
	    else
	        $res_obj->execute();
		return $res_obj;
	}
}
