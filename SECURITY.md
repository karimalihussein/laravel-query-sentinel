# Security Policy

## Reporting a vulnerability

Please report security issues privately: open a **private security advisory** on GitHub (Repository → Security → Advisories → New draft) or contact the maintainers directly. Do not open public issues for vulnerabilities.

## Security pipeline

This project runs a dedicated **Security** workflow (`.github/workflows/security.yml`) separate from CI:

- **When:** Weekly (Monday UTC), on push to `main` (relevant paths), and via manual `workflow_dispatch`.
- **What:** Lockfile enforcement, `composer validate --strict`, `composer audit` (fail on HIGH/CRITICAL), and CodeQL SAST for PHP.
- **Policy:** The workflow fails the run if any dependency has a known HIGH or CRITICAL severity advisory, or if CodeQL reports security findings.

## Supply chain

- **Lockfile:** `composer.lock` must be committed. The security workflow enforces its presence. All installs in CI and Security use the lock so builds are deterministic.
- **No `vendor/` in repo:** Dependencies are not committed. This avoids accidental inclusion of vulnerable or modified binaries and keeps the repo small. CI and Security install from lock via `composer install`.
- **Abandoned packages:** The workflow does not currently block on abandoned packages. Consider periodically running `composer show --direct` and reviewing for abandoned replacements; Dependabot can help surface some of these.
- **roave/security-advisories:** To make `composer update` fail when a dependency has a known vulnerability, add `roave/security-advisories:dev-latest` as a dev requirement. The Security workflow already runs `composer audit`; this is an optional extra safeguard for local and CI installs.

## Hardening and handling failures

- **CVSS threshold:** The Security workflow is configured to fail on **HIGH** and **CRITICAL** (Composer advisory severity). Lower severities do not fail the run but remain visible in the audit output.
- **Failing security scans:** When the Security workflow fails:
  1. Fix or upgrade the affected dependency (prefer upstream fix or patch).
  2. Re-run the workflow after merging the fix. Do not disable the workflow to “unblock” without addressing the finding.
- **False positives:** For CodeQL, use the GitHub Security tab to dismiss a finding with a reason (e.g. “Not used”) or add a code-level comment as documented in [CodeQL docs](https://codeql.github.com/docs/codeql-cli/about-codeql/). Do not disable the CodeQL job for the whole repo for a single false positive.
- **Secrets:** Do not commit secrets (API keys, tokens, passwords). The repository can use GitHub’s **secret scanning** (enabled at org/repo level) and **push protection** to block known secret patterns. Consider also enabling **code scanning** (which includes CodeQL) in the Security tab.

## Dependabot

`.github/dependabot.yml` is configured for weekly Composer updates with grouped minor/patch PRs. Security-related updates are prioritized by Dependabot; review and merge dependency PRs with care, especially those labeled or titled as security.
