# Integration guide

For whoever builds the consumer: a service or agent that calls `POST /api/run` and
merges the result into its own records. The full parameter and response reference is
in the [HTTP API reference](api/bootstrap-api.md); this page is the short list of
things that decide whether an integration works well.

## 1. Reaching the API

| Setup | URL |
|---|---|
| Local stack (`make up`) | `http://localhost/api/run` |
| Another container on the same Docker network | `http://<project>-nginx/api/run` (`profiltagent-nginx` by default) |
| Production | Whatever your reverse proxy publishes — see the [deploy runbook](deploy-runbook.md) |

Authenticate with the header `X-Api-Key: <AGENT_API_KEY>` (or `Authorization: Bearer …`).
A missing or wrong key returns `401 {"error":"unauthorized"}`.

> When you copy the key into an `.env` file, **quote it**. An unquoted value containing
> `#` is cut off at the `#`, and the failure looks exactly like a wrong key.

## 2. Send `code` whenever you have it

```json
{"company": "Acmena, UAB", "code": "300000123", "domain": "acmena.example"}
```

The registry code identifies the company unambiguously. It keys the stored profile, it
is what a connected registry is looked up by, and it lets the open-web source open a
directory page for exactly this company instead of searching by name.

Without `code`, the company is resolved by name and domain. That is heuristic: with a
registry connected you can lose the registry facts and every director it lists, and the
request still answers `200`. Check `sources_used` if it matters to you.

## 3. Request parameters worth using

| Field | Why you care |
|---|---|
| `company` | Required. |
| `code` | Registry code. See §2. |
| `domain` | Skips domain discovery when you already know the site. |
| `fields` | Declare only what you need (`["activity","description",…]`). The run fills those and stops. Omit it to get the catalogue's required fields. |
| `known_fields` | What you already hold. Recorded as `supplied` and not searched for. |
| `people` | Directors you already know, at most 10, each `{name, role?}`. They become news-search subjects. |
| `verify_fields` | Re-runs the sources on an **already complete** profile so that match verification can be established. Costs a full pass. |
| `session` | Your own correlation id (letters, digits and `-`, at most 36 characters). |

**A complete profile short-circuits.** If every declared field is already stored, the
source ladder stops at once and the request returns in a few seconds. Use
`verify_fields: true` when you need the sources to run anyway.

## 4. Reading the response

Beyond `profile`, these keys tell you **how much to trust what you got**:

| Key | Meaning |
|---|---|
| `verdict` | `sufficient`, `partial`, `data_limited` or `none`. |
| `coverage` | Overall and per-field score, and the list of missing fields. |
| `sources_used` | Which sources contributed. |
| `field_status` | Per field: `supplied`, `found` or `not_found`. |
| `field_sources` | Per field: which source the stored value came from. |
| `field_verification` | Per field: `verified`, `unverified` or `unknown`. See §5. |
| `match_verdicts` | Per source: the identity verdict its data was accepted on. |

A `partial` verdict is worth retrying; `data_limited` is not — every source ran cleanly
and the data is simply not public.

### `profile.news`

News is searched on every request, but `profile.news` holds the latest report that
found **at least one mention**; the key is absent until one does.

| Key | Meaning |
|---|---|
| `search_state` | Always `answered` here, because only a report with mentions is stored on the profile. |
| `mentions[]` | Each with `portal`, `title`, `url`, `date`, an `excerpt` when the article text was extracted, and `person` when the mention is attributed to a director rather than to the company. |
| `people_searched` | The people a query was actually **answered** for. |
| `people_dropped_reasons` | A list of records, one per person who was not searched, with the reason: `query_budget`, `time_budget`, `search_unanswered` or `unusable_name`. |

**A missing or unchanged `profile.news` does not mean "no news".** A search that could
not see — `unanswered`, `returned_nothing` or `off_portal` — is recorded on the run and
shown in the admin panel, but it does not reach the API response. If you need to tell
"searched, nothing found" from "could not search", look at the run in the admin panel;
[Concepts](concepts.md#the-news-search-states) defines the states.

## 5. The three verification states

- **`verified`** — a source confirmed to be about this company established the value.
- **`unverified`** — a source contributed the value, but its identity could **not** be
  established, for example because the match judge was unreachable. The data is there;
  the confirmation is not.
- **`unknown`** — no verification record exists for the stored value. It does not clear
  by itself; request `verify_fields: true`.

Alert on **`unverified`**, not on "anything that is not `verified`": `unknown` is the
ordinary state of values nobody has re-confirmed, and a rule that mixes the two will be
ignored.

Only the registry is identity-certain, because it is keyed by company code. Every other
source is judged by an LLM, and **the judge fails open**: during an LLM outage
contributions are accepted and recorded as `unverified` rather than rejected. Filtering
them, if you need to, is the consumer's job.

## 6. Timeouts and throughput

A request takes from a few seconds (complete stored profile) to about a minute (full
pass with news). The source ladder's default budget is 120 seconds; set your client
timeout **above that**.

One request is one company; there is no batch endpoint. Search engines rate-limit, so
pace a large batch rather than firing it in parallel — the
[deploy runbook](deploy-runbook.md) covers the throttle.

## 7. Limitations to code around

- **`role: null` means silence, not "no title".** A missing key, `null`, an empty string
  and whitespace all arrive as `null`, so you cannot conclude that a person holds no
  title, and a stored role stays until a source states a different one.
- **Verification is per company, on demand.** There is no bulk verification pass.
- **A run can fail on invalid text.** Invalid UTF-8 in scraped text makes the profile
  save fail; the run is closed as `failed` and nothing is stored. A retry is safe.

The full list is in [Known limitations](known-limitations.md).
