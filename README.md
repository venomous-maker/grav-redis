# grav-redis

A Redis client for [Grav](https://github.com/venomous-maker/grav): strings,
keys, hashes, lists, sets and sorted sets, pipelining, transactions, pub/sub
and basic scripting, over a RESP2 connection it speaks itself (no C client
library, no dlopen — just a `TcpStream`). Compiled, with no interpreter or
extension runtime underneath it: about an eighth of phpredis's or redis-py's
memory for the same work, and comparable or better speed — see
[bench/](bench/).

```grav
import "std"
import "grav-redis"

fn main() {
    let r = RedisConnection.open("redis://127.0.0.1:6379/0")
    r.set("name", "Ada", 60)              // expires in 60 seconds
    print(r.get("name"))                  // "Ada"
    r.hSet("user:1", "role", "admin")
    print(r.hGet("user:1", "role"))       // "admin"
}
```

## Performance

See [bench/](bench/) for the full methodology. Best of 3, localhost, 20,000
operations:

| | grav-redis (`--release`) | phpredis (PHP) | redis-py |
|---|---:|---:|---:|
| SET / GET | **within noise of phpredis** | within noise of grav-redis | ~2.5× slower |
| pipelined GET | fast | fastest | several times slower |
| peak memory | **~5 MB** | ~42 MB | ~42 MB |

## Install

```bash
grav add /path/to/grav-redis          # or: grav add github.com/venomous-maker/grav-redis
```

```toml
# grav.toml
[dependencies]
grav-redis = "github.com/venomous-maker/grav-redis"
```

```grav
import "grav-redis"
use redis.*
```

## Connecting

```grav
let r = RedisConnection.open("redis://127.0.0.1:6379/0")
let r = RedisConnection.open("redis://user:password@host:6379/2")
let r = RedisConnection.open("redis://:password@host/0")       // no username
let r = RedisConnection.open("rediss://host:6380/0")           // TLS
let r = RedisConnection.open("redis://host/0?timeout=5000")    // read/write timeout, ms

// Or by hand:
let o = new RedisOptions()
o.host = "host"; o.port = 6380; o.tls = true; o.database = 2
let r = new RedisConnection(o)
```

`r.close()` closes it; `r.isOpen()` says whether it still is. Reads and
writes with no `timeout` option wait forever (as Redis itself does); set one
if a hung connection should fail instead of blocking.

**Named connections**, for code that reaches for one by name instead of
passing it around:

```grav
Redis.connect(RedisConnection.open("redis://localhost:6379"))
Redis.connect(RedisConnection.open("redis://localhost:6379/1"), "sessions")
Redis.get("name")                         // the default connection
Redis.connection("sessions").set("sid:1", "...")
```

## Strings

```grav
r.set("name", "Ada")
r.set("temp", "x", 60)                    // EX: expires in 60 seconds
r.set("temp", "x", 0, 500)                // PX: 500 milliseconds
r.set("once", "x", 0, 0, true)            // NX: only if it does not exist
r.set("existing", "x", 0, 0, false, true) // XX: only if it does
r.get("name")                             // "Ada", or null
r.getSet("name", "Grace")                 // the old value, after setting the new one
r.getDel("name")                          // the value, removing the key
r.append("name", "!")                     // the new length
r.strLen("name")
r.incr("hits"); r.decr("hits")            // incrBy, decrBy, incrByFloat
r.mGet(["a", "b", "nope"])                // ["1", "2", null]
r.mSet(values)                            // HashMap<string, any>
```

## Keys

```grav
r.del("a")                      // del(["a", "b", ...]) too
r.exists("a")                   // bool; exists(["a", "b"]) -> how many
r.expire("a", 60)               // pExpire (ms), expireAt (unix seconds)
r.ttl("a")                      // seconds left; -1 none, -2 no such key
r.persist("a")                  // drops the expiry
r.keyType("a")                  // "string", "list", "set", "zset", "hash", "none"
r.keys("user:*")                // every matching key (KEYS: for small keyspaces)
r.rename("a", "b")              // renameNx: only if "b" does not exist
r.randomKey()
r.copy("a", "b")

// SCAN: steps through the keyspace without blocking the server.
let cursor = 0 as long
while (true) {
    let step = r.scan(cursor, "user:*", 100)   // Pair<long, Array<string>>
    for (k in step.second) { print(k) }
    cursor = step.first
    if (cursor == 0) { break }
}
```

## Hashes, lists, sets, sorted sets

```grav
r.hSet("user:1", "name", "Ada")
r.hSet("user:1", values)                 // several at once (HashMap<string, any>)
r.hGet("user:1", "name")
r.hGetAll("user:1")                      // HashMap<string, string>
r.hDel("user:1", ["name"])
r.hIncrBy("user:1", "visits", 1)

r.rPush("queue", "job1"); r.lPush("queue", "first")
r.lRange("queue", 0, -1)
r.lPop("queue"); r.rPop("queue")
r.blPop(["queue"], 5)                    // waits up to 5 seconds; [key, value] or null

r.sAdd("tags", "red"); r.sAdd("tags", "blue")
r.sMembers("tags"); r.sIsMember("tags", "red")
r.sUnion(["tags", "other"])              // sInter, sDiff; ...store variants need command()

r.zAdd("scores", 10.0, "ada")
r.zAdd("scores", scored)                 // HashMap<string, double>
r.zRange("scores", 0, -1)                // lowest to highest; zRevRange the other way
r.zScore("scores", "ada"); r.zRank("scores", "ada")
r.zIncrBy("scores", 5.0, "ada")
```

## Anything else: the raw command

Every typed method above is built on one escape hatch, for whatever is not
wrapped: `command(name, args)` sends it and gives back the reply as a plain
value (a string, a long, null, or an `Array<any>` for a multi-bulk reply),
throwing `RedisError` for a `-ERR ...` one.

```grav
r.command("OBJECT", ["ENCODING", "name"])
r.command("CLIENT", ["GETNAME"])
r.command("CONFIG", ["GET", "maxmemory"])
```

## Pipelining

Queues every command and sends them in one batch (one `write()`, not one per
command), then reads every reply: much faster than one round trip each when
the replies are not needed until they are all in.

```grav
let results = r.pipeline(fn(p: RedisPipeline) {
    for (i in 0..1000) { p.command("INCR", ["counter:${i}"]) }
})
```

## Transactions

```grav
let out = r.transaction(new Array<string>(), fn(tx: RedisTransaction) {
    tx.command("DECRBY", ["from", "10"])
    tx.command("INCRBY", ["to", "10"])
})

// Optimistic locking: watch() keys, and exec() gives null if one changed first.
r.watch(["balance"])
let tx = r.multi()
tx.command("GET", ["balance"])
let result = tx.exec()                    // null: retry from watch()

// Or let transaction() retry it for you (3 attempts by default):
r.transaction(["balance"], fn(tx: RedisTransaction) {
    let balance = Integer.parseOr(r.get("balance"), 0)
    tx.command("SET", ["balance", balance - 10])
})
```

A transaction is queued on the client and sent as one `MULTI ... EXEC` batch
when `exec()` runs: one round trip however many commands it holds. So reads on
the connection itself inside the callback (`r.get` above) run straight away,
outside the transaction, and a command the server rejects surfaces as a
`RedisError` from `exec()` (the whole transaction is aborted).

## Pub/sub

```grav
r.publish("news", "hello")

// subscribe()/psubscribe() block forever, calling the function for each
// message; run them on their own connection and their own thread.
r.subscribe(["news"], fn(channel: string, message: string) { print(message) })
r.psubscribe(["news.*"], fn(pattern: string, channel: string, message: string) { print(message) })
```

## Scripting

```grav
r.eval("return redis.call('SET', KEYS[1], ARGV[1])", ["key"], ["value"])
let sha = r.scriptLoad("return 1")
r.evalSha(sha, new Array<string>())
```

## A pool, for several threads sharing one server

```grav
let pool = new RedisPool(8, fn() -> RedisConnection { return RedisConnection.open(url) })
pool.with(fn(r: RedisConnection) { r.incr("hits") })
```

A connection handed back with a `MULTI` or a `WATCH` still open is reset
before it is lent again; one that left nothing open goes back as it is, with
no extra round trip.

## Errors

`RedisError` (an `Exception`) for a `-ERR ...` reply; `.code` is its leading
word ("WRONGTYPE", "NOSCRIPT", ...). `NetError` and `TimeoutError` (from
`std/net`) for connection problems. `ValueError` for a bad URL.

## Not (yet) covered

Redis Cluster and Sentinel (client-side sharding and failover), RESP3 (no
`HELLO 3`: replies decode as RESP2 always gives them), streams, geo commands,
HyperLogLog, and the admin/introspection commands (`CLIENT`, `CONFIG`,
`DEBUG`, ...) — reach them with `command()`.

## Tests

Against a real Redis server (`REDIS_URL`, default `redis://127.0.0.1:6379/9`;
database 9, flushed before each test — do not point this at one with data you
want):

```bash
REDIS_URL="redis://127.0.0.1:6379/9" grav test tests/redis.grav
```

## License

MIT. See [LICENSE](LICENSE).
