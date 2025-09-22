# Coding Agent Notes

- Split non-library scripts once they cross 75 lines. Extract helpers into dedicated modules instead of letting single files grow beyond that point.
- Treat `etc/skel/www` as read-only for now; it is updated remotely and needs a coordinated plan before changing anything there.
- Keep the directory tree architectural: group code by responsibility (e.g. `/scripts/lib` for shared helpers, `/scripts/lib/update` for updater-specific code). When moving code, adjust includes/require paths alongside the move.
