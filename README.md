# simple-PDO-wrapper
simple PDO wrapper class

# example
```
if(!function_exists('pre')){function pre($v,$x=0){echo '<pre>';print_r($v);echo'</pre>';if($x)exit;}}

$conns= [
          'mydb1'=>['mysql:host=localhost;dbname=test','root',''],
          'mydb12'=>['sqlite:tvinfo.db'],
       ];

DB::config('sqlite:tvinfo.db');
DB::config($conns);
//DB::pdo('mydb1')->reset('users');

DB::conn('mydb1')->reset('users');
DB::conn('mydb1')->table('users')->reset();
DB::conn('mydb1')->drop('users');
/*** Get All table names ***/
pre(DB::conn('main')->tables());
/*** Get fields names of a table ***/
pre(DB::table('channels')->fields());
pre(DB::conn('main')->fields('channels'),1);


DB::table('channels')->max('id');       //Last ID
DB::table('channels')->row(1);          //get 1st row
DB::table('channels')->row(10);          //get 10th row
DB::table('channels')->row(-1);         //get last row
DB::table('channels')->row(-2);         //get row befour last 

/*** count examples ***/
pre(DB::table('channels')->lastRow('id'),1);
DB::table('channels')->insert(['name' => 'shamim','email' => 'shamim@gmail.com', 'votes' => 1]);

/*** count where examples ***/
pre(DB::table('channels')->count(),1);
pre(DB::table('channels')->where('id > 10')->where('id < 20')->where('id > 12')->where('id < 18')->where('id in 11,12,13,14')->count());

/*** Select examples  ***/
pre(DB::table('channels')->limit(1)->rows());
pre(DB::table('channels')->limit('5,1')->rows(['id,name,link'],true));
pre(DB::table('channels')->select('id','name')->limit('0,1')->rows());
pre(DB::conn('main')->table('channels')->select('id','name')->limit(1)->rows());
pre(DB::conn('main')->table('channels')->where('id in 1,5,9')->rows());
pre(DB::table('channels')->limit(2)->rows('id,name,link'),1);


/*** Update Example ***/
pre(DB::table('channels')->where('id in 1,2,4')->rows('name,link'));//A Bola TV
DB::table('channels')->where('id in 1,2,4')->update(['name' => 'AAAA Bola TV', 'link' => 'google.com']);
pre(DB::table('channels')->where('id in 1,2,4')->rows('name,link'),1);

pre(DB::table('channels')->order_by('id,desc')->limit(5)->rows('id,name,link'),1);

/*** Mysql get/drop/reset tables name in examples ***/
$rtn = DB::conn('mydb1')->tables();			
$rtn = DB::conn('mydb1')->reset('users11');
$rtn = DB::conn('mydb1')->fields('users11');
$rtn = DB::conn('mydb1')->drop('users11');
$rtn = DB::conn('mydb1')->table('users11')->rows();
pre($rtn,1);

/*** Sqlite get/drop/reset tables name in examples ***/
$rtn = DB::conn('mydb2')->tables();
$rtn = DB::conn('mydb2')->drop('users');
$rtn = DB::conn('mydb2')->reset('users');
$rtn = DB::conn('mydb2')->fields('movies');
$rtn = DB::conn('mydb2')->table('movies')->limit(10)->rows();
pre($rtn,1);

/*** Use Raw sql query with custom connection ***/
pre(DB::conn('main')->sql('select name from channels limit 10')->cols(true),1);
pre(DB::sql('select name from channels limit 10')->rows(),1);
/*** Use custom database connection ***/
pre(DB::conn('main')->table('channels')->limit('1,3')->rows(),1);

/*** Update Example ***/
pre(DB::table('channels')->where('id in 1,2,4')->rows('name,link'));//A Bola TV
DB::table('channels')->where('id in 1,2,4')->update(['name' => 'AAAA Bola TV..', 'link' => 'yahoo.com']);
pre(DB::table('channels')->where('id in 1,2,4')->rows('name,link'),1);

DB::pdo()->beginTransaction();
DB::conn('mydb1')->table('users88')->insert(['name' => 'shamim1','email' => 'shamim1@gmail.com', 'votes' => 1]);
DB::conn('mydb1')->table('users88')->insert(['name' => 'shamim2','email' => 'shamim2@gmail.com', 'votes' => 2]);
DB::conn('mydb1')->table('users88')->insert(['name' => 'shamim3','email' => 'shamim3@gmail.com', 'votes' => 3]);
DB::pdo()->commit();
```
