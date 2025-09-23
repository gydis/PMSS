# Test Layout

- `development/` – fast, hermetic tests (run with `php scripts/lib/tests/development/Runner.php`).
- `production/` – post-provision probes intended for live hosts (run manually via
  `php scripts/lib/tests/production/Runner.php`).
- `common/` – shared utilities such as `TestCase`.

Keep development tests free of network/system side-effects; production tests may
rely on real services but should remain read-only.
