# Personal data

ProfiltAgent collects information about companies, and some of that information is about
people. This page states what the software collects, where it keeps it, where it sends
it, and what it does not do for you. It is a description of the software, **not legal
advice**: whether and how you may process this data depends on the law that applies to you
(in the EU, the GDPR) and on your own purpose.

If you deploy ProfiltAgent, **you decide why and how the data is processed, so you are
its controller**. Neuro Momentum, MB does not receive any data from your installation.

## What personal data a run can contain

| Data | Where it comes from |
| --- | --- |
| Names and roles of directors and executives | The company registry you connect, the company's own site, open web pages, or the caller's request (`people[]`) |
| Contact details: e-mail addresses and phone numbers | The same sources. Most are company contacts, but a personal address or number published on a company page is collected like any other |
| News mentions attributed to a named person | Articles on the whitelisted news portals: title, link, date and a short excerpt |
| Text of the pages a run read | Stored as run artifacts; it contains whatever those pages contain |

Only publicly reachable pages are read. ProfiltAgent does not log in anywhere, does not
read social-network profiles, and does not build profiles of private individuals: a person
appears only in connection with a company. That data is public does not by itself make it
free to process; you still need a lawful basis for your use of it.

The admin panel has one account table (`users`) with the name, e-mail and password hash
of your own operators.

## Where it is stored

Everything stays in your installation:

| Location | Content |
| --- | --- |
| `company_profiles` table | The current profile of each company, including people and news |
| `collection_runs`, `collection_actions` tables | The history of every run: what was requested, which sources were used and what each yielded |
| `orchestrator/storage/app/private/runs/` | Per-run artifacts: captured page HTML and Markdown, screenshots, extracted news article text, reasoning traces |
| `orchestrator/storage/app/private/runs/memory_cache.json` | A cache of which domain was resolved for which company name |
| `orchestrator/storage/logs/laravel.log` | The application log, which can include company names and error context |

The database is the SQLite file `orchestrator/database/database.sqlite`.

## Where data leaves your infrastructure

A run sends data to third parties you choose in the configuration:

- **The LLM provider** (`LLM_PROVIDER`) receives the text of the pages being analysed,
  together with the company's name and code. This includes any names and contact details
  on those pages. Using a locally hosted model through Ollama keeps this inside your
  infrastructure.
- **Search engines**, through your SearXNG instance, receive the search queries. A query
  contains the company's name and, for the news search, the names of the people being
  researched.
- **The sites that are read** see requests from your server's address.
- **Langfuse**, only if you configure its keys, receives the prompts and model outputs of
  every run. Leave the keys empty, or point `LANGFUSE_BASE_URL` at your own instance, to
  avoid that.

Check each provider's terms and, where the law requires it, have a data-processing
agreement with them.

## What ProfiltAgent does not do

These are gaps you need to cover in your own process:

- **No retention limit.** Profiles, run history and run artifacts are kept until you
  delete them. Nothing expires by itself.
- **No deletion in the admin panel or the API.** Profiles and runs are read-only there.
  Deleting a company's or a person's data is a manual operation, described below.
- **No record of a lawful basis or of consent**, and no register of processing
  activities.
- **No handling of data-subject requests.** There is no built-in export or erasure per
  person. People are stored inside each company's profile, so finding one person means
  searching the stored profiles.
- **No field-level access control.** Every admin user sees everything.
- **Opt-outs are not checked.** The collector does not consult any do-not-contact list
  before storing a contact detail.

## Deleting data

To remove everything stored about one company, identified by its registry code:

```bash
docker compose exec php_orchestrator php artisan tinker
```

```php
$runs = \App\Models\CollectionRun::where('company_code', '300000123')->get();
\App\Models\CollectionAction::whereIn('run_hash', $runs->pluck('run_hash'))->delete();
$sessions = $runs->pluck('session')->filter()->unique();   // note these for the next step
\App\Models\CollectionRun::where('company_code', '300000123')->delete();
\App\Models\CompanyProfile::where('company_code', '300000123')->delete();
```

Then delete the artifact folder of each session noted above:

```bash
rm -rf orchestrator/storage/app/private/runs/<session>
```

Take a backup of `database.sqlite` first if you may need to undo this. To remove a single
person, edit the `fields` JSON of the company's row in `company_profiles`, and remember
that earlier runs and their artifacts still hold the same data.

A recurring clean-up (for example, deleting run artifacts older than a set number of days)
is not included; schedule it yourself if your retention policy needs one.

## Reducing what is collected

- **Ask only for the fields you need.** A run fills the fields the caller declares; leave
  out `emails` and `phones` if you do not need contact details.
- **Do not send `people[]`** and no one is searched by name in the news.
- **Turn sources off**: `REACT_NEWS_ENABLED=false`, `REACT_CONTACTS_ENABLED=false`,
  `REACT_OPEN_WEB_ENABLED=false`. See the [configuration reference](configuration.md).
- **Stop storing article text**: `REACT_NEWS_EXTRACT_TEXT=false`.
- **Keep model calls in-house** with a local model, and leave Langfuse unconfigured.

## Other things to check before you deploy

- The terms of use of the sites and directories you collect from, including the default
  business directory in `REACT_OPEN_WEB_DIRECTORY_URLS`.
- Whether your use of contact details for outreach is allowed under the rules on
  unsolicited communication that apply to you.
- Who can reach the admin panel and the API. Both expose everything collected; see the
  security section of the [README](../README.md#security) and the
  [deploy runbook](deploy-runbook.md).
