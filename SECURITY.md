# Security Policy

## Reporting a vulnerability

Please report security issues privately: open a **private security advisory** on GitHub (Repository → Security → Advisories → New draft) or contact the maintainers directly. Do not open public issues for vulnerabilities.

## Security pipeline

This project runs a dedicated **Security** workflow (`.github/workflows/security.yml`) separate from CI:

- **When:** Weekly (Monday UTC), on push to `main` (relevant paths), and via manual `workflow_dispatch`.
- **What:** `composer validate --strict`, dependency resolution (with or without committed `composer.lock`), `composer audit` (fail on HIGH/CRITICAL), and **Semgrep** SAST for PHP (CodeQL does not support PHP).
- **Policy:** The workflow fails if any dependency has a known HIGH or CRITICAL advisory, or if Semgrep reports findings (use `.semgrepignore` or inline `nosemgrep` for false positives).

## Supply chain

- **Lockfile:** This is a **library** package. `composer.lock` is **not** required to be committed; dependency resolution is left to the consuming application. The Security workflow resolves dependencies (from lock if present, otherwise via `composer update`) then runs `composer audit`.
- **No `vendor/` in repo:** Dependencies are not committed. CI and Security install or resolve dependencies during the run only.
- **Abandoned packages:** Not enforced in the workflow. Periodically run `composer show --direct` and review; Dependabot can help.
- **roave/security-advisories:** Optional dev requirement to make `composer update` fail when a known vulnerability exists; the workflow already runs `composer audit`.

## Hardening and handling failures

- **CVSS threshold:** Fail on **HIGH** and **CRITICAL** from `composer audit`. Lower severities are visible but do not fail the run.
- **Failing security scans:** Fix or upgrade the affected dependency; re-run after merging. Do not disable the workflow to unblock without addressing the finding.
- **False positives (Semgrep):** Add a `.semgrepignore` file or use inline `# nosemgrep` comments. See [Semgrep ignoring code](https://semgrep.dev/docs/ignoring-files-folders-code/).
- **Secrets:** Do not commit secrets. Use GitHub secret scanning and push protection if available.

## Dependabot

`.github/dependabot.yml` is configured for weekly Composer updates with grouped minor/patch PRs. Review dependency PRs with care.
