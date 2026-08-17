<?php
require "/app/vendor/autoload.php";
$c = Ladybug\Database::inMemory(new Ladybug\Config(connector: "ffi"))->connect();
$c->run("INSTALL json");
echo "no crash\n";
