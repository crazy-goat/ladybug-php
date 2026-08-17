dnl Build configuration for the ladybug extension.
dnl
dnl   phpize && ./configure --enable-ladybug && make
dnl
dnl liblbug is linked dynamically by default. --enable-ladybug-static links the static
dnl archive instead, which produces a self-contained .so at the cost of ~20 MB and a
dnl dependency on the C++ runtime (liblbug is a C++ library behind a C API).

PHP_ARG_ENABLE([ladybug],
  [whether to enable LadybugDB support],
  [AS_HELP_STRING([--enable-ladybug],
    [Enable LadybugDB support])],
  [no])

PHP_ARG_WITH([liblbug],
  [where to find liblbug],
  [AS_HELP_STRING([--with-liblbug=DIR],
    [Directory holding lbug.h and liblbug (default: search ../lib, /usr/local, /opt/homebrew)])],
  [yes],
  [no])

PHP_ARG_ENABLE([ladybug-static],
  [whether to link liblbug statically],
  [AS_HELP_STRING([--enable-ladybug-static],
    [Link liblbug statically; requires liblbug.a and a C++ runtime])],
  [no],
  [no])

if test "$PHP_LADYBUG" != "no"; then

  dnl -- locate lbug.h -------------------------------------------------------------

  LBUG_SEARCH_DIRS="$srcdir/.. $srcdir/../lib /usr/local /usr /opt/homebrew"
  if test "$PHP_LIBLBUG" != "yes" && test "$PHP_LIBLBUG" != "no"; then
    LBUG_SEARCH_DIRS="$PHP_LIBLBUG $PHP_LIBLBUG/lib $LBUG_SEARCH_DIRS"
  fi

  AC_MSG_CHECKING([for lbug.h])
  LBUG_INCLUDE_DIR=""
  for dir in $LBUG_SEARCH_DIRS; do
    if test -r "$dir/lbug.h"; then
      LBUG_INCLUDE_DIR="$dir"
      break
    fi
    if test -r "$dir/include/lbug.h"; then
      LBUG_INCLUDE_DIR="$dir/include"
      break
    fi
  done

  if test -z "$LBUG_INCLUDE_DIR"; then
    AC_MSG_RESULT([not found])
    AC_MSG_ERROR([lbug.h not found. Download a liblbug release and pass --with-liblbug=DIR.
Searched: $LBUG_SEARCH_DIRS])
  fi
  AC_MSG_RESULT([$LBUG_INCLUDE_DIR])
  PHP_ADD_INCLUDE([$LBUG_INCLUDE_DIR])

  dnl -- locate the library itself -------------------------------------------------

  if test "$PHP_LADYBUG_STATIC" != "no"; then
    AC_MSG_CHECKING([for liblbug.a])
    LBUG_STATIC_LIB=""
    for dir in $LBUG_SEARCH_DIRS; do
      if test -r "$dir/liblbug.a"; then
        LBUG_STATIC_LIB="$dir/liblbug.a"
        break
      fi
      if test -r "$dir/lib/liblbug.a"; then
        LBUG_STATIC_LIB="$dir/lib/liblbug.a"
        break
      fi
    done

    if test -z "$LBUG_STATIC_LIB"; then
      AC_MSG_RESULT([not found])
      AC_MSG_ERROR([liblbug.a not found; download a liblbug-static release, or drop
--enable-ladybug-static to link dynamically.])
    fi
    AC_MSG_RESULT([$LBUG_STATIC_LIB])

    LDFLAGS="$LDFLAGS $LBUG_STATIC_LIB"

    dnl liblbug is C++; a static link has to pull in the C++ runtime explicitly.
    AC_MSG_CHECKING([which C++ runtime to link])
    case $host_os in
      darwin*)
        LDFLAGS="$LDFLAGS -lc++"
        AC_MSG_RESULT([libc++])
        ;;
      *)
        LDFLAGS="$LDFLAGS -lstdc++"
        AC_MSG_RESULT([libstdc++])

        dnl A static link re-exports every global symbol taken out of the archive, including
        dnl the STB_GNU_UNIQUE locale facet ids that glibc binds process-wide no matter what
        dnl RTLD_DEEPBIND says — which would make this extension the first half of the crash
        dnl it exists to avoid. ladybug.map keeps liblbug's own symbols visible (its
        dnl downloaded extensions resolve against them) and hides the rest; the file explains
        dnl the split. --exclude-libs,ALL is the blunter alternative and breaks `LOAD json`.
        AC_MSG_CHECKING([whether the linker accepts a version script])
        LADYBUG_VERSION_SCRIPT=`cd "$srcdir" && pwd`/ladybug.map
        ladybug_saved_ldflags="$LDFLAGS"
        LDFLAGS="-Wl,--version-script=$LADYBUG_VERSION_SCRIPT"
        AC_LINK_IFELSE([AC_LANG_PROGRAM([[]], [[]])],
          [AC_MSG_RESULT([yes])
           LDFLAGS="$ladybug_saved_ldflags -Wl,--version-script=$LADYBUG_VERSION_SCRIPT"],
          [AC_MSG_RESULT([no; the extension will re-export liblbug's bundled C++ runtime])
           LDFLAGS="$ladybug_saved_ldflags"])
        ;;
    esac
    AC_DEFINE([LADYBUG_STATIC_LIBLBUG], [1], [liblbug is linked statically])
  else
    AC_MSG_CHECKING([for the liblbug shared library])
    LBUG_LIB_DIR=""
    for dir in $LBUG_SEARCH_DIRS; do
      for candidate in "$dir/liblbug.dylib" "$dir/liblbug.so" "$dir/lib/liblbug.dylib" "$dir/lib/liblbug.so"; do
        if test -r "$candidate"; then
          LBUG_LIB_DIR=`dirname "$candidate"`
          break
        fi
      done
      if test -n "$LBUG_LIB_DIR"; then
        break
      fi
    done

    if test -z "$LBUG_LIB_DIR"; then
      AC_MSG_RESULT([not found])
      AC_MSG_ERROR([liblbug shared library not found. Pass --with-liblbug=DIR.])
    fi
    AC_MSG_RESULT([$LBUG_LIB_DIR])

    dnl Absolute so the extension resolves liblbug without LD_LIBRARY_PATH; this is the
    dnl same trap the Homebrew elephc formula falls into with symlinked Cellar paths.
    LBUG_LIB_DIR=`cd "$LBUG_LIB_DIR" && pwd`
    PHP_ADD_LIBRARY_WITH_PATH([lbug], [$LBUG_LIB_DIR], [LADYBUG_SHARED_LIBADD])
    LDFLAGS="$LDFLAGS -Wl,-rpath,$LBUG_LIB_DIR"
  fi

  PHP_SUBST([LADYBUG_SHARED_LIBADD])

  PHP_NEW_EXTENSION([ladybug],
    [ladybug.c ladybug_value.c],
    [$ext_shared],,
    [-Wall -Wextra -Wno-unused-parameter])
fi
