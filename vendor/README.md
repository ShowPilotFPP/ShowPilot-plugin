# Vendored dependencies

`ws/` is the [`ws`](https://github.com/websockets/ws) WebSocket library, version 8.21.3,
copied unmodified from the npm registry tarball (MIT license, see `ws/LICENSE`). It has no
dependencies of its own.

It is vendored so installing the plugin needs only Debian's `nodejs` package, not `npm`
(Debian's `npm` pulls in hundreds of packages). To update: `npm pack ws@<version>`, extract,
and replace `ws/` with the tarball's `package/` contents (the README can be omitted).
