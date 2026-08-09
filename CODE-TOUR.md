# karhu-queue — Code Tour

> A **reading-guide map**, and a short **reference appendix** — outside the ten-tour sequence. Read [karhu](../karhu/CODE-TOUR.md) first, and ideally [mishka §7](../mishka/CODE-TOUR.md) (the sync/async payoff), because this package *is* the machinery that section describes. Three files, ~330 lines.
>
> **How to use it:** §1 why a database is the queue; §2 the interface; §3 `DatabaseQueue` and its one genuinely sharp detail; §4 the worker; §5 exercises.

---

## 1. Orientation — why a table, and not Redis

Recall the problem from the mishka tour: PHP is **synchronous and share-nothing**. A request cannot "start something and carry on" — when the request ends, the process is torn down. So deferring work means getting it *out of the request* entirely: the web request writes a row, and a **separate long-lived process** picks it up later.

This package is that pattern with no infrastructure added. The queue is a `jobs` table in the database you already have.

**The trade, honestly:** a dedicated broker gives you blocking pops, fan-out, and delivery semantics you'd otherwise hand-roll. A table gives you *transactional* enqueue (the job and your domain write commit together, or neither does), one fewer service to run, and the ability to inspect the backlog with `SELECT`. For a homelab app sending reminder emails, that trade is obviously right — and it's the same reasoning wojtek uses when it argues *against* an in-memory queue in its own tour.

---

## 2. `QueueInterface` — the seam

[src/QueueInterface.php](src/QueueInterface.php) (65 lines). `push` a job (name + payload + queue name), `pop` one, mark it `complete` or `failed`. That's it — small enough that a Redis or SQS driver could implement it later without touching callers. Same instinct as karhu-view's `ViewInterface`: **declare the shape, let the driver vary.**

---

## 3. `DatabaseQueue` — and the clause-order trap

[src/DatabaseQueue.php](src/DatabaseQueue.php) (186 lines) expects a `jobs` table whose schema is documented in the class docblock — `queue`, `job`, `data`, `status`, `error`, plus timestamps.

The interesting method is [`pop()`](src/DatabaseQueue.php#L78), because **claiming a job is a race**. Two workers must never take the same row. The package solves it per-driver ([:48-65](src/DatabaseQueue.php#L48-L65)):

- **PostgreSQL 9.5+** → the `SELECT` gets `FOR UPDATE SKIP LOCKED`. Row locks make the claim atomic, and `SKIP LOCKED` means a second worker steps over a locked row instead of blocking on it.
- **SQLite** → an empty suffix. There is no `FOR UPDATE` syntax; adding it would be a **syntax error**, not a slower query. SQLite's single-writer model makes it moot anyway.

**The gotcha, and it's a good one** ([:91-92](src/DatabaseQueue.php#L91-L92)): *clause order is load-bearing* — `LIMIT 1` must come **before** `FOR UPDATE SKIP LOCKED`, because PostgreSQL applies row locks **after** `LIMIT`. Get the order wrong and you have a query that runs fine, passes tests under low concurrency, and quietly mis-claims under load. This is exactly the class of bug that only appears when two workers finally run at once.

**Read it alongside** the driver-portability theme from the mishka tour: the same codebase runs PG in production and SQLite in tests, so anything driver-specific must degrade rather than break.

---

## 4. `Worker` — the loop

[src/Worker.php](src/Worker.php) (83 lines) is the long-lived half:

```php
$worker = new Worker($queue);
$worker->register('SendEmail', fn($data) => mail($data['to'], ...));
$worker->run();
```

[`register()`](src/Worker.php#L33) maps a job name to a callable; [`run()`](src/Worker.php#L39) loops `while ($this->running)`, and when [`processNext()`](src/Worker.php#L53) finds nothing it **sleeps** ([:47](src/Worker.php#L47), default 5s) rather than spinning. [`stop()`](src/Worker.php#L79) flips the flag.

**The shape worth naming:** this is *polling*, not blocking. A broker would let the worker block until a message arrives; a table can only be asked. The 5-second sleep is the latency/CPU dial, and it's the honest cost of the "no extra infrastructure" choice.

**Compare across the set:** wojtek (Node) holds work in a long-lived event loop *inside* the same process, because it can. PHP cannot, so the long-lived process is a **separate container** — which is precisely why mishka ships `mishka-app` and `mishka-worker` as two services off one image.

---

## 5. Active-recall exercises

1. **Two workers `pop()` simultaneously on PostgreSQL.** Walk the SQL and say what stops them claiming the same row — then say what happens on SQLite instead.
2. **Move `LIMIT 1` after `FOR UPDATE SKIP LOCKED`.** What breaks, and *when* would you notice? Why is that timing the dangerous part?
3. **The worker sleeps 5s when idle.** What does lowering it to 0.1s cost, and what does raising it to 60s cost? Which app in the set would notice first?
4. **Argue for replacing this with Redis** in mishka, then argue against. Use the transactional-enqueue property in both arguments.

---

*Tour covers karhu-queue @ `2c6f9cd`. A reference appendix — the ten-tour sequence ends at [koda-blast](../koda-blast/CODE-TOUR.md). Engine: [karhu](../karhu/CODE-TOUR.md). Seen in production in [mishka](../mishka/CODE-TOUR.md) §7.*
