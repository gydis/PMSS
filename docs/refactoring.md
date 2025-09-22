# Refactoring Guidelines

PMSS follows Linux kernel style expectations when restructuring code. Keep the
following points in mind whenever you touch large files:

- **Keep single source files short.** Target ~150 lines per file; if you are
  pushing past ~200 lines, extract cohesive helpers or move logic into
  `scripts/lib/…` so callers compose small units. Splitting early keeps review
  surface manageable and mirrors the Linux kernel guidance captured in the repo
  root `README.md`.
- **Prefer focused modules.** When breaking a script apart, group related
  routines (e.g. package helpers vs. orchestration code) into dedicated files
  under the same feature directory. Avoid dumping unrelated functions into
  shared files.
- **Preserve behaviour.** Always keep upgrade paths for Debian 10 and 11 working
  while modernising logic for newer releases. Add adapters or fallbacks instead
  of rewriting flows in place.
- **Comment new helpers.** Maintain the 1-in-10 comment ratio by documenting why
  the split exists and what each helper does. Favour short docblocks at the top
  of each file.
- **Re-run lint/tests.** After refactoring, execute `php -l` on the touched
  files and run `php scripts/lib/tests/Runner.php` so regressions surface before
  shipping.

These rules complement the “Linux kernel style” note already present in the
repository documentation and should be referenced before undertaking larger
clean-ups.
