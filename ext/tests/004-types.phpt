--TEST--
Values are converted to PHP types in C
--EXTENSIONS--
ladybug
--FILE--
<?php
$db = ladybug_database_open('', []);
$conn = ladybug_connect($db);

function one($conn, string $cypher) {
    $result = ladybug_query($conn, $cypher);
    $row = ladybug_result_fetch($result);
    ladybug_result_close($result);
    return $row[0];
}

var_dump(one($conn, 'RETURN 42 AS v'));
var_dump(one($conn, 'RETURN 1.5 AS v'));
var_dump(one($conn, 'RETURN "text" AS v'));
var_dump(one($conn, 'RETURN true AS v'));
var_dump(one($conn, 'RETURN null AS v'));
var_dump(one($conn, 'RETURN [1, 2, 3] AS v'));
var_dump(one($conn, 'RETURN {a: 1, b: "two"} AS v'));
echo one($conn, 'RETURN date("2026-08-17") AS v')->format('Y-m-d T'), "\n";
echo one($conn, 'RETURN timestamp("2026-08-17 10:20:30.5") AS v')->format('Y-m-d H:i:s.u'), "\n";
echo get_class(one($conn, 'RETURN interval("2 days") AS v')), "\n";
echo one($conn, 'RETURN CAST("170141183460469231731687303715884105727" AS INT128) AS v'), "\n";
echo one($conn, 'RETURN CAST("123.456" AS DECIMAL(10, 3)) AS v'), "\n";

ladybug_connection_close($conn);
ladybug_database_close($db);
?>
--EXPECT--
int(42)
float(1.5)
string(4) "text"
bool(true)
NULL
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  string(3) "two"
}
2026-08-17 UTC
2026-08-17 10:20:30.500000
DateInterval
170141183460469231731687303715884105727
123.456
