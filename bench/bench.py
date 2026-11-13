#!/usr/bin/env python3
# bench.py — the same work as bench.grav and bench.php, against the same
# local server (redis-py): see bench/README.md.
import sys
import time
import redis

n = int(sys.argv[1]) if len(sys.argv) > 1 else 20000
host = sys.argv[2] if len(sys.argv) > 2 else "127.0.0.1"
port = int(sys.argv[3]) if len(sys.argv) > 3 else 54400
db = int(sys.argv[4]) if len(sys.argv) > 4 else 9

r = redis.Redis(host=host, port=port, db=db)
r.flushdb()

t0 = time.monotonic()
for i in range(n):
    r.set(f"k{i}", f"v{i}")
set_ms = int((time.monotonic() - t0) * 1000)

t1 = time.monotonic()
ok = 0
for i in range(n):
    if r.get(f"k{i}") == f"v{i}".encode():
        ok += 1
get_ms = int((time.monotonic() - t1) * 1000)

t2 = time.monotonic()
pipe = r.pipeline()
for i in range(n):
    pipe.get(f"k{i}")
pipe.execute()
pipe_ms = int((time.monotonic() - t2) * 1000)

print(f"redis-py: set={set_ms}ms get={get_ms}ms pipelined_get={pipe_ms}ms checked={ok}/{n}")
r.flushdb()
