# grav-redis against phpredis and redis-py

The same work, against the same local Redis server: [`bench.grav`](bench.grav),
[`bench.php`](bench.php) (phpredis, PHP's fastest common Redis client), and
[`bench.py`](bench.py) (redis-py). 20,000 `SET`s, then 20,000 `GET`s (checked
against what was set), then the same 20,000 `GET`s again, pipelined.

```bash
grav bench.grav --release -o redis_bench --emit bin && ./redis_bench 20000 redis://127.0.0.1:PORT/9
php bench.php 20000 127.0.0.1 PORT 9
python3 bench.py 20000 127.0.0.1 PORT 9
```

## Results

Best of 5 (redis-py: 3), localhost, Redis 8.0. Milliseconds (lower is
better); memory is peak RSS in MB, from an earlier run.

| | grav-redis (`--release`) | phpredis (PHP 8.4) | redis-py |
|---|---:|---:|---:|
| 20,000 SET | **585** | 682 | 1672 |
| 20,000 GET | **544** | 608 | 1492 |
| 20,000 GET, pipelined | 18 | **14** | 174 |
| peak memory (MB) | **5.2** | 41.6 | 41.7 |

On plain `SET`/`GET`, round trips to the same local server dominate all
three clients' time about equally — grav-redis and phpredis are within
noise of each other, and both run about 2.5× as fast as redis-py. Pipelined,
phpredis's C extension still has an edge (its whole pipeline API is in C);
grav-redis was at 24 ms before its framing stopped formatting every length
through a string and bulk replies stopped going through `Bytes` (about a
quarter fewer client instructions), and it stays several times faster than
redis-py either way.

Memory is where the difference is largest and most consistent: grav-redis
peaks at about an eighth of what the PHP and Python processes use, for the
same 20,000 round trips — a compiled client with no interpreter or extension
runtime underneath it.
