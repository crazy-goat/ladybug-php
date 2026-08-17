# ladybug-ext

The native PHP extension for [LadybugDB](https://github.com/LadybugDB/ladybug), installable
with [PIE](https://github.com/php/pie). Verified against liblbug 0.19.1.

**This repository is generated.** The sources live in
[crazy-goat/ladybug-php](https://github.com/crazy-goat/ladybug-php) under `ext/`, and every
release overwrites this mirror with a single commit — issues and pull requests belong there.

## Installing

The extension links against liblbug, so the library has to be on the machine before the build.
For the static link, which is the one to use on Linux:

```bash
curl -sL https://raw.githubusercontent.com/crazy-goat/ladybug-php/main/tools/fetch-liblbug.sh \
  | bash -s 0.19.1 --static          # unpacks into ./lib
pie install crazy-goat/ladybug-ext --enable-ladybug-static --with-liblbug="$PWD/lib"
```

Or dynamically, if liblbug is already installed somewhere standard:

```bash
pie install crazy-goat/ladybug-ext
```

On Linux prefer the static link: a dynamically linked liblbug 0.19.x crashes the process on
`INSTALL` whenever another extension carrying libstdc++ (`intl` is enough) shares it. The
[main README](https://github.com/crazy-goat/ladybug-php#ladybugdb-extensions) has the mechanism.

Prebuilt binaries for PHP 8.2–8.5 are attached to every
[ladybug-php release](https://github.com/crazy-goat/ladybug-php/releases) if you would rather
not compile at all.

## What it gives you

`ladybug_*` functions, which are an internal ABI rather than an API to write against. Install
[crazy-goat/ladybug-php](https://packagist.org/packages/crazy-goat/ladybug-php) for the API; it
detects this extension and uses it automatically.

MIT, same as LadybugDB.
