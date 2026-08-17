--TEST--
An in-memory database round-trips a row
--EXTENSIONS--
ladybug
--FILE--
<?php
$db = ladybug_database_open('', []);
$conn = ladybug_connect($db);

ladybug_result_close(ladybug_query($conn, 'CREATE NODE TABLE P(name STRING, age INT64, PRIMARY KEY(name))'));
ladybug_result_close(ladybug_query($conn, "CREATE (:P {name: 'Ada', age: 36})"));

$result = ladybug_query($conn, 'MATCH (p:P) RETURN p.name, p.age');
var_dump(ladybug_result_column_names($result));
var_dump(ladybug_result_row_count($result));
var_dump(ladybug_result_fetch($result));
var_dump(ladybug_result_fetch($result));
ladybug_result_close($result);

ladybug_connection_close($conn);
ladybug_database_close($db);
?>
--EXPECT--
array(2) {
  [0]=>
  string(6) "p.name"
  [1]=>
  string(5) "p.age"
}
int(1)
array(2) {
  [0]=>
  string(3) "Ada"
  [1]=>
  int(36)
}
NULL
