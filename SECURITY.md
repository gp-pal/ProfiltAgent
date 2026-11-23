# Security policy

## Reporting a vulnerability

Please report security problems **privately**. Do not open a public issue, pull request
or discussion for them.

- On GitHub: open the repository's **Security** tab and choose **Report a
  vulnerability**. This creates a private advisory visible only to the maintainers.
- Or use the contact details on <https://neuromomentum.network> and mention
  "ProfiltAgent security" in the subject.

Include what you can of the following:

- the affected component (orchestrator, runner, admin panel, deployment files) and the
  commit or version you tested;
- what an attacker can do, and under which configuration;
- steps to reproduce, or a proof of concept;
- whether the issue is already known to anyone else.

## What to expect

ProfiltAgent is maintained by Neuro Momentum, MB. We aim to acknowledge a report within
5 working days and to tell you whether we can reproduce it. We will keep you informed
while a fix is prepared, and we will credit you in the fix's notes unless you prefer
otherwise. Please give us a reasonable time to release a fix before you publish details.

## Supported versions

Fixes are made on the `main` branch. There are no maintained release branches.

## Scope

In scope: the code and deployment files in this repository, for example an
authentication bypass on the API or the admin panel, a way to make the runner or the
orchestrator reach internal addresses, a way to write outside the run directory, or
exposure of stored secrets or collected data.

Out of scope:

- an installation that runs with `AGENT_API_KEY` or `RUNNER_TOKEN` left empty, with
  `APP_DEBUG=true`, or without TLS in front of it. These are documented as unsafe; see
  the [deploy runbook](docs/deploy-runbook.md) and the
  [configuration reference](docs/configuration.md);
- vulnerabilities in third-party software (Laravel, Filament, Playwright, SearXNG and
  others). Report those to their maintainers; tell us if ProfiltAgent's use of them
  makes the impact worse;
- what the collector may lawfully gather. That is a question for the operator of an
  installation; see [Personal data](docs/personal-data.md).

## Testing

Test only against your own installation. Do not test against systems you do not own,
and do not use ProfiltAgent to collect data about real people as part of a test.
