<?php

return [
    // When true, a missing domain triggers the LLM-driven ReAct search loop
    // (falls back to the heuristic SearchDdgService when false or unsupported).
    'search_enabled' => (bool) env('REACT_SEARCH_ENABLED', true),

    // Max LLM reasoning steps for the domain-search tool loop. Set to 12 (not 8)
    // so the agent has headroom to recover from occasional wandering (redundant
    // searches / non-About visits) and still confirm within budget.
    'max_steps' => (int) env('REACT_MAX_STEPS', 12),

    // Company-match quality judge (LLM-as-judge). Verifies a resolved domain /
    // built profile actually belongs to the target company, and rates how
    // complete the collected info is. Verdict is derived from match_confidence:
    //   >= pass_threshold  -> pass
    //   >= fail_threshold  -> warn
    //   <  fail_threshold  -> fail  (resolution rejects the candidate)
    // Fail-open: if disabled or the LLM errors, the judge never blocks a run.
    'match_judge' => [
        'enabled' => (bool) env('REACT_MATCH_JUDGE_ENABLED', true),
        'pass_threshold' => (float) env('REACT_MATCH_PASS_THRESHOLD', 0.7),
        'fail_threshold' => (float) env('REACT_MATCH_FAIL_THRESHOLD', 0.4),
    ],

    // Per-field profile-completeness scoring that drives the universal collector's
    // gather loop. A field scoring below gap_threshold is a "gap" to target with
    // another source; the loop stops once every field the caller declared is out of
    // the gap list, or once ladder_budget_s is spent. There is no overall-score
    // threshold any more — a declared field is either filled or still a gap.
    'coverage' => [
        'gap_threshold' => (float) env('REACT_COVERAGE_GAP_THRESHOLD', 0.5),

        // Wall-clock ceiling for the whole source ladder. With the score threshold
        // gone, this is the only thing stopping every run from touching every
        // source. Checked before each source; the first one always runs. A run cut
        // short here is 'partial' — never 'data_limited', which would claim the
        // company has no such data when we simply stopped looking.
        'ladder_budget_s' => (int) env('REACT_LADDER_BUDGET_S', 120),
    ],

    // Open-web source: search + fetch open informational pages (Wikipedia, news,
    // general web) about a target, skipping social networks and ToS-restricted
    // business directories. Used when there is no official site or coverage is low.
    'open_web' => [
        'enabled' => (bool) env('REACT_OPEN_WEB_ENABLED', true),
        'max_pages' => (int) env('REACT_OPEN_WEB_MAX_PAGES', 4),
        // The target's own page on a business directory. A page is used only when its
        // text contains the target's registry code, so a namesake's page (or a lookup
        // that found nothing) is never read as the target's.
        //
        // directory_urls: URL templates with `{code}` that open or redirect to the
        // company page directly. Comma-separated; empty disables.
        'directory_urls' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REACT_OPEN_WEB_DIRECTORY_URLS', 'https://imoniurekvizitai.lt/paieska/?q={code}')),
        ))),
        // directory_sites: hosts searched with `site:<host> <name>`, for directories
        // with no code-addressable URL. Only works while a search engine that honours
        // `site:` is answering. Comma-separated; empty (the default) disables.
        'directory_sites' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REACT_OPEN_WEB_DIRECTORY_SITES', '')),
        ))),
    ],

    // Contacts source: contact-focused search + regex extraction of emails/phones
    // from open pages that mention the target. Fills the email/phone gap for
    // site-less firms whose contacts aren't in the registry either.
    'contacts' => [
        'enabled' => (bool) env('REACT_CONTACTS_ENABLED', true),
        'max_pages' => (int) env('REACT_CONTACTS_MAX_PAGES', 4),
    ],

    // News dimension: third-party LT news-portal mentions of the target company.
    // A SEPARATE signal (news_score) — never mixed into the 6-field coverage or
    // the verdict. Only portals on the admin-editable whitelist count — see the
    // WebSearchFilters news whitelist.
    'news' => [
        'enabled' => (bool) env('REACT_NEWS_ENABLED', true),
        'max_mentions' => (int) env('REACT_NEWS_MAX_MENTIONS', 8),
        'freshness_months' => (int) env('REACT_NEWS_FRESHNESS_MONTHS', 12),
        'strong_portal_count' => (int) env('REACT_NEWS_STRONG_PORTAL_COUNT', 3),
        // When a mention has no date from SearXNG or the URL, fetch the article
        // page (cheap HTTP GET) and read its publish-date meta/JSON-LD. Bounded
        // to the kept mentions (<= max_mentions). Disable to skip the fetches.
        'fetch_dates' => (bool) env('REACT_NEWS_FETCH_DATES', true),
        // Portal-targeted search: the whitelisted domains (news_portals table,
        // editable at /admin/news-portals) are chunked into `site:` OR groups so
        // the query targets trusted sources, instead of post-filtering whatever a
        // generic search happened to return.
        // 7, not 5: with 14 whitelisted portals this is 2 `site:` groups instead of 3.
        // News was 8 of the ~15 searches a single company costs — more than half the
        // burst that earns a datacenter IP its rate-limit reputation.
        'portals_per_query' => (int) env('REACT_NEWS_PORTALS_PER_QUERY', 7),
        // Upper bound on people per run, NOT a promise: max_queries below can lower it,
        // and does. With 14 whitelisted portals and 7 per query the company groups take 2
        // of the 4 searches, leaving both forms of ONE person. NewsQueryBuilder::peopleBudget()
        // gets that effective number out of build() itself, and RequestConsole::peopleResearchBudget()
        // shows it to the operator before a run starts, so neither can drift from the plan.
        'max_people' => (int) env('REACT_NEWS_MAX_PEOPLE', 3),
        // Hard ceiling on searches per run, so a grown whitelist cannot blow the budget.
        // 4 with 7 portals per group means 2 company queries and 2 person forms, taking
        // one company's news cost from 8 searches to 4 and its total from ~15 to ~11.
        // This is the binding constraint on people: raise it, not max_people, to research
        // more of them — knowing that each additional person costs 2 more searches.
        'max_queries' => (int) env('REACT_NEWS_MAX_QUERIES', 4),
        // Wall-clock ceiling for the whole news phase. The first query always runs;
        // each later one only while budget remains. Without this the worst case is
        // max_queries x the 15s search timeout plus the date fetches — minutes past
        // any caller's patience — so a slow SearXNG must cost coverage, not the run.
        // 0 is valid and means "one query only".
        'time_budget_s' => (int) env('REACT_NEWS_TIME_BUDGET_S', 45),
        // Extract each kept article's text from the same download the date already
        // needed, via the runner's POST /markdown (no browser, so no bot wall). The
        // full text is stored as an artifact beside the captured HTML; the mention
        // carries only an excerpt, because the profile is returned over HTTP on every
        // run and whole articles would dwarf everything else in it.
        'extract_text' => (bool) env('REACT_NEWS_EXTRACT_TEXT', true),
        // Ceiling on articles downloaded for their text per run — each one is a request
        // to a third party. Dates are still backfilled beyond it, being far cheaper to
        // justify against a portal.
        'extract_max' => (int) env('REACT_NEWS_EXTRACT_MAX', 5),
        'excerpt_chars' => (int) env('REACT_NEWS_EXCERPT_CHARS', 600),
    ],
];
