--TEST--
The extension loads and reports its ABI and liblbug version
--EXTENSIONS--
ladybug
--FILE--
<?php
var_dump(ladybug_abi_version());
var_dump((bool) preg_match('/^\d+\.\d+\.\d+/', ladybug_version()));
var_dump(class_exists('Ladybug\Ext\Database'));
var_dump(is_subclass_of('Ladybug\Ext\QueryError', 'Ladybug\Ext\Exception'));
?>
--EXPECT--
int(1)
bool(true)
bool(true)
bool(true)
