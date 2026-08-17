--TEST--
The module only loads against a supported liblbug, and reports what it checked
--EXTENSIONS--
ladybug
--FILE--
<?php
// Reaching this point at all means the MINIT compatibility check passed: on a mismatch the
// module returns FAILURE and the test would not run. What is asserted here is that the
// version it accepted really is in the supported series, and that the series is visible in
// phpinfo() so a mismatch can be diagnosed without reading the source.
$version = ladybug_version();
var_dump((bool) preg_match('/^\d+\.\d+\.\d+/', $version));

ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();

preg_match('/liblbug supported series => (\S+)/', $info, $series);
preg_match('/liblbug built against => (\S+)/', $info, $built);
preg_match('/liblbug storage version => (\d+)/', $info, $storage);

var_dump(isset($series[1], $built[1], $storage[1]));

// The running library has to belong to the series the extension declares support for.
$runtime = implode('.', array_slice(explode('.', $version), 0, 2));
var_dump($series[1] === $runtime . '.x');

// And the version it was built against must itself be in that series.
var_dump(str_starts_with($built[1], $runtime . '.'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
