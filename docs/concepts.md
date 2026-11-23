# Concepts — what this system's own words mean

ProfiltAgent uses a small number of words in ways you cannot guess from ordinary English.
`verified` is not the opposite of `unverified`. `supplied` does not mean "the caller sent it".
"We searched and found nothing" and "our search was never answered" are different stored
facts.

This document defines that vocabulary and says why each distinction exists. Where a term
appears in the admin panel, the on-screen wording is quoted.

---

## The one rule behind all of it

> **Never claim to have looked when the look could not have worked, and never state a fact the
> code cannot support.**

Call it the honesty invariant. Every three-way distinction below exists because a two-way one
forced the system to state something it did not know. The pattern to recognise: **one status
carrying two facts while the copy names only one.** That is not a wrong partition — it is a
missing discriminator, and the fix is always to add the third word rather than to redefine the
two.

The invariant has a cost, and it is accepted on purpose. The clearest case: the registry name
parser (`PersonName`) keeps role words such as `seniūnas`/`seniūnė` in its role vocabulary.
A person whose surname genuinely *is* such a word is therefore not parsed, and is reported as
**"name not recognised — not searched"** instead. Dropping a person is preferred to producing
a confident wrong name.

---

<a name="verified-unverified-unknown"></a>
## `verified` · `unverified` · `unknown`

Stored per field on `company_profiles.field_verification`, read **only** through
`CompanyProfile::verificationOf()`. Three states, and the third is the point:

| status | means | reading it as "verified" would be |
|---|---|---|
| `verified` | a source whose match verdict was itself verified established this value | correct |
| `unverified` | the value's source was accepted with **nothing verifying it** — a judge outage, or no verdict at all | a false claim about the company |
| `unknown` | **we hold no verification record for this value** | a false claim about *our own records* |

`unknown` is a fact about our bookkeeping, not about the company. Every profile row written
before the `field_verification` column existed reads `unknown`, and so does every field no
source has re-confirmed since. There is deliberately **no data backfill** — a backfilled
`verified` would be the purest form of the prohibited claim.

`verificationOf()` is the single place a stored entry is interpreted, and it returns `unknown`
for anything missing, malformed, or carrying an unrecognised status. That makes the rule
structural rather than a matter of discipline: **a reader cannot turn "we never recorded it"
into "a judge checked it", because no reader sees the raw blob.**

### Why `unknown` and `unverified` must not be collapsed

They call for different responses, so alerting keys on `unverified`:

- `unverified` — something went wrong. A judge outage, or an identity that did not check out.
  It does not clear itself.
- `unknown` — nothing has spoken for this value yet. It clears the next time a verified source
  touches the field. Collapsing the two would make every historical row look like a failure and
  train people to ignore the alert.

