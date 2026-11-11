# hkm-plugin-monitors

Rules over usage metrics that fire and resolve **with hysteresis**, and tell
people by mail, webhook and console.

Solves `monitor.alerting`. Requires `usage.metering`, `webhook.delivery` and
`mail.delivery`. Declares no routes.

```php
$monitors->save(Rule::define(
    id: Rule::newId(), name: 'api.errors',
    metric: 'http.errors', source: Source::Sum, windowSeconds: 3600,
    comparison: Comparison::Gt,
    fireThreshold: 100, resolveThreshold: 80,   // the band
    fireAfter: 2, resolveAfter: 3,              // the streaks
    severity: Severity::Critical, mailTo: ['ops@example.com'],
));
```

```
hkm cli monitors:add api.errors --metric=http.errors --fire=100 --resolve=80 \
    --fire-after=2 --resolve-after=3 --severity=critical --mail=ops@example.com
hkm cli monitors:status        # exits 1 while a rule is firing or an alert is undelivered
hkm cli monitors:evaluate      # queue an evaluation now
```

A rule reads one of three things from the Usage plugin:

| `source` | value | window |
|---|---|---|
| `sum` | counter total | the last `windowSeconds` (a multiple of 3600 — usage is hourly) |
| `gauge` | the latest level | — |
| `growth` | `(this window − the one before) / the one before`, a fraction | `windowSeconds` |

---

## Hysteresis — the part that pages someone at 3am

A metric that hovers at its threshold must not fire, resolve, fire, resolve.
Two independent guards; use either or both:

* **Band** — `resolveThreshold` differs from `fireThreshold`. A `gt 100` rule
  with `resolve 80` fires above 100 and does not resolve until the metric is at or
  below 80. A reading of 90 changes nothing.
* **Streak** — `fireAfter` / `resolveAfter` consecutive evaluations. One spike is
  not an incident; one good reading is not a recovery.

```
ok      + breach of FIRE      -> breach streak +1, fires at fireAfter
ok      + anything else       -> breach streak 0
firing  + clear of RESOLVE    -> clear streak +1, resolves at resolveAfter
firing  + anything else       -> clear streak 0   (including the band between them)
no reading                    -> nothing moves
```

`Rule::define()` refuses a resolve threshold on the wrong side of the fire
threshold (it could resolve while still breaching, or never resolve). A rule with
neither guard (`resolve == fire`, both streaks 1) is allowed — and flags itself
via `isDamped()`; `monitors:add` and `monitors:status` warn about it.

`HysteresisTest` oscillates a metric across the boundary and asserts **a single
fire** with a band alone, with streaks alone, and end to end through the service
(mail, webhook and console each once). It also asserts the same oscillation
**flaps** on an undamped rule, so the test cannot pass for the wrong reason.

**No data is neither a breach nor a recovery.** A gauge never reported, growth
from an empty window, or an unreadable metric leaves the rule exactly where it
was. Treating silence as OK would turn a dead pipeline into a resolved alert.
(The corollary: a metric that goes quiet while a rule is firing never resolves
it. Alerting *on* silence is not built.)

## Overlapping evaluations cannot double-fire — three guards

1. **`withoutOverlapping: true`** on the `monitors.evaluate` schedule entry.
   `ScheduleDeclarationTest` compiles this plugin's real `module.json` with the
   kernel's own `CompileScheduleManifestStage` and ticks the kernel's own
   `Scheduler` twice: one evaluation is queued, the second dispatch is refused.
   `expiresAfter` is 240, deliberately *below* the five-minute interval: for a
   queued job the lock is not released when the job finishes, it only expires, so
   anything longer would also skip the next tick.
2. **A `CachePort` lock** around `evaluate()` (when a cache is bound), released in
   a `finally`.
3. **An optimistic version on each rule's state row.** The loser of a race writes
   nothing — this holds with no cache and no scheduler. Tested by letting a rival
   evaluator commit *between* the first one's read and its write, for a rule with
   and without an existing state row.

## How a notification is made

Only a **state change** is notified: `ok → firing` and `firing → ok`.

The state change and its alert row are committed in **one transaction**; only
**after** that commit is anything sent (`MonitorService::deliverPending`). Each
channel is tracked, so a retry sends only the channels that have not been
delivered: a broken webhook does not re-send the mail. An alert is retried on
every evaluation, up to `MONITORS_MAX_ATTEMPTS`, then marked abandoned.
`monitors:status` exits non-zero for pending or abandoned alerts. Routing is
**snapshotted onto the alert**, so editing or deleting a rule cannot redirect or
strand a page already in flight. Delivery is **at-least-once**.

