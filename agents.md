# Coding Agent Notes

- Split non-library scripts once they cross 75 lines. Extract helpers into dedicated modules instead of letting single files grow beyond that point.
- Treat `etc/skel/www` as read-only for now; it is updated remotely and needs a coordinated plan before changing anything there.
- Keep the directory tree architectural: group code by responsibility (e.g. `/scripts/lib` for shared helpers, `/scripts/lib/update` for updater-specific code). When moving code, adjust includes/require paths alongside the move.
- Keep per-host automation idempotent so reruns converge systems to the same state; our only permissible drift comes from staggered rolling upgrades.
- Look for an `agents.local.md` file in the repo root; follow any host-specific instructions there before making local changes.
- Write tests in bundles (multiple cases per function), covering small variances and extreme out-of-bounds inputs while keeping them side-effect free—no test may mutate the real filesystem.
