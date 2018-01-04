# simple-PDO-wrapper
simple PDO wrapper class

# example

* db::table('user')->rowCount();
* db::table('user')->where("id>? AND name=?", ['1','john'])->get("email");
* db::table('user')->where("id>? AND name=?", ['1','john'])->get();