Two things to expect in practice (both are [D4](./known-limitations.md#d4)):

- `unknown` is widespread, and it does **not** self-clear on its own. For an already-enriched
  firm the registry alone fills every declared field, the ladder stops at the first source, and
  the fields only `official_site` can speak for are never re-asked.
- A stored value that no source re-finds stays `unknown` forever. That is the correct answer,
  not a gap: an opt-in verification pass makes `unknown` **addressable**, not unconditionally
  clearable.

In the request console, `unverified` renders as the badge **"match not verified"** (amber,
with the basis in the tooltip) and `unknown` as **"no verification record"** (grey).

### Provenance is not verification

Two parallel columns, two different questions, deliberately not merged:

- `field_sources` — **who the value came from.** `registry_db`, `official_site`, `caller`, …
- `field_verification` — **what was established about that source's claim to be this company,
  and when.** `{status, source, verdict, basis, at}`.

They can disagree, and one of them can change while the other must not. Note `source` inside a
verification entry is **who established the verification**, which is not always who owns
`field_sources` for that field — see [corroboration](./known-limitations.md#d18).

### A field is never more verified than the source it came from

A value is only as good as what was established about the source that contributed it, so a
field written by a source accepted `unverified` is stored `unverified` too. During a total
LLM outage `official_site` is still accepted — the judge fails open — but every field it
contributes says so, and nothing downstream may read those fields as checked.

---

<a name="identity-why-only-the-registry-is-certain"></a>
## Identity: why only the registry is certain

Every source's contribution is gated on one question — **is this the target company?** — and
there are exactly two ways to answer it:

**Identity as a fact.** `CompanyRegistrySource` reads a registry row **keyed by company code**.
The code *is* the identity; there is nothing to judge. Its assessment is recorded as
`pass` / `basis: registry_code`, and no LLM is asked. This is why registry people are trusted
even when the row mapped no declared field at all: their trustworthiness comes from the key,
not from the yield.

**Identity as a verdict.** Every other source reached the company through a name, a search
result or a link. `CompanyMatchJudge` — an LLM-as-judge — is asked whether the evidence really
is the target company, and returns `pass` / `warn` / `fail` derived deterministically from a
`match_confidence` score. A verdict is an opinion, however good.

That difference is a hard rule with a written boundary:

> The registry bypass may only ever be extended to a source whose identity is guaranteed by an
> **authoritative key**. It must never be extended to a source that reaches identity through
> the judge — that identity is a verdict, not a fact, and bypassing the judge for it would
> accept a stranger's fields as ours on nothing more than a guess.

The failure this prevents is concrete. Lithuanian company names collide, and a namesake's
directors merged in as this company's executives is unrecoverable in a CRM — nothing
downstream can tell them from the real ones. If a second identity-certain source is ever
added, mark it with an explicit method on `CollectionSource`; never infer trust from data
shape.

### `basis` — how a verdict was reached

`basis` exists so `pass` cannot be misread. A `pass` from an authoritative key and a `pass`
from an LLM are not the same claim.

| basis | meaning |
|---|---|
| `registry_code` | a registry row keyed by company code — identity is a fact |
| `judge` | the judge was asked here, while gating this source, and answered |
| `stored_verdict` | the source carried a judge verdict of its own (`official_site`'s `meta['quality']`) |
| `caller_supplied` | the caller asserted this itself (a supplied field value) |
| `no_verdict` | nothing produced a verdict; the acceptance rests on the fail-open default |
| `judge_unavailable` | a verdict-shaped answer with nothing behind it — the judge's fail-open |

And the verdict word `unverified` (`MatchAssessment::VERDICT_UNVERIFIED`) is used instead of
`warn` whenever nothing produced a verdict. `warn` means "a judge looked and was unsure";
`unverified` means "nobody looked". Keeping the two words apart is what makes a fail-open
acceptance auditable after the fact.

### The judge fails open

`rejects()` returns true only for a definite `fail`. A `warn` — including the fail-open `warn`
produced by any LLM error — accepts. **During an LLM outage every source is accepted.** This is
deliberate: failing closed would stop collection dead during a provider outage. The fail-open
is visible in every stored row and logged at `warning` level as it happens, but it still
accepts.
See [D3](./known-limitations.md#d3).

---

<a name="supplied-found-not_found"></a>
## `supplied` · `found` · `not_found`

Per declared field, in `field_status` on the collect response:

| value | means |
|---|---|
| `supplied` | provenance for this field is `caller` — we never searched for it |
| `found` | we searched and filled it |
| `not_found` | we ran the whole ladder and it is **not publicly available** |
| *(absent from the map)* | we stopped looking before finishing — **our** budget, not the firm's data ceiling |

**The fourth case is the important one.** A field left unresolved because the wall-clock budget
ran out is deliberately **omitted** rather than reported `not_found`. `not_found` is a claim
about the world — "this company has no such data" — and a run that stopped early has not
earned it. A consumer that iterates `field_status` sees fewer keys than it declared, and that
gap is itself the signal.

> **Known limitation:** `supplied` is derived from the **accumulated** profile's provenance,
> not from this run's input. A field some caller supplied in an earlier session reads
> `supplied` on every later run — including runs whose caller supplied nothing. Do not read
> `supplied` as "I sent this". See [D10](./known-limitations.md#d10).

### Run verdicts

Whole-run, computed from coverage alone:

| verdict | means |
|---|---|
| `sufficient` | every declared field is filled |
| `none` | no source contributed anything |
| `partial` | a declared field is still missing **and** either a source errored or the wall-clock budget ran out — a retry could still close the gap |
| `data_limited` | the ladder ran to completion cleanly and a declared field is still missing — the firm's public-data ceiling, not a failure |

`partial` versus `data_limited` is the same distinction as the fourth field-status case, one
level up: *we* stopped versus *the world* has no more. Never report a budget-truncated run as
a data ceiling.

> `verdict` and `coverage` know **nothing** about verification. `sufficient` says every declared
> field is filled; it says nothing about whether anything checked that the data is this
> company's. See [D1](./known-limitations.md#d1).

### Which fields are required is the caller's declaration

Not a fixed constant. `POST /api/run` takes `fields: [...]`; absent that, the admin-editable
catalogue at `/admin/profile-fields` supplies the default. **The caller declares what it
needs and ProfiltAgent fills only the gaps** — which is why `known_fields` exists, and why a
supplied field leaves the missing list before any source is asked for it.

---

<a name="the-three-news-states"></a>
<a name="the-news-search-states"></a>
## The news search states

The news dimension is a **separate signal**. It runs unconditionally, is stored alongside the
profile, and **never** enters the coverage score or the run verdict — a news hit cannot flip a
verdict from `none` to `data_limited`.

`search_state`, on the stored news blob, answers *what the search backend did for this run*.
There are four values (constants on `NewsSource`):

| `search_state` | status | means | what an operator should do |
|---|---|---|---|
| `answered` | `ok` / `empty` | the backend answered and returned results from whitelisted portals | trust the mention list, zero included |
| `returned_nothing` | `error` | at least one query was answered, and every answer was an empty list | retry — possibly a suspended engine |
| `off_portal` | `error` | results came back, none from a whitelisted portal — the `site:` filter was ignored, so no portal was searched | retry once an engine that honours `site:` is answering again |
| `unanswered` | `error` | **no query was answered at all** — refused by the local rate-limit window before it left the process, unreachable, or non-200 | retry later; more queries makes this worse |

One state means "the search could see"; the other three all carry `status: error` and mean
"it could not", for three different reasons. `search_state` exists because the status alone
cannot tell those three apart, and they call for different sentences and different responses.

### Why "empty" and "could not see" are kept apart

SearXNG returns **HTTP 200 with an empty list** when its upstream engines are rate-limited or
CAPTCHA-suspended. At the transport level that is indistinguishable from a company nobody has
written about: the same query can return ten results one minute and none the next. Without a
stored state, "no coverage" could mean "we could not see", and nothing would record which.

So the status `empty` is reserved for a search that was answered, reached the portals, and
kept nothing after filtering. In `NewsSource::collect()` the two `error` returns — no raw
results at all, and results but none from a whitelisted portal — come first, and `empty` is
reachable only after both; it cannot arise when nothing was answered.

### How the admin panel words it

**Request console.** The result has a **News** section, shown only when this run has a news
record of its own:

- The heading is **`News (N)`** with the real mention count — zero included — when the search
  was answered, and **`News (?)`** when it was not, so the heading never states a count the
  body says is unknown.
- `unanswered` → **"The search was not answered — unknown whether mentions exist."**
- `returned_nothing` → **"The search returned nothing — unknown whether mentions exist."**
- `off_portal` → **"The search did not reach the news portals — unknown whether mentions exist."**
- `answered` with no mentions → **"No mentions found."**
- A run for which no news record exists (news disabled, or the news source skipped) gets
  **no News section at all**. An older run's data is never presented as this one's.

**Collection run and company profile views.** Both have a **News mentions** section and take
their warning from one place, `NewsSearchNotice`, so the two cannot disagree. When the news
action of the run (for a profile: of the company's most recent run) has status `error`, an
amber line is shown:

- `unanswered` → **"News search was not answered by the search backend — unknown whether mentions exist."**
- `returned_nothing` → **"News search returned no results at all — unknown whether mentions exist."**
- `off_portal` → **"News search did not reach the news portals — unknown whether mentions exist."**
- an `error` with no recognised `search_state` → **"News search failed — unknown whether mentions exist."**

With no mentions to list, that line replaces the placeholder **"No third-party news
mentions."**, which is shown only when the search did not fail. On a profile that already
holds mentions from earlier runs, the line is shown above the stored list.

---

<a name="people-who-was-researched-and-who-was-not"></a>
## People: who was researched, and who was not

The news dimension also researches **people** — the company's directors and executives, from
the caller's list and from the registry. Because that costs queries, the report says exactly
who was reached.

**`people_searched` means the search backend *answered* a query naming that person.** Not "we
planned a query", not even "we sent one". The distinction is load-bearing: a planned query can
be cut off by the time budget, and a sent query can be refused by the rate-limit window — which
opens exactly when the engines are already degraded. Neither earns the person a "searched"
credit.

`people_dropped` is the single honest answer to "who went unresearched", whatever stopped us.
`people_dropped_reasons` pairs each name with the cause, because they call for **different
operator responses**:

| reason | means | response | UI |
|---|---|---|---|
| `query_budget` | the configured ceilings never had room | raise `react.news.max_queries` | **"query budget spent"** |
| `time_budget` | `react.news.time_budget_s` ran out mid-run | the backend was slow; investigate it | **"time budget spent"** |
| `search_unanswered` | the query got no answer at all | retry later — **more queries makes this worse** | **"search not answered"** |
| `unusable_name` | the value is not purely a person's name (a registry string with the role glued on, several people in one field, a company) | **fix the data, not the budget** | **"name not recognised — not searched"** |

One undifferentiated list would hide the one response that is actively harmful: raising the
query budget in response to `search_unanswered`.

`unusable_name` is the honesty invariant applied to input data. Rather than sending an
unmatchable query and awarding a green **"news searched"** badge for it, the person is
reported as dropped with a reason.

This matters at scale, because the registry commonly glues the role onto the name
(`"Ona Onaitė, direktorė"`). A parser (`PersonName`) extracts the name; what it cannot
confidently parse becomes `unusable_name` rather than a guess.

The signal that parser keys on is not the obvious one. Names are an open set and roles are a
closed vocabulary, and that asymmetry — not position, not letter case — is the rule. The role
can sit on either side of the comma, can be capitalised, and can itself contain commas, so
position-based splitting does not work.

### The budget is small, and it is shown

With the defaults — 14 enabled news portals, 7 portals per query, `react.news.max_queries` of
4 — the company's own queries take 2 of the 4 slots and each person needs two, leaving room
for exactly **one** person. So `max_queries` — not `max_people` — is the binding constraint,
and a firm with three directors reports one searched and two dropped with reason
`query_budget`. The request console shows the ceiling up front, in the **"People to research"**
section ("Limit on people whose news is searched for: N…"), rather than letting an operator
discover it afterwards.

The number is computed by asking the query builder itself (`NewsQueryBuilder::peopleBudget()`
runs `build()` with placeholder names) rather than re-deriving the arithmetic — so the
displayed ceiling cannot drift from the one the run actually applies.

---

## Where these words live in the data

| word | lives in |
|---|---|
| `verified` / `unverified` / `unknown` | `company_profiles.field_verification`, and `field_verification` on the collect response |
| `field_sources` provenance | `company_profiles.field_sources` |
| `verdict` / `basis` / `derived_from` | `collection_actions.detail`, under a `match` key; and `match_verdicts` on the response |
| `supplied` / `found` / `not_found` | `field_status` on the response (not stored) |
| run verdict | `collection_runs.verdict`, and `verdict` on the response |
| `search_state`, `people_searched`, `people_dropped`, `people_dropped_reasons` | the news blob — in `collection_actions.detail` for the news action, and in `company_profiles.fields['news']` **only when the run found at least one mention** |

That last asymmetry catches people. `mergeNews()` runs only when mentions were found, so on an
empty or blind run the people report reaches the **action row** and not the profile. Anything
rendering the blind-vs-empty distinction must read the action row, keyed by this run's hash;
reading the profile instead renders nothing at all, and the distinction disappears at the last
step. The request console, the collection run view and the company profile view all read the
action row for this reason.

---

## Related documents

- [known-limitations.md](./known-limitations.md) — where the code still acts as if it knew more than it does.
- [deploy-runbook.md](./deploy-runbook.md) — how to deploy, and the operator procedures.
- [api/bootstrap-api.md](./api/bootstrap-api.md) — the `POST /api/run` contract, field by field.
- [overview.md](./overview.md) — product overview.
