--TEST--
Failures are exceptions, and a closed handle refuses to be used
--EXTENSIONS--
ladybug
--FILE--
<?php
$db = ladybug_database_open('', []);
$conn = ladybug_connect($db);

try {
    ladybug_query($conn, 'THIS IS NOT CYPHER');
} catch (Ladybug\Ext\QueryError $e) {
    echo "query error: ", (str_contains($e->getMessage(), 'xception') ? 'reported' : $e->getMessage()), "\n";
}

// The connection survives a failed query.
$result = ladybug_query($conn, 'RETURN 1 AS ok');
var_dump(ladybug_result_fetch($result));
ladybug_result_close($result);

// A result must not outlive its connection.
$result = ladybug_query($conn, 'RETURN 1 AS ok');
ladybug_connection_close($conn);
try {
    ladybug_result_fetch($result);
} catch (Ladybug\Ext\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    ladybug_query($conn, 'RETURN 1');
} catch (Ladybug\Ext\Exception $e) {
    echo $e->getMessage(), "\n";
}

try {
    ladybug_database_open('', ['nonsense' => 1]);
} catch (Ladybug\Ext\Exception $e) {
    echo $e->getMessage(), "\n";
}

ladybug_database_close($db);
?>
--EXPECT--
query error: reported
array(1) {
  [0]=>
  int(1)
}
This result handle is unusable: its connection was closed.
This connection handle is already closed.
Unknown configuration key "nonsense".
