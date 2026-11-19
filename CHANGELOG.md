# Changelog

Every release of grav-redis, newest first. Each is a `vX.Y.Z` tag with a GitHub
release carrying its section; the `grav` line in `grav.toml` says which
compiler versions it builds with.

## Unreleased

## 0.1.0 (2026-11-19)

The first tagged release. Needs grav 1.7.0 or newer.

A Redis client for [Grav](https://github.com/venomous-maker/grav): strings, keys, hashes, lists, sets and sorted sets, pipelining, transactions, pub/sub and basic scripting, over a RESP2 connection it speaks itself (no C client library, no dlopen — just a `TcpStream`). Compiled, with no interpreter or extension runtime underneath it: about an eighth of phpredis's or redis-py's memory for the same work, and comparable or better speed — see [bench/](bench/).