| Channel | How |
|---|---|
| `mail` | One message per recipient (addresses are not disclosed to each other) through `MailerContract::dispatch()` — *synchronously*, so a transport failure fails the channel. Words come from `resources/lang/{en,fr}/alert.php`, overridable by a project through I18n. |
| `webhook` | Dispatches `monitor.alert.fired` / `monitor.alert.resolved` on the EventBus. **`webhook.delivery` has no "send" method** — it subscribes to the bus and delivers to endpoints that registered a pattern, so register an endpoint for `monitor.alert.*`. `EventBus::dispatch()` *returns* listener failures instead of throwing; the notifier turns any into a failed channel. |
| `console` | The application log (level follows severity; a resolution is `info`). |

**Mail needs a From address.** Set `MAIL_FROM_ADDRESS` (the Mail plugin's own
key); without it every mail fails with "No From address … no default configured"
and the alert stays pending. The end-to-end test caught this.

## Config

| Env | Default | |
|---|---|---|
| `MONITORS_QUEUE` | `monitors` | queue for the evaluation job. Run a worker on it |
| `MONITORS_MAX_ATTEMPTS` | `5` | per alert, then abandoned |
| `MONITORS_CLAIM_TIMEOUT` | `300` | seconds before a claimed alert may be taken by another deliverer |
| `MONITORS_RETAIN_DAYS` | `90` | settled alerts only; an undelivered one is never pruned |
| `MONITORS_LOCK_SECONDS` | `240` | TTL of the evaluation lock |
| `MONITORS_BATCH` | `50` | alerts delivered per run |

Evaluation runs as a **queued job** (`monitors.evaluate`, every five minutes),
not a scheduler `command`, because the work needs the worker's container — the
Usage contract, and the Webhooks listener. `monitors:evaluate` queues that same
job; it does not evaluate inside the CLI process, which has no way to read
metrics.

## Verified / NOT verified

**Verified** (run):

* the state machine, as a pure function, including the oscillation pins above, no
  data, `lt`/`lte` mirrors, `gt` vs `gte` exactly at the threshold;
* the real SQL on a migrated **SQLite** database: commit atomicity (a failed alert
  insert rolls the state change back), the version race, exclusive claiming of a
  pending alert (a rival claims between another deliverer's read and its write),
  per-channel retry, give-up, pruning that spares undelivered alerts, snapshots;
* `withoutOverlapping` against the kernel's real compiler and scheduler;
* the whole chain through the **real kernel** (Ground boots all five modules):
  3 HTTP requests → `usage.flush` → worker → `monitors:add` and `monitors:evaluate`
  through the real CLI → worker runs the job → the rule fires once → mail (the Mail
  plugin's `array` transport) and console delivered → a second evaluation raises
  nothing; `monitors:status` exits 1;
* revert checks: ~45 one-line breaks, all caught (see CHANGELOG).

**NOT verified, or known broken elsewhere:**

* **The webhook channel does not currently deliver end to end.** Against
  `hkm-plugin-webhooks` v0.1.0 the kernel cannot build its `EnqueueWebhookListener`:
  `WebhookService` is `bindInternal()`, and the EventBus resolves the listener from
  outside that module's scope, so every dispatch ends in
  `ScopeViolationException: Cannot resolve internal [WebhookService] from scope
  [kernel]`. This plugin reports it, keeps the alert pending and retries — and
  that is tested — but nothing reaches an endpoint until Webhooks binds the
  listener (or `WebhookService`) publicly. The end-to-end test records this as
  *incomplete* and will pass by itself once fixed. What **is** verified: the event
  names and primitive-only payload, that a real `WebhookEndpoint` registered for
  `monitor.alert.*`, `monitor.**` or `monitor.alert.fired` subscribes to them, and
  that a listener that throws fails the channel.
* **MySQL and PostgreSQL** — SQLite only. Likewise real concurrency across
  processes, a real SMTP server, a real HTTP receiver, PHP-FPM, OpenSwoole.
* The scheduler lock's **expiry** (`FakeCache` locks do not lapse); only the
  declared numbers are checked.
* `monitors:*` commands were run through `BufferIO` and registered by the real
  kernel, never from a real terminal.
* Not built: re-notifying a long-firing alert, silences/maintenance windows,
  alerting on missing data, per-rule evaluation intervals, a rule-edit route (no
  routes by design — the application owns who may edit rules).
