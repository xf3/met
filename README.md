#MET

MET library allows to overcome Php's `max_execution_time` limit by splitting code execution into parts.

##Description

In case you cannot modify Php's `max_execution_time` directive and need to execute long-running script, you can use MET to split it and rerun itself each time limit is close with saving done changes.

Before run, class checks the ability to save data:
	* Start session for web request
	* Execute `php self_script.php` for CLI mode

###Usage examples

Say you have some script, which imports big database data into file:

```php
<?php
require 'met/MET.php';

function mysqlClose() {
	mysql_close();
}

mysql_connect('127.0.0.1', 'root', 'password') || die('Error connecting to Mysql');
mysql_select_db('bigdata_db') || die('Error switching database');

$MET = new MET();

$offset = $MET->value('offset', 0);

$r = mysql_query('SELECT * FROM logs ORDER BY id LIMIT '.$offset.' LIMIT 50');

while($row = mysql_fetch_assoc($r)) {
	appendToLogFile($row);
	++$offset;
	$MET->check('offset', $offset, 'mysqlClose');
}

mysqlClose();
```

Here we define `$offset` variable (0 for first call) and after each row iteration check whether time limit is close.

If reload is needed, `$offset` variable is updated to current value and user-defined `mysqlClose` function is called before exit to close existing Mysql connection.
