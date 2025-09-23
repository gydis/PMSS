# Repository Guidelines

## Project Context
- **Purpose**: PMSS is Pulsed Media's distro overlay for seedboxing, data hoarding, streaming etc. working on top of Debian distro and this repo is overlayed on top of the distro to manage the multi-tenant environment.
- **Supported OS**: Production targets Debian 10 (buster) and Debian 11 (bullseye); Debian 12 (bookworm) is currently under validation.
- **Current Freeze**: Do not modify `etc/skel/www` or its subdirectories until further notice; work in that area is paused.
- **Third-Party Libraries**: Treat bundled upstream or vendor code (e.g., ruTorrent front-end, Devristo helpers) as read-only unless explicit approval to update or replace is granted.
- **Updater Topology**: `update-step2.php` executes after the full repository tree is present, so it may depend on shared libraries under `scripts/lib/update`. In contrast `update.php` must remain a mostly self-contained bootstrapper—assume it might be the only file available during break-glass installs, so keep it focused on argument parsing, fetching the requested snapshot, and handing off to `update-step2.php`.

## Core Principles
- **KISS Principle**: Keep implementations simple, readable, and direct. Avoid unnecessary abstractions or over-engineering.
- **Single-Method Consistency**: When a problem has already been solved in this codebase, reuse the established method instead of introducing alternate approaches. Prefer shared helpers/abstractions over duplicating logic.
- **MVC Layering Mindset**: Organize logic so that data access, business rules, and presentation/output responsibilities remain clearly separated. Apply this separation consistently from method structure to overall file organization.
- **Fail-Soft Bias**: Favor recovering and continuing whenever safe instead of terminating execution. Only halt when the outcome would be catastrophic or data-damaging, and document the reason when an exit becomes unavoidable.
- **Readability & Reuse**: Prioritize human readability, comment generously, and reuse existing helpers rather than duplicating logic in new forms.
- **Predictable Provisioning Flow**: Scripts should follow a deliberate sequence—detect environment, gather inputs, prepare resources, execute actions, and report status—mirroring the clone-and-configure workflow in the reference tooling. Make every transition between steps explicit.
- **Change Justification**: Only make modifications when there is a clear, documented reason. Do not alter stable, long-lived behavior without evidence that change is required.
- **Commenting Rule**: Maintain comments such that, on average, at least one line of commentary appears for every ten lines of code (Linux Kernel style guidance).
- **Language Policy**: Default to Bash for automation tasks. Step up to PHP when workflows become lengthy or complex, keeping the logic centralized. Do **not** introduce Python; if a requirement appears to demand it, escalate instead of adding a Python dependency.

## Operational Philosophy
- **Safety First**: Destructive actions (partitioning, formatting, wiping identifiers) must be guarded with clear intent checks, informative logging, and opportunities for dry runs or confirmation steps where practical.
- **Environment Awareness**: Account for dual-boot/RAID boot devices and NVMe storage layouts similar to the sample provisioning script. When adapting logic, confirm device names, RAID membership, and mount points remain consistent across the workflow.
- **Idempotence and Recovery**: Design routines so that rerunning them on partially prepared systems is safe. Prefer explicit cleanup helpers (e.g., stopping arrays, unmounting filesystems) rather than ad-hoc sequences.
- **Observability**: Provide concise status output (`print_step`-style helpers, logging) to make long-running operations traceable when executed on bare-metal targets.

## Formatting & Linting
- **Bash**: Run `shellcheck` on every script and format with `shfmt -w` using the repository's style settings. Address all warnings before submitting changes.
- **PHP**: Execute `php -l` for syntax validation and format code with a PSR-12–compliant tool such as `php-cs-fixer` or `phpcbf` configured for this project.
- **Consistency**: Reuse existing helpers or configuration files in this repository when invoking the tools above. If new configuration is genuinely necessary, document the reason alongside the change.

## Package Baseline
- **dpkg Selections**: The file `scripts/lib/update/dpkg/selections.txt` is a direct capture from a production system. Do **not** edit it without explicit approval—the list must remain in sync with live hosts.
- **Release Baselines**: Per-release snapshots (`selections-debian10.txt`, `selections-debian11.txt`, `selections-debian12.txt`) mirror production environments. The Debian 12 list is generated from the bullseye baseline and must be validated before full rollout. Regenerate with `dpkg --get-selections` on a live host and strip any `deinstall` lines before committing.

## Operational Verification
- **Baseline Checks**: Until a formal test suite exists, run lightweight confirmations before committing—`bash -n`, `shellcheck`, and `php -l` as applicable—to ensure syntax correctness.
- **Safe Execution Proof**: When possible, exercise non-destructive entry points such as `--help`, `--dry-run`, or environment-detection routines and note the observed output. If the change affects destructive steps, document reasoning or out-of-band validation that supports the update.
- **Manual Traceability**: Record the commands or scenarios reviewed (including dry runs or log captures) so reviewers can follow the verification story.

## Dependency Policy
- **Default Stance**: Avoid adding new system packages, Composer dependencies, or external binaries unless there is a clear, reviewable justification.
- **Proposal Process**: If a new dependency is required, open a discussion or issue before implementation that explains the benefit, maintenance cost, and security considerations.
- **Implementation**: After approval, document installation steps and configuration updates within the repository so future contributors can reproduce the setup.

## Documentation Updates
- **Keep Docs Current**: Update README files, inline comments, usage examples, and configuration references whenever behavior or interfaces change.
- **Cross-Checks**: Review the diff to confirm new or modified logic has matching narrative documentation and comment coverage aligned with the 1-in-10 guideline.
- **Review Expectation**: Pull requests lacking necessary documentation revisions should be considered incomplete until updates accompany the code changes.

## Workflow Expectations
- **Required Checks**: Run the linting and operational verification steps outlined above before committing. Document the commands and their results in your work notes.
- **Future Instructions**: Check for additional `AGENTS.md` files within subdirectories before modifying files there; follow the most specific applicable instructions.
