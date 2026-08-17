@NAME@
======

The ladybug extension for PHP @PHP@ on @PLATFORM@, with LadybugDB @LIBLBUG@ linked in.

Nothing else is needed on the machine: liblbug is part of this binary, not a dependency of it.
Everything here is MIT-licensed — this extension under the LICENSE file next to this one, and
LadybugDB under its own.


Installing
----------

    php -i | grep extension_dir          # where PHP looks for extensions
    cp ladybug.so "$(php-config --extension-dir)/"
    echo 'extension=ladybug.so' > "$(php-config --ini-dir)/99-ladybug.ini"

Or without copying anything, which is the fastest way to try it:

    php -d extension=/full/path/to/ladybug.so -m | grep ladybug

Then check what got loaded:

    php -r 'echo phpversion("ladybug"), " / liblbug ", ladybug_version(), PHP_EOL;'

The PHP side comes from Composer and finds this extension by itself:

    composer require crazy-goat/ladybug-php

With the extension loaded the library uses it; without it, the library falls back to FFI, which
needs a liblbug shared library on the machine. The API is identical either way.


Which file to take
------------------

The PHP version has to match exactly — 8.3 and 8.4 have incompatible module ABIs, and PHP
refuses to load the wrong one. `php -v` is the answer. Debug and thread-safe (ZTS) builds are
not covered by these binaries; those need `make ext-static` from a checkout.


Why it is 20-something megabytes
--------------------------------

liblbug is linked statically, and that is a correctness requirement rather than a packaging
preference. A dynamically linked liblbug 0.19.x crashes the process on `INSTALL` whenever a PHP
extension carrying its own libstdc++ is loaded — `intl` alone is enough — because glibc binds
libstdc++'s locale symbols process-wide while PHP loads extensions with RTLD_DEEPBIND, leaving
liblbug's locale registry split across two runtimes. The static build has no second runtime to
split against.

One consequence: do not use the FFI connector in a process that has this extension loaded. That
puts two copies of liblbug in one address space. The library picks the extension automatically
when both are available, so this only comes up if you ask for FFI explicitly.


Verifying the download
----------------------

    sha256sum -c SHA256SUMS --ignore-missing

SHA256SUMS is attached to the same release.
