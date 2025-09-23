# Production Test Scaffolding

These tests describe the intent for in-place system validation. They should run on
real PMSS hosts after provisioning to confirm packages, services, and
configuration drift. Keep them non-destructive and expect fuller coverage in
future iterations.

Run manually when ready:
```
php scripts/lib/tests/production/Runner.php
```
