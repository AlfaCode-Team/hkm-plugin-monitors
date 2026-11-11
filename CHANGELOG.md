# Changelog

## 0.1.0

Initial release.

* `monitor.alerting`: rules over usage metrics (`sum`, `gauge`, `growth`) with
  separate fire and resolve thresholds and consecutive-evaluation streaks.
* Notification by mail, webhook (`monitor.alert.fired|resolved` events) and
  console; per-channel retry; abandoned after `MONITORS_MAX_ATTEMPTS`.
* Evaluation as a queued job every five minutes, `withoutOverlapping`, plus a
  cache lock and an optimistic version per rule state.
* `monitors:add`, `monitors:status`, `monitors:evaluate`, `monitors:prune`.
* English and French message catalogues.
