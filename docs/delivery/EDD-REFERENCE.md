# Evidence-Driven Delivery (EDD) — Methodology Reference

> Reusable, public-safe methodology extract, distilled from the canonical
> `EVIDENCE-DRIVEN-DELIVERY.md` (~1,130 lines) and its production
> implementations across several private repositories. All names, ticket
> keys, URLs, and business specifics are generic placeholders (`ACME-###`,
> `CB-###`, `client-x`).

---

## 1. Canonical Definition and Principles

EDD is a delivery methodology, not a project plan. The single-paragraph
definition (verbatim, EDD §0):

> Every piece of client work follows one chain of custody:
> **captured source (discovery requirement · meeting decision · production
> observation) → Linear issue → work packet → small PR(s) → review-feedback
> digest loop → structurally gated merge → deploy + outcome evidence → issue
> auto-closed → client follow-up.**

The artifact split: **Linear owns status and conversation; the work packet
owns scope and proof.** Evidence lives in git next to the code — versioned,
reviewable, greppable, able to hold binary evidence, and durable across
tracker migrations. Status updates flow *from* git events *to* the tracker
via magic-word automation (`Fixes ACME-###` / `Part of ACME-###`), not from
humans typing the next box.

### The 10 principles (verbatim, ordered, conflicts resolved by earlier-wins)

1. **No issue, no work.** Every unit of work has a Linear issue key before
   code starts — it is the traceability spine. Never mint work IDs from
   files in the repo: file-based task numbering collides the moment multiple
   agents work in parallel worktrees.
2. **The repo is the source of truth; the tracker is a synchronized view.**
   Scope, evidence, and review history live in git next to the code.
   Tracker status must be updated *by automation from git events*, because
   manual status updates always lag.
3. **One checklist item = one PR.** Every implementation slice must be
   independently mergeable without breaking the default branch (feature
   flag, stub, or no-op if necessary).
4. **Merge gates must be structural, not aspirational.** A rule written in
   a doc is a suggestion; a rule configured in branch protection is a gate.
   "0 unresolved review threads" existed on paper for months and was
   routinely violated — until it became branch protection's *required
   conversation resolution*, after which every sampled merge complied.
5. **Evidence over claims.** Use the Evidence Ladder (see §3 below). The
   lightest evidence that proves the claim, and never overstate. Assert
   **business outcomes** (amounts, counts, byte sizes) — never HTTP
   statuses or "command succeeded". Honest partial completion is rewarded.
   Overstated completion is the cardinal sin.
6. **Few hard checks beat many soft checks.** A CI step with `|| true` is
   theater. Every automated check either blocks, or carries a written
   reason plus a date on which it becomes blocking.
7. **Feed scar tissue into the reviewers.** Review quality came from the
   repo-tuned rulebook, not from the bots. Every production incident adds a
   named, greppable P0 rule.
8. **The process audits itself weekly.** Distillation pass + evidence-review
   gate, on the calendar, run by an agent. Without them, digest and
   evidence discipline measurably decay within weeks.
9. **Kill replaced layers explicitly.** Archive the doc, delete the command,
   re-date the runbook. Every stale artifact taxes trust in all the others.
10. **Autonomous agents are opt-in per issue**, gated by labels — never
    self-selecting.

### Three load-bearing culture rules

These appear throughout the doc and are non-negotiable:

- **The lightest evidence that proves the claim, and no lighter than that.**
- **Honest partial completion beats claimed completion.** "6 of ~10 orders
  placed; here is why the rest were not" is rewarded. Boxing a partial
  completion is a cardinal sin.
- **Required conversation resolution is the single highest-leverage
  setting in the whole system.** It turns every reviewer finding (bot or
  human) into a merge blocker until explicitly dispositioned.

### What the evidence says EDD costs vs. what it buys

EDD §0 is explicit that this is **not theoretical**: it has been audited
in sustained production use — a corpus of over a thousand PRs with a
near-total merge rate and **zero reverts**, hundreds of work packets, and
a multi-bot review layer. The cost is process overhead on every issue (packet,
digest loop, gates, weekly automations). The payback is resumable work,
auditable claims, honest client status, and onboarding cost that drops
because the packet corpus is a queryable case history.

---

## 2. Work Packet Format — `Target / Change / Acceptance`

### 2.1 The packet skeleton (verbatim from `docs/delivery/work/000-packet-template.md`)

```markdown
# Work Packet: <title>

**Issue:** [ACME-123](https://linear.app/<org>/issue/ACME-123)
**Slug:** ACME-123
**Created:** YYYY-MM-DD
**Meeting reference:** [YYYY-MM-DD-…](../../meetings/transcripts/….md)   (if meeting-derived)

## Goal
(1 paragraph: what this accomplishes and for whom)

## Non-goals
- Explicit exclusions. Protects scope and makes honest completion possible.
  (e.g. "Production order placement — this packet is staging-only." /
  "Declaring the ERP handoff complete without vendor confirmation.")

## Constraints / Invariants
- (pre-seeded house rules: data preservation, coverage floor, coding
  standards, environment safety rails, "do not deploy as part of this packet"
  when applicable)

## Design reference   (MANDATORY for any user-visible surface)
- Link /DESIGN.md and NAME the tokens used, or state "Pure backend — N/A".

## Rollout / Feature Flag Plan
- Flag name (convention: FEATURE_<KEY>_ENABLED), default OFF, verification
  steps, rollback plan.

## Checklist (ONE item = ONE PR)
- [ ] M1: …
- [ ] M2: …
  (each independently mergeable without breaking the default branch)

## Commands
(build / test / validate / deploy commands for this repo, copy-pasteable)

## Acceptance Criteria

### Behavior (Gherkin — the testable contract)
```gherkin
Feature: <capability this packet delivers>

  Scenario: <primary happy path>
    Given <precondition, in business terms>
    When <action>
    Then <observable outcome, with concrete values>

  Scenario: <key edge case or failure mode>
    Given <…>
    When <…>
    Then <…>
```

### Process gates
- [ ] Every Gherkin scenario maps to an automated test (name the files) or a
      recorded manual verification in evidence/
- [ ] Existing documentation touched by this change is updated
- [ ] New documentation written where needed — maintainer-facing (how it
      works, for future maintenance) and user-facing (how to use the change)
- [ ] Architectural decisions recorded as ADRs in docs/decisions/ — or
      "none made"
- [ ] PR feedback digest shows 0 unresolved review threads
- [ ] Deploy/verification evidence recorded in this packet

## Working Log
(append-only, newest last — every working session adds an entry; see §5.4)
- YYYY-MM-DD HH:MM — DONE: <what was completed, with commit SHAs / PR #s>
  · NEXT: <the next concrete step> · BLOCKERS / OPEN QUESTIONS: <or "none">

## PR Loop Status / Evidence
(appended as work proceeds — PR numbers, merge SHAs, deploy activity IDs,
smoke results, dated investigation notes)

## Links
- Issue, ADRs, architecture docs, related packets
```

### 2.2 Gherkin acceptance is non-negotiable

EDD §5.3 makes the case plainly: *"An agent can always claim to
satisfy 'feature works as described in Goal'; it cannot fake 'Given a
logged-in B2B buyer with contract pricing, When they add SKU A-100 to the
cart, Then the line shows the contract price of $464.40, not the raw $0.08
unit price.'"*

Discipline:
- Business language (not implementation speak).
- Concrete values, not adjectives.
- One behavior per scenario.
- At least one failure-mode scenario per packet.
- Each PR names the scenarios its checklist item satisfies.

A packet without concrete Gherkin scenarios is not ready to start. A
historical note: an earlier template migration silently dropped the Gherkin
block; nobody decided that, and the unfalsifiable "feature works as
described in Goal" reappeared. Template regressions are themselves a
failure mode — diff old vs. new section-by-section like code.

### 2.3 The Working Log is the resume rule (the "another agent picks it up cold" contract)

Agents write to the packet *while* they work, not just at the end. Every
working session appends Working Log entries: what was just completed (with
commit SHAs and PR numbers), what the next concrete step is, and any open
questions or blockers. Investigation findings, dead ends, and relevant
environment state (branch names, feature-flag settings, test data created)
belong here too.

The test is **resumability**: if an agent fails or is interrupted mid-task,
another agent must be able to pick the packet up cold — with no access to
the prior session's conversation — and finish the work from the packet, the
Working Log, and the git history alone. A packet that would strand its
successor is an incomplete packet; the weekly evidence gate treats a merged
PR whose packet has no Working Log entries as a finding.

### 2.4 The packet directory contract (verbatim shape)

From `docs/delivery/work/README.md`:

```
docs/delivery/work/<KEY>/
  packet.md                    # the contract: goal, non-goals, checklist,
                               # Gherkin acceptance, Working Log, evidence log
  design.md                    # multi-PR features only: data flow, interfaces,
                               # file-level plan, ADRs relied on/created —
                               # human-reviewed before M1 starts
  evidence/                    # dated notes, screenshots, harness scripts
  prs/
    pr-<N>/feedback/           # archived review digests for PR #N
      latest.md                # current digest (human-readable)
      latest.json              # unresolved_count — what the merge gate reads
      <timestamp>-<sha>.md/.json   # append-only snapshots
```

The `evidence/` directory holds dated markdown notes with **searchable IDs**
(real order/record/transaction IDs, with an explicit "these are real
records, not synthetic payloads" statement when it matters); numbered
screenshots with a README; **the harness scripts that generated the
screenshots** (reproducibility beats trust); dry-run CSV/log artifacts for
any data repair (a reviewed dry-run artifact is a P0 requirement before
production data scripts run). For UI work, include the agent's
browser-driven self-verification captures.

### 2.5 The packet generator (from `scripts/delivery/new_work_packet.py` docstring)

```bash
python3 scripts/delivery/new_work_packet.py --issue ACME-123 --title "..." \
  [--meeting docs/meetings/transcripts/....md] [--create-branch]
```

Creates `docs/delivery/work/ACME-123/{packet.md,prs/}`, validates and
embeds the meeting link, records the active slug for the digest tool. Keep
the `--meeting` flag from day one (only 24 of 401 packets got meeting
links when it was added late — the historical lesson).

**Author packets by interview.** The agent drafts the packet by
interviewing the user (and reading the meeting record or discovery source);
the user's review of the drafted packet is the phase gate before
implementation starts. Spec precision pays off more than supervising
implementation.

### 2.6 The packet lifecycle (verbatim short list)

- **Capture-only** packets (issue captured, nothing implemented yet) are
  expected — that is intake working. But they accumulate (~58% in one
  audit). Daily triage (§4) classifies them and starts the smallest safe
  item or closes as duplicate/stale.
- **Execute each checklist item in a fresh agent session.** The packet is
  the contract, not the chat history.
- **Packets are spec-anchored.** When implementation legitimately diverges,
  update the packet in the same PR; a packet that no longer matches the
  code fails the weekly evidence gate.
- **Honest partial completion** is rewarded: leave the box unticked and
  write down exactly what was and wasn't proven.

### 2.7 Verbatim example packet (anonymized from a real spike packet, lightly trimmed)

The following is a real, fully-populated packet that was used to deliver a
pipeline integration spike. It demonstrates the full skeleton including
Goal/Non-goals, Constraints, Design reference, Rollout, multi-item
checklist, Gherkin acceptance, and Working Log. All client names and
endpoints have been replaced with `ACME-###` / `client-x` / generic
platform sandbox placeholders.

```markdown
# Work Packet: <vendor> to <platform> integration spike

- Linear: [ACME-7](https://linear.app/<org>/issue/ACME-7)
- Milestone: M1 Discovery & Architecture Sign-Off
- Related Linear: ACME-27 (access, done), ACME-18 (platform access, in review),
  ACME-4 (storefront repo decision, blocks storefront validation only)
- Branch: `work/ACME-7/m1`

## Goal

Prove the <vendor>-to-<platform> path end-to-end for one representative
product family, and build it as the permanent foundation of the catalog
pipeline rather than throwaway spike code:

1. Pull the <vendor> connector payload, normalize category-expanded rows to
   canonical products/variants keyed by `product_id`/`variant_id`.
2. Run the pre-import data-quality gate (implementable subset of the
   2026-07-03 gate spec) and produce run artifacts.
3. Transform canonical entities to <platform> payloads per the product
   model v2 and the 2026-06-30 attribute-mapping draft.
4. Import the representative family into the platform sandbox (dry-run
   first, then live sandbox import).
5. Give the client an operational view: every platform action invocation
   and sync run is tracked, failures land in an error queue with
   retry/resolve, and a monitoring web UI (platform SPA, not storefront
   code) shows status.

Provisional representative family: `product_id=516` "Cube Architectural 6\"
Pendant" (FUNCTIONAL attribute set, 144 variants — at the F3 configurable
boundary, which is exactly what the spike should stress). To be confirmed
with the client on the 2026-07-10 call; the family is a runtime parameter,
not a constant.

## Non-goals

- No storefront code or storefront deploys (blocked on ACME-4; excluded by the maintainer's instruction).
- No production platform resources — **sandbox only**.
- No full-catalog import; spike scope is one family plus the gate running
  against the full payload statistics.
- No auto-fixing of data problems — the gate reports/blocks, never
  mutates.
- No client-facing alerting; monitoring UI is pull-based.
- No secrets in git: no vendor keys, platform OAuth credentials, tokens,
  or workspace config JSON.

## Constraints / Invariants

- Vendor secret comes from `<VENDOR>_PRIVATE_KEY` env var; never from a
  file.
- Platform credentials flow through the platform CLI login / `.env`
  (gitignored); `.env.example` documents names only.
- SKU strategy: `sku = normalize(external_id)` with collision **report+block,
  never auto-suffix** (red-team F1). External identity attributes are
  immutable.
- Zero-variant identities are default-deny `parked` (F5) — never imported.
- Runtime actions must not assume they can hold the full payload; fetch
  is paginated and sync scope is family-filtered.
- The platform's serverless state/file stores hold small run/queue state and artifacts only —
  not catalog data.
- Deploys target Stage workspace only and are run interactively; check
  current org before every deploy.

## Design Reference

`docs/delivery/work/ACME-7/design.md` — pipeline architecture, run/queue
state model, monitoring UI, native-features-first assessment, deployment
and verification plan. Monitoring/error-queue architecture decision is
captured as a separate ADR.

## Rollout / Feature Flag Plan

- All import writes default to `mode=dry-run`; `mode=import` must be passed
  explicitly per invocation.
- The sync action requires an explicit `family` parameter; there is no
  "sync everything" default.
- Stage-workspace-only manifest; no production workspace config exists.

## Checklist

One checklist item = one PR, in order:

- [x] 1. Project scaffold: runtime manifest, package/test/lint tooling,
  env documentation, `.env.example`, AGENTS.md environment map fill.
- [x] 2. Vendor client + normalizer: signed-URL client, category-expanded
  → canonical normalization, synthetic fixtures mirroring the real schema.
- [x] 3. Attribute transform layer: mapping table as data, value
  normalizers, canonical → platform payload builder.
- [x] 4. Data-quality gate: checks A1–A7, B1, B4, C4, D1, D2, D4 with the
  severity ladder, run-artifact writer, baseline comparison.
- [x] 5. Run tracking + error queue: wrapper, state-backed run registry,
  general error queue, status web actions for the UI.
- [x] 6. Sync orchestrator + platform client: fetch→normalize→gate→
  transform→import pipeline, IMS-authenticated REST client, dry-run and
  import modes, reconciliation checks after import.
- [x] 7. Monitoring UI: SPA — sync runs list/detail with gate verdicts,
  error queue with retry/resolve, action health view.
- [x] 8. Stage deploy + sandbox verification COMPLETE: dry-run + LIVE
  import of family 516, and browser-verified in platform Admin AND the
  monitoring UI same day (screenshots in `evidence/`).

## Commands

```bash
npm ci                 # install
npm test               # claiming-done gate (jest)
npm run lint           # eslint
npm run build          # platform build (bundles actions + web assets)
# Deploy is maintainer-run, after platform login + org/project/workspace
# switch; see docs/deployment.md
```

## Acceptance Criteria

### Behavior

```gherkin
Scenario: Category-expanded rows collapse to canonical identities
  Given a vendor payload where product 516 appears in 3 category rows
  When the normalizer processes the payload
  Then exactly one canonical product 516 exists
  And its categories field aggregates all 3 category references
  And every variant keeps its variant_id and product_id linkage

Scenario: Quality gate blocks on identity violations
  Given a payload containing two variants with the same variant_id
  When the pre-import gate runs
  Then check A1 reports BLOCK with the duplicate ids listed
  And the run verdict is "blocked"
  And no platform write is attempted

Scenario: external_id collision is reported, never auto-fixed
  Given two variant identities sharing external_id "WS-58-BK"
  When the pre-import gate runs
  Then check A4 lists the collision with both variant_ids
  And no suffixed SKU is generated anywhere in the run

Scenario: Transform produces model-v2 platform payloads
  Given canonical family 516 with 144 variants and attribute_set FUNCTIONAL
  When the transform runs
  Then one configurable parent payload carries vendor_product_id "516"
  And 144 simple payloads carry immutable vendor_variant_id values
  And each simple SKU equals its normalized external_id
  And zprice_list values "Yes", "YES" and "Y" all normalize to true

Scenario: Dry-run import writes nothing
  Given a passing gate run for family 516
  When the sync action runs with mode "dry-run"
  Then the platform client performs zero write calls
  And the run record contains the would-be payload counts

Scenario: Sandbox import is verifiable in platform Admin
  Given a passing gate run and mode "import"
  When the sync action completes against the platform sandbox
  Then reconciliation check E1 confirms parent+simple counts in platform
  And the products are visible in the sandbox platform Admin catalog grid

Scenario: Failed action lands in the error queue with retry
  Given the platform API returns HTTP 500 during an import batch
  When the sync action records the failure
  Then an error-queue entry exists with action, run id, phase and message
  And the monitoring UI lists it as "open"
  And invoking retry re-runs only the failed phase idempotently

Scenario: Operators can see sync status without console admin
  Given at least one completed and one failed sync run
  When a user opens the monitoring UI
  Then they see each run's verdict, counts, duration and gate summary
  And they see per-action last-invocation health
  And they did not need elevated admin permissions to view it
```

### Process Gates

- [x] Every scenario above maps to at least one automated test (jest;
  vendor/platform clients mocked with fixtures). Evidence: 58 tests / 8
  suites green on 2026-07-07, incl. one test per Gherkin scenario except
  the two sandbox-only scenarios (item 8).
- [ ] CI green on the PR(s); 0 unresolved review threads.
- [x] No secrets committed (verified at every commit; run records and
  error entries strip secret-shaped keys via `redact()` with tests).
- [x] Deploy/verification evidence recorded in this packet (item 8;
  admin screenshot pending).
- [ ] Spike results referenced into the M1 ADR (ACME-11) after sandbox
  verification.

## Working Log

- 2026-07-07: Packet created on branch `work/ACME-7/m1`. Design inputs
  distilled from: attribute-mapping draft, product model v2, data-quality
  gate spec, integration architecture spec. Provisional spike family:
  product_id 516.
- 2026-07-07: Deployment constraint recorded: local CLI is authenticated
  to a different client org and interactive login is impossible in the
  automation shell. All Stage deploys are maintainer-run.
- 2026-07-07: Items 1–7 implemented on `work/ACME-7/m1`. Verification:
  `npx jest` → 58 passed / 58, 8 suites; `npm run lint` → exit 0; platform
  build → succeeded offline.
- 2026-07-07 (later): Item 8 executed end-to-end. Sequence and evidence:
  - The maintainer completed interactive platform login; org visible in the
    console.
  - Platform developer terms for the org were UNACCEPTED initially;
    The maintainer authorized acceptance.
  - Platform CLI `app use --merge --no-input --global` imported Stage
    credentials to gitignored `.env`.
  - REST contract verified live: base URL, /V1 paths, required headers,
    s2s token with the correct scope → 200.
  - Deployed to Stage twice (initial + tuned build): UI URL, 5 web
    actions under `/api/v1/web/<app>/`.
  - Live-data fixes discovered by running against the real feed: connector
    is stateful (delta by default; last_update=1 for full pull), rows are
    positional arrays with data_schema only on page 1, variant rows are
    category-expanded (66,856 → 37,712), platform requires numeric
    attribute_set_id. 61/61 tests green after fixes.
  - Full pull reconciles EXACTLY with the prior audit: 4,385 canonical
    products / 37,712 variants / 374 categories / 407 zero-variant / 236
    external_id collisions (A4 advisory evidence for the SKU decision).
  - Runs: `202607071810-516-dry-run` (verdict warn, 145 would-write, 0
    writes), `202607071848-516-import` (200, 145/145 written, E1 145/145
    found). Gate artifacts under
    `docs/delivery/work/ACME-7/evidence/quality-runs/`.
- 2026-07-07 (evening): Visual verification completed via driven Chrome
  with the maintainer's interactive login:
  - Platform Admin Products grid: **146 records** (145 imported + disabled
    probe). Screenshots: `evidence/admin-products-grid-146.png`,
    `evidence/admin-parent-page.png`.
  - Monitoring UI: Sync runs tab renders the full run history (completed /
    partial / failed / blocked with gate verdicts and phase chains);
    Action health tab live. Screenshots: `evidence/monitor-ui-sync-runs.png`,
    `monitor-ui-error-queue.png`, `monitor-ui-action-health.png`.
- Next: reference spike results in the M1 ADR (ACME-11); client decisions
  on family confirmation, SKU/external_id strategy, etc.
- Blockers: none. Configurable option linking (parent↔simples) needs the
  finish/cct attributes + client attribute sets provisioned in platform —
  an M2 task per product model v2; the spike imports parent + simples
  unlinked.

## Links

- Issue: ACME-7
- Related Linear: ACME-27, ACME-18, ACME-4
```

---

## 3. Merge / Quality Gates (the "no claim without a receipt" machinery)

### 3.1 The Evidence Ladder (verbatim from EDD §8.1)

> "Use the lightest evidence that proves the claim. Do not overstate a result."

| Claim | Minimum evidence |
| --- | --- |
| Code is review-ready | Focused tests run locally, lint/syntax pass, packet updated, PR link |
| PR is merge-ready | Checks green **plus** digest showing 0 unresolved threads |
| Issue is **done** | Tests mapped to every Gherkin scenario · existing docs updated · new maintainer- and user-facing docs written (or explicitly N/A) · ADRs recorded for any architectural decision · deploy/verification evidence in the packet |
| Staging is deployed | Deploy ID/commit, staging outcome-smoke result, packet note |
| Production is deployed | Commit/activity, smoke result, **rollback path**, packet note |
| Serverless/integration deployed | Workspace/namespace, action/function versions before+after, proof live params/config were preserved |
| Launch readiness changed | Readiness packet, environment evidence, blocker disposition |
| Incident is repaired | Incident note: impact, root cause, fix, verification, follow-up issues |
| External system is confirmed | Searchable IDs + confirmation source; **if external confirmation is missing, say so** |

That last row is the culture in one line: one exemplary packet recorded
six real orders with downstream system record IDs and then explicitly
declined to claim the downstream vendor leg because only the vendor could
confirm it.

### 3.2 The outcome-asserting QA kit (verbatim from EDD §8.2)

Three gates. Founding rule: **"assert business outcomes (amounts, counts,
byte sizes) — never just HTTP statuses or 'command succeeded'."** Every
client-found bug this kit was built from had been invisible to status-only
checks.

- **Gate A — after every prod→staging data sync** (flush caches first so
  cached state can't mask DB damage): data-integrity checks (roles/ACLs
  intact, no dangling references) + a **credential inventory**: every
  credential the scrub touches has an explicit staging expectation — sandbox
  replacement present, live mode off, or a documented disable.
- **Gate B — after every staging deploy and within 24h of inviting the
  client to test**: business-outcome smoke (page byte-size floors,
  product/price counts, search result counts, tax within an expected band,
  an integration round-trip returning real records) + log scan + build-patch
  skip count. Exit 1 means literally: **"do not invite the client to test."**
- **Gate C — before client handoff**: staging-vs-production parity diff;
  intentional differences are documented in the config file as comments, not
  silently tolerated.
- **Log-allowlist governance**: recurring error signatures fail the gate
  unless allowlisted; **every allowlist entry carries a comment and an issue
  key**; entries are *removed* when fixed, with a dated verification note —
  so suppressed errors can't quietly become permanent.

### 3.3 The merge gate (structural, not aspirational)

EDD §6.3 — the agent-side gate and the platform-side gate in
table form:

| Setting | Value | Why |
| --- | --- | --- |
| Required status checks (strict) | `CI Complete`, `AI Review Complete` (two synthetic aggregator jobs) | Aggregators let you evolve inner jobs without re-editing protection |
| **Required conversation resolution** | **ON** | The single highest-leverage setting in the whole system — it turns every reviewer finding (bot or human) into a merge blocker until explicitly dispositioned |
| Required approvals | 0 (bots comment, they don't approve) | The human/agent merges; gating lives in checks + threads |
| Enforce for admins | Optional OFF | Allows an escape hatch — but **every bypass gets a written rationale comment on the PR** |

Agent-side, `verify_merge_ready.py` is the pre-merge command: all check-runs
green → live GraphQL unresolved-thread count == 0 (**an API failure blocks
— fail closed**) → digest archived in the packet → packet exists. Two soft
spots the audits exposed — correct them when setting up: a *missing*
archived digest was non-blocking (make it blocking), and the bot-wait job
"proceeded anyway" after a 15-minute timeout (decide explicitly whether
absent-bot means block or proceed-with-note).

The exact checklist the agent command reads (verbatim from
`.claude/commands/merge-ready.md`):

| Check | Blocking? | Meaning |
| --- | --- | --- |
| CI Checks | yes | every check-run on the PR head is green |
| Review Threads (live) | yes — **an API failure also blocks (fails closed)** | GraphQL unresolved count == 0 |
| Feedback Digest (archived) | yes — **a missing digest blocks** | `latest.json` in the packet shows `unresolved_count == 0` |
| Work Packet | warn-only | `docs/delivery/work/<KEY>/packet.md` exists |

### 3.4 The feedback digest loop (the "reply-then-resolve" discipline)

EDD §6.2 — `pr_feedback_digest.py` runs after opening a PR and
after every review wave. It pulls PR metadata, all review threads
(`isResolved`, path, line, comments), and conversation comments via the
GitHub GraphQL API. It writes an append-only timestamped snapshot plus
`latest.md`/`latest.json` under `work/<KEY>/prs/pr-<N>/feedback/`. It flags
comments that are **NEW since the previous run**, so each loop iteration
triages only what changed. It emits `unresolved_count` — the number the
merge gate reads.

**Reply-then-resolve discipline:** every review thread gets a substantive
reply citing the fixing commit ("Fixed in `e02fc1df6`: …") *before* it is
resolved. Never click-resolve silently. In the most recent audit, 108 of
108 owner inline comments were such replies — the result is an audit trail
of review outcomes that human teams almost never produce.

Budget for the loop: active review bots re-review every push and typically
add about one new finding per cycle. Plan 2–4 iterations, not 1.

### 3.5 The review system (one rulebook, many reviewers)

EDD §7.1 — `.github/BUGBOT.md` is the shared rulebook every
reviewer follows. Keep the filename — Cursor Bugbot reads it:

- **P0 — must fix before merge**: security (injection, XSS, exposed
  secrets, missing CSRF, unsafe uploads); data integrity (unsafe
  migrations, data-loss operations, **production data-repair scripts
  without a default dry-run mode and a reviewed dry-run artifact
  committed to the packet's `evidence/`**); platform-fatal patterns;
  breaking changes without a migration path.
- **P1 — should fix**: testing gaps below the coverage floor, N+1
  queries, unbounded retries, resource leaks, framework anti-patterns,
  deprecation timebombs.
- **P2 — nice to fix**: readability, naming, docs, dead code.
- **Noise control** (verbatim spirit): "Do NOT nitpick formatting —
  linters own that. Be specific: location, why it matters, suggested
  fix." Every comment starts with its severity tag. PR-size table posts
  a warning >500 lines.

**The rulebook is production code.** Keep a golden corpus of past real
bugs (a reversed-argument fatal, an address-book data-loss bug, a
PCI-logging catch, a CSRF-breaks-the-feature miss) and re-run the review
layer against it whenever the rulebook or the reviewer setup changes.
Track the **author-addressed rate** per reviewer and prune rules that
generate ignored comments.

### 3.6 The three-layer reviewer ensemble

EDD §7.2:

1. **Deterministic gate** (blocking, no LLM): a CI job that greps the diff
   for the mechanically checkable P0s and exits 1. Cheap, instant, immune
   to bot quota. This is the floor.
2. **Two independent LLM reviewers** (e.g. Cursor Bugbot + one other).
   Independence matters: in one audit, two bots independently flagged the
   same production data-loss bug (a partial account list treated as
   authoritative, which would have dropped live records) — exactly the
   redundancy you want when the *author* is also an AI.
3. **Humans by exception**: architecture, client-sensitive changes,
   anything irreversible. Production use has sustained **zero human
   inline comments** over long stretches while holding quality via the
   ensemble + gates — decide per project whether that is acceptable, and
   write the decision down.

### 3.7 CI that means something

EDD §7.3:

- Hard-fail the few checks you trust: typecheck, unit tests, lint on
  changed code, build, bundle budget where relevant.
- If a check must start advisory, annotate it with the tightening date
  and review monthly. "Start as warning, tighten later" with no date has
  cost six months of meaningless green before.
- Aggregate inner jobs into the synthetic `CI Complete` /
  `AI Review Complete` statuses that branch protection requires.

### 3.8 The Definition of Shippable (content-side analog)

`Content Engine/Definition of Shippable.md` is the **content-side**
acceptance gate — the "self-audit rubric" a cheap model applies to every
drafted artifact before it reaches the human edit-and-approve block. The
point is to protect throughput without spending the human's judgment on
things that aren't ready.

The seven gates:

1. **Size bar met** — blocker. Normal week ≥ M; collapse week ≥ S.
2. **Substance** — blocker. Real artifact, number, story, or insight the
   reader couldn't get elsewhere. Fails if it's throat-clearing,
   motivational filler, or a take with no evidence behind it.
3. **Pillar + audience fit** — concern. Maps cleanly to one of the 4
   pillars and names its audience + primary channel.
4. **Voice** — blocker. Passes the `josh-voice` skill and the
   deterministic `voice-lint` script. Cite the lint result as the
   receipt. AI-tell phrasing, hype, or emoji → HOLD.
5. **Safety / not-to-do** — blocker. No client specifics unless
   anonymized or explicitly approved; no engagement-bait or growth-hack
   format; no secrets/tokens/internal IPs.
6. **Truthfulness** — blocker. Every number and claim is real and
   traceable to a committed artifact or a cited source — never fabricated
   or rounded-up. Benchmark numbers must match the actual results.
7. **Publish-readiness** — concern. Title/hook, one clear CTA or a
   deliberate none, links resolve, repurposing derivatives noted.

Verdict block the model appends:

```
VERDICT: SHIP | HOLD
Size: S/M/L/XL (bar for the week: __)   Pillar: __   Audience: A/B/both
Gates: 1✓ 2✓ 3✓ 4✓ 5✓ 6✓ 7✓   (mark any fail)
voice-lint: pass/fail (receipt: <path or output>)
If HOLD → the single blocking fix: ____
Repurposing derivatives (L/XL): ____
```

The content side and the delivery side share the same meta-rule: the
cheapest model that can self-verify, then the human's judgment only on
things that already cleared the gate.

### 3.9 The binding workflow paragraph (paste into every project's `AGENTS.md`)

```markdown
## Delivery workflow (binding)

This project follows Evidence-Driven Delivery: read
`docs/delivery/EVIDENCE-DRIVEN-DELIVERY.md` before starting any work.
Non-negotiable rules: no work without a Linear issue AND a work packet
(`docs/delivery/work/<KEY>/packet.md`) created first. Keep the packet's
Working Log current while you work (done / next / blockers) so another
agent can resume your task cold. One checklist item = one PR. Acceptance
criteria are Gherkin scenarios, and every scenario must map to a test.
Record architectural decisions as ADRs in `docs/decisions/`. After reviews,
run the feedback digest and merge only with CI green and 0 unresolved
review threads. A task is NOT done until tests cover it, existing
documentation is updated, and any needed new maintainer- and user-facing
documentation is written. Record deploy/verification evidence in the
packet, and never overstate a result. If this file and the EDD document
conflict, the EDD document wins — then fix this file.
```

### 3.10 The agent command that runs the gate (verbatim from `.claude/commands/merge-ready.md`)

```bash
# Auto-detect from the current branch
python3 scripts/delivery/verify_merge_ready.py

# Or explicitly
python3 scripts/delivery/verify_merge_ready.py --slug ACME-### --pr <N>
```

**Exit 0 — ready:** confirm the packet checklist item for this PR is
complete and the Working Log has a closing entry (merge SHA once merged);
merge on GitHub; the Linear magic word in the PR body moves the issue
automatically.

**Exit 1 — not ready:** report exactly which checks block and why. Do not
merge. If a bypass is genuinely warranted (e.g. the reviewer bot died in
setup twice), it requires a **written rationale comment on the PR** — and
keep the break-glass rate under 1%.

---

## 4. Directory / File Conventions

### 4.1 The repo scaffolding (EDD §3, verbatim)

```
docs/
  delivery/
    EVIDENCE-DRIVEN-DELIVERY.md  # this document — the hub
    CURRENT-STATE.md             # rolling weekly distillation (one page)
    work/
      README.md
      <KEY>/packet.md            # one dir per Linear issue that reaches work
      <KEY>/evidence/            # dated notes, screenshots, harness scripts
      <KEY>/prs/pr-<N>/feedback/ # archived review digests (latest.md/.json + snapshots)
    evidence-reviews/            # dated weekly audit notes
    templates/
      weekly-distillation-template.md
      evidence-review-gate-template.md
      launch-readiness-packet-template.md
      incident-deploy-note-template.md
      consulting-strategy-note-template.md
  decisions/                     # ADRs — ADR-NNNN-<slug>.md (§7.5)
  meetings/
    MEETING_GUIDELINES.md  MEETING_TEMPLATE.md  MEETING_INDEX.md
    transcripts/YYYY-MM-DD-HH-MM-<type>-<topic>.md
scripts/
  delivery/
    new_work_packet.py           # generator: packet dir + template + active-slug file
    pr_feedback_digest.py        # GraphQL review-thread digest → packet archive
    verify_merge_ready.py        # the agent-side merge gate
  qa/                            # outcome-asserting QA kit (§8.2)
.github/
  PULL_REQUEST_TEMPLATE.md
  BUGBOT.md                      # the review rulebook (Cursor Bugbot reads this name)
  workflows/ci.yml               # few, HARD checks
  workflows/ai-review.yml        # deterministic P0 grep gate + bot orchestration
.claude/commands/                # the loop encoded as reusable agent commands:
  new-work.md  pr-feedback.md  merge-ready.md  review.md
  deploy-staging.md  deploy-production.md
AGENTS.md / CLAUDE.md            # the project constitution (§7.4)
DESIGN.md                        # single source of truth for visual tokens (if UI work)
```

### 4.2 Packet directory contract (re-stated for emphasis)

`docs/delivery/work/<KEY>/` is the unit of work. Inside each:

- `packet.md` — the contract (Goal, Non-goals, Checklist, Gherkin
  acceptance, Working Log, evidence log, PR Loop Status, Links).
- `design.md` — multi-PR features only. Data flow, interfaces, file-level
  plan, ADRs relied on or created. Human-reviewed before M1 starts.
- `evidence/` — dated notes, screenshots, harness scripts.
- `prs/pr-<N>/feedback/` — archived review digests. `latest.md` for
  humans, `latest.json` for machines (carries `unresolved_count`).

### 4.3 Five delivery templates (all in `docs/delivery/templates/`)

Production implementations keep these five in lockstep. They are the templates
the weekly automations consume; the digest includes their full skeletons in
§5 below.

- `weekly-distillation-template.md` — rewrites `CURRENT-STATE.md` after
  the client status meeting.
- `evidence-review-gate-template.md` — audits the week's merged work
  against the checklist; saved to `evidence-reviews/`.
- `launch-readiness-packet-template.md` — per go/no-go decision; watch
  items separated from blockers separated from post-launch work.
- `incident-deploy-note-template.md` — one template for anything that
  touches live data or produces a customer-visible incident.
- `consulting-strategy-note-template.md` — strategy discussions captured
  as a decision note, not code.

### 4.4 The 6 agent commands (in `.claude/commands/`)

Each command is a small, self-contained prompt that operationalizes one
section of the canonical doc. Verbatim names (from
`.claude/commands/`):

| Command | Implements | What it does |
| --- | --- | --- |
| `new-work.md` | EDD §5.1–5.3 | Verifies the issue, generates the packet (Gherkin skeleton included), authors it by interview, and gates on concrete scenarios before implementation starts |
| `pr-feedback.md` | EDD §6.2 | Runs the feedback digest, triages NEW comments, reply-then-resolves every thread, loops until 0 unresolved |
| `merge-ready.md` | EDD §6.3 | Runs `verify_merge_ready.py`; merges if it passes or reports exactly what blocks |
| `review.md` | EDD §6.4 | Fresh-context reviewer pass over a PR/diff against `.github/BUGBOT.md`; correctness-affecting findings only — no nitpicks |
| `deploy-staging.md` | EDD §8.1 (Staging row) + §8.2 Gate B | Deploys to staging and records the Evidence-Ladder proof (deploy ID/commit + outcome smoke result) in the work packet |
| `deploy-production.md` | EDD §8.1 (Production row) | Deploys to production with staging evidence, recorded commit/activity, smoke result, and the ROLLBACK PATH in the work packet |

### 4.5 Three scripts in `scripts/delivery/`

| Script | Role |
| --- | --- |
| `new_work_packet.py` | Generator: validates issue key, creates `docs/delivery/work/<KEY>/{packet.md,prs/}`, seeds the full §5.2 skeleton (Gherkin + Working Log + gates), validates `--meeting` links, records the active slug, optional `--create-branch` |
| `pr_feedback_digest.py` | Review-thread digest via GitHub GraphQL: timestamped snapshots + `latest.md`/`latest.json`, `unresolved_count`, NEW-comment flagging against the previous run |
| `verify_merge_ready.py` | Pre-merge gate: checks green + live unresolved-threads == 0 (fails closed on API error) + archived digest (blocking) + packet exists |

`_utils.py` is the small shared module (git_root, run, sanitize_slug,
validate_issue_key — `^[A-Z][A-Z0-9]*-\d+$`, get/set_active_slug,
branch_exists, get_pr_number_from_branch, detect_repo_owner_name).

### 4.6 ADR conventions (EDD §7.5)

- **Format** — one file per decision, `ADR-NNNN-<slug>.md`: Status
  (proposed / accepted / superseded by ADR-NNNN) · Context (the forces and
  constraints) · Decision (what we chose, in full sentences) · Alternatives
  considered (and why rejected) · Consequences (what gets easier, what
  gets harder, follow-up work).
- **Immutability** — accepted ADRs are never edited into a different
  decision; a new ADR supersedes the old one and links back.
- **When** — the ADR is written in the same PR as (or before) the change
  that implements it, and reviewed like code.
- **Relationship to meeting decisions** — meeting notes own business and
  project decisions; ADRs own technical/architectural ones. When a meeting
  makes an architectural call, the action item is "write the ADR."
- **Bootstrap** — `ADR-0001` records the adoption of EDD itself.

### 4.7 The minimum contents of `AGENTS.md` / `CLAUDE.md`

1. The binding workflow paragraph (verbatim in §3.9), pointing at the
   canonical doc.
2. Environment map + safety rails — which environments exist, which one
   is real, what must never be run where, and how deploys happen.
3. The commands — build, test, lint/validate, and deploy commands an
   agent needs to verify its own work.
4. Stack-specific hazards — the incident-derived P0 list, or a pointer
   to `.github/BUGBOT.md` as the single source.
5. Pointers, not copies — links to `DESIGN.md`, `docs/decisions/`
   (ADRs), the work-packet directory, and per-subsystem SYSTEM/RUNBOOK
   docs.

Keep the constitution small. Context-window fill degrades agent
performance, and overlong instruction files get *ignored*, not skimmed.
Budget a few hundred lines; prune quarterly. Apply the rule: *"a rule
that keeps being violated becomes a hook or a CI gate, not a bolder
paragraph."* Safety rails in particular must be *enforced* in permissions,
environment isolation, and hooks — prompts and ALL-CAPS warnings
demonstrably do not stop a determined agent; infrastructure is the barrier.

### 4.8 The napkin / lessons file

Mature implementations carry a per-repo napkin at `.claude/napkin.md` (also
called the lessons file) — corrections and gotchas appended as they
happen, consolidated periodically. The format (from
`.claude/napkin.md`):

```
| Date | Source | What Went Wrong | What To Do Instead |
|------|--------|-----------------|--------------------|
| 2026-07-11 | self | <the mistake> | <the fix> |
```

Where `Source` is `self` (the agent caught it) or `user` (the human
corrected it). The napkin is read at the start of every session; the
agent applies the rule silently (does not announce the read). This is
the same "scar tissue" pattern as the BUGBOT.md rulebook, but at the
individual-agent level rather than the review level.

### 4.9 The other repo-shape files referenced in scope

- **`THEORY.MD`** — the operating-theory narrative. Activated
  continuously, rewritten end-to-end (not appended) as understanding
  deepens, the "what we're doing and why" document. The canonical place
  to record durable strategic context.
- **`progress.md`** — running per-project progress log. The
  `repo-research-analyst`-style "scraped session history → synthesized
  progress file." The EDD doc itself says: *"the packet is the progress
  file, the Gherkin scenarios are the feature list, and commits
  reference the checklist item they advance"* — for the delivery
  layer, the packet subsumes the progress.md role at packet granularity.
- **`CHANGELOG.md`** — running log of every infra change
  (`homelab-infra:CHANGELOG.md`). Enforced via a PreToolUse hook that
  blocks `git commit` if `CHANGELOG.md` is not staged for infra
  changes. EDD does not require CHANGELOG for the delivery layer; the
  audit trail lives in the packet corpus and the Linear magic words.

### 4.10 Bootstrap checklist for a new project (EDD §11)

Day one (≈half a day with the starter kit):

- [ ] Linear: team, project(s), workflow states, labels (§10), estimates
      on, GitHub integration connected, magic-word automation verified
      with a test issue
- [ ] Linear agents: coding agent(s) installed as workspace members;
      **agent guidance** written (target repos, branch/PR conventions,
      review process)
- [ ] Run the EDD starter kit installer (Appendix A): repo scaffolding,
      delivery scripts, QA-kit skeleton, templates, PR template +
      rulebook, workflows, agent commands, and the `AGENTS.md`/`CLAUDE.md`
      starters
- [ ] Adapt the kit to the stack: project commands in `AGENTS.md`, hard
      checks in `ci.yml`, incident-derived P0 patterns in
      `.github/p0-patterns.txt`, QA-kit probes and configs
- [ ] Branch protection: required checks + **required conversation
      resolution**
- [ ] Review bots installed (two independent), pointed at the rulebook,
      and **both named in the reviewer gate** so each is required for
      merge — one passing reviewer must never satisfy the gate on its
      own
- [ ] `AGENTS.md` completed to the §7.4 minimum contents — including the
      binding workflow paragraph — and `DESIGN.md` if there's UI
- [ ] `docs/decisions/ADR-0001` (adoption of this methodology)
      reviewed and accepted
- [ ] Meeting template + index; first meeting note filed properly
- [ ] Requirements baseline session → MoSCoW doc → first tranche of
      issues + capture packets
- [ ] Schedule the three automations (daily triage, weekly
      distillation, weekly evidence gate) and create `CURRENT-STATE.md`
      from the template
- [ ] Verification harness: stop-hook wired to the packet's test
      command and a fresh-context verifier pass before PRs are marked
      ready

---

## 5. Verbatim Template Library (copy-paste ready)

The five delivery templates and one ADR template, in full. These are
copied verbatim from the canonical `docs/delivery/templates/` set and from
`docs/decisions/ADR-0000-template.md`.

### 5.1 Work packet template (reproduced in §2.1 above; this is the canonical packet skeleton)

### 5.2 Weekly distillation template

```markdown
# Weekly Distillation: YYYY-MM-DD

<!-- EVIDENCE-DRIVEN-DELIVERY.md section 9, automation #2. Rewrite
     docs/delivery/CURRENT-STATE.md from this template after the client
     status meeting. Post the client-safe portion as a Linear project
     update, with project health mirroring the verdict. Rule: the
     distillation REPORTS; it never implements. -->

**Source window:** YYYY-MM-DD through YYYY-MM-DD
**Prepared by:** [human/agent]
**Primary inputs:** [meeting note, tracker search, recent PRs, work packets, evidence dirs]

## Launch / Delivery Verdict

**Verdict:** [green | yellow | red]
**Rationale:** [One paragraph. Be concrete about blockers and evidence.]

## Top 3 Actions

| Priority | Action | Owner | Due / timing | Issue / packet |
| --- | --- | --- | --- | --- |
| 1 |  |  |  |  |
| 2 |  |  |  |  |
| 3 |  |  |  |  |

## Active Blockers

| Blocker | Severity | Owner | Next evidence needed | Issue / packet |
| --- | --- | --- | --- | --- |
|  |  |  |  |  |

## Recently Closed / Proved

| Item | Proof | Environment | Follow-up |
| --- | --- | --- | --- |
|  |  |  |  |

## Waiting On

| Waiting on | Owner / system | Needed by | Why it matters | Issue / packet |
| --- | --- | --- | --- | --- |
|  |  |  |  |  |

## Evidence Gaps

| Gap | Why it matters | Owner | Next review |
| --- | --- | --- | --- |
|  |  |  |  |

## Stale / Duplicate Cleanup Candidates

| Candidate | Reason | Proposed action |
| --- | --- | --- |
|  |  |  |

## Client Follow-Up Draft

[Short recap suitable for chat/email: what changed, what is blocked, what
is next, and what needs a decision.]

## Repo Updates Made

- Updated [`docs/delivery/CURRENT-STATE.md`](../CURRENT-STATE.md).
- Updated packet(s):
- Created evidence review note(s):
```

### 5.3 Evidence review gate template

```markdown
# Evidence Review Gate: YYYY-MM-DD

<!-- EVIDENCE-DRIVEN-DELIVERY.md section 9, automation #3: audit the
     week's merged work against claimed status. Save the dated note in
     docs/delivery/evidence-reviews/. The gate may add a small
     missing-evidence note to a packet; it must NOT fix implementation
     bugs. Required follow-ups are filed as issues. -->

**Review type:** [weekly | pre-launch | post-deploy | incident follow-up | issue-specific]
**Reviewer:** [human/agent]
**Source window:** YYYY-MM-DD through YYYY-MM-DD
**Scope:** [issues/PRs/launch packets reviewed]

## Verdict

**Overall:** [pass | pass with caveats | fail]
**Reason:** [One paragraph. State what evidence is missing if not pass.]

## Review Checklist

| Check | Result | Notes |
| --- | --- | --- |
| Every reviewed item has a Linear issue key and a packet | Unknown |  |
| Packet scope/non-goals still match the work | Unknown |  |
| One checklist item maps to one PR, or the exception is documented | Unknown |  |
| Working Log present and current (packets are running logs, section 5.4) | Unknown |  |
| Latest PR feedback digest archived with 0 unresolved review threads | Unknown |  |
| CI/check outcomes recorded for merged PRs | Unknown |  |
| Tests mapped to every Gherkin scenario | Unknown |  |
| Existing documentation updated; needed new maintainer/user docs written, or explicitly N/A | Unknown |  |
| ADRs recorded for architectural decisions, or "none made" | Unknown |  |
| Staging deploy evidence present or explicitly N/A | Unknown |  |
| Production deploy evidence present or explicitly N/A | Unknown |  |
| Serverless/integration deploy evidence (versions, config preserved) present or explicitly N/A | Unknown |  |
| External-system confirmation present or honestly caveated | Unknown |  |
| Follow-up issues exist for deferred or missing proof | Unknown |  |
| Tracker status aligns with repo evidence | Unknown |  |

## Packet / PR Evidence Matrix

| Issue | Packet | PR(s) | Digest | CI/checks | Working Log | Docs | ADRs | Staging evidence | Production evidence | Caveat / gap | Verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ACME-### |  |  |  |  |  |  |  |  |  |  |  |

## Launch / Incident Risk Notes

-

## Required Follow-Up

| Follow-up | Owner | Issue / packet | Due / timing |
| --- | --- | --- | --- |
|  |  |  |  |

## Client-Safe Summary

[Short summary of what is proven, what is not proven, and what happens next.]
```

### 5.4 Incident / deploy note template

```markdown
# Incident / Deploy Note: [Topic]

<!-- EVIDENCE-DRIVEN-DELIVERY.md section 8.3: one template for anything
     that touches live data or produces a customer-visible incident. The
     standard to hit: blast-radius scan (affected-record count),
     before/after values, and a post-repair scan showing 0 remaining. -->

**Issue:** [ACME-###](https://linear.app/<workspace>/issue/ACME-###)
**Date:** YYYY-MM-DD
**Environment:** [staging | production | serverless/integration]
**URL / action:** [URL, action/function name, or system]
**Severity:** [P0 | P1 | P2 | maintenance]

## Summary

[One paragraph: what happened, what was changed, and current state —
written so a non-engineer could be sent it verbatim.]

## Impact

- Customer/user impact:
- Data impact (blast radius — affected-record count):
- Integration impact:
- Time window:

## Timeline

| Time (with timezone) | Event | Evidence |
| --- | --- | --- |
| YYYY-MM-DD HH:MM TZ |  |  |

## Root Cause / Working Theory

[State the confirmed root cause. If not confirmed, say "working theory —
unconfirmed" and what would prove or disprove it.]

## Commands / Evidence

```bash
# Commands run - secrets redacted; mark each as READ-ONLY or DATA-WRITING.
```

Evidence files (in the packet's `evidence/`):

- [path]

## Data Writes / Deployment Changes

- Code commit / branch:
- Deploy ID / activity:
- Serverless/integration namespace/action/version (before + after):
- Data rows changed (before/after values; dry-run artifact path):
- Index/cache actions:
- Credential or runtime parameter changes: [none | describe safely]

## Verification

| Check | Result | Evidence |
| --- | --- | --- |
| Core-journey outcome smoke |  |  |
| Admin/API smoke |  |  |
| Integration round-trip |  |  |
| Post-repair scan (0 remaining affected records) |  |  |
| Regression test |  |  |

## Rollback

- Rollback path:
- Rollback owner:
- Conditions that trigger rollback:

## Follow-Up Issues

| Issue | Follow-up | Owner | Status |
| --- | --- | --- | --- |
|  |  |  |  |
```

### 5.5 Launch readiness packet template

```markdown
# Launch Readiness Packet: [Topic]

<!-- EVIDENCE-DRIVEN-DELIVERY.md section 8.4: "Launch readiness is a
     tracked state, not a feeling." One packet per go/no-go decision.
     Watch items are separated from blockers, which are separated from
     post-launch work. When dates slip, RE-DATE and rewrite this packet
     — never stack SUPERSEDED banners on a live runbook. -->

**Issue:** [ACME-###](https://linear.app/<workspace>/issue/ACME-###)
**Date:** YYYY-MM-DD
**Owner:** [Name]
**Environment(s):** [staging | production | DNS cutover | serverless/integration]

## Goal

[What decision this packet supports: pilot start, cutover, go/no-go,
launch date, blocker disposition, or launch-day process.]

## Current Verdict — Per-Area Status

<!-- ADAPT the area rows to this project's launch surface. -->

| Area | Status | Evidence | Owner | Next action |
| --- | --- | --- | --- | --- |
| Core user journeys (outcome smoke) | Unknown |  |  |  |
| Data/content readiness | Unknown |  |  |  |
| Payments / transactions | Unknown |  |  |  |
| Integration round-trips (ERP/CRM/etc.) | Unknown |  |  |  |
| Performance / capacity | Unknown |  |  |  |
| Customer communications | Unknown |  |  |  |
| Support / training | Unknown |  |  |  |

## Blockers

| Blocker | Severity | Issue | Evidence | Required before launch? | Owner |
| --- | --- | --- | --- | --- | --- |
|  |  |  |  |  |  |

## Watch Items (not blockers)

| Watch item | Risk | Launch-day monitoring/check | Owner |
| --- | --- | --- | --- |
|  |  |  |  |

## Post-Launch Work (explicitly not launch-gating)

| Item | Issue | Owner |
| --- | --- | --- |
|  |  |  |

## Evidence Captured

- Staging outcome smoke:
- Production outcome smoke:
- Serverless/integration versions + config preservation:
- Data/import/index state:
- External confirmation (or the honest caveat that it is missing):

## Decisions

| Decision | Rationale | Decided by | Date |
| --- | --- | --- | --- |
|  |  |  |  |

## Launch / Pilot Plan

1. [Step]
2. [Step]
3. [Step]

## Rollback / Hold Criteria

- Hold launch if:
- Roll back if:
- Owner for rollback:

## Follow-Up

| Action | Owner | Due | Issue |
| --- | --- | --- | --- |
|  |  |  |  |
```

### 5.6 Consulting / strategy note template

```markdown
# Consulting / Strategy Note: [Topic]

<!-- EVIDENCE-DRIVEN-DELIVERY.md section 2 step 1: a strategy discussion
     is captured as a decision note, not code. Business/project decisions
     live in meeting notes; architectural decisions become ADRs in
     docs/decisions/ — if this note makes an architectural call, its Next
     Action is "write the ADR". -->

**Issue:** [ACME-###](https://linear.app/<workspace>/issue/ACME-###)
**Date:** YYYY-MM-DD
**Audience:** [client | vendor | internal | mixed]
**Source:** [meeting, email, chat, research, live evidence]

## Question

[What decision, recommendation, or tradeoff needs to be answered?]

## Recommendation

[Plain-English recommendation. Include "do not decide yet" if that is
the right answer.]

## Context

- Business context:
- Technical context:
- Delivery/launch impact:
- Known constraints:

## Options

| Option | Pros | Cons | Delivery impact | Recommendation |
| --- | --- | --- | --- | --- |
|  |  |  |  |  |

## Decision

| Decision | Decided by | Date | Rationale |
| --- | --- | --- | --- |
|  |  |  |  |

## Non-Goals

- [What this note does not decide or implement.]

## Next Actions

| Action | Owner | Due | Issue / link |
| --- | --- | --- | --- |
|  |  |  |  |

## Evidence / References

- [Link]
```

### 5.7 ADR-0000 template

```markdown
# ADR-NNNN: <short decision title>

**Status:** [proposed | accepted | superseded by ADR-NNNN]
**Date:** YYYY-MM-DD
**Deciders:** [who decided]

## Context

[What forces, constraints, and prior decisions drive this choice?]

## Decision

[What did we choose, in full sentences?]

## Alternatives considered

- **<alternative>** — rejected: <why>.
- **<alternative>** — rejected: <why>.

## Consequences

- Easier: <what gets easier>
- Harder: <what gets harder>
- Follow-up work: <what's left to do>
```

### 5.8 The binding `AGENTS.md` paragraph (reproduced from §3.9)

```markdown
## Delivery workflow (binding)

This project follows Evidence-Driven Delivery: read
`docs/delivery/EVIDENCE-DRIVEN-DELIVERY.md` before starting any work.
Non-negotiable rules: no work without a Linear issue AND a work packet
(`docs/delivery/work/<KEY>/packet.md`) created first. Keep the packet's
Working Log current while you work (done / next / blockers) so another
agent can resume your task cold. One checklist item = one PR. Acceptance
criteria are Gherkin scenarios, and every scenario must map to a test.
Record architectural decisions as ADRs in `docs/decisions/`. After reviews,
run the feedback digest and merge only with CI green and 0 unresolved
review threads. A task is NOT done until tests cover it, existing
documentation is updated, and any needed new maintainer- and user-facing
documentation is written. Record deploy/verification evidence in the
packet, and never overstate a result. If this file and the EDD document
conflict, the EDD document wins — then fix this file.
```

### 5.9 The packet-pr-feedback-merge sequence (verbatim, from `.claude/commands/`)

#### `new-work.md`

```bash
# Standard
python3 scripts/delivery/new_work_packet.py --issue ACME-### --title "Description"

# Meeting-derived (validated relative link embedded in the packet)
python3 scripts/delivery/new_work_packet.py --issue ACME-### --title "Description" \
  --meeting docs/meetings/transcripts/YYYY-MM-DD-HH-MM-type-topic.md

# With a branch for M1
python3 scripts/delivery/new_work_packet.py --issue ACME-### --title "Description" --create-branch
```

#### `pr-feedback.md`

```bash
# Auto-detect PR from the current branch
python3 scripts/delivery/pr_feedback_digest.py

# Or explicitly
python3 scripts/delivery/pr_feedback_digest.py --slug ACME-### --pr <N>
```

Snapshots + `latest.md`/`latest.json` land in
`docs/delivery/work/ACME-###/prs/pr-<N>/feedback/`. The Action Summary
carries `unresolved_count`, the number the merge gate reads.

#### `merge-ready.md`

```bash
# Auto-detect from the current branch
python3 scripts/delivery/verify_merge_ready.py

# Or explicitly
python3 scripts/delivery/verify_merge_ready.py --slug ACME-### --pr <N>
```

#### `deploy-staging.md` — outcome-smoke template appended to the packet

```markdown
- YYYY-MM-DD HH:MM — STAGING DEPLOY: commit `<sha>`, deploy/activity `<id>`.
  Outcome smoke: <concrete results — counts/amounts/bytes, not "passed">.
  Log scan: <PASS / findings>. NEXT: <step> · BLOCKERS: <or "none">
```

#### `deploy-production.md` — production-smoke template appended to the packet

```markdown
- YYYY-MM-DD HH:MM — PRODUCTION DEPLOY: commit `<sha>`, deploy/activity `<id>`.
  Outcome smoke: <concrete results>. Rollback path: <exact command/procedure
  and its owner>. Monitoring: <what was watched, for how long, result>.
```

---

## 6. Seeding a new repo — the three load-bearing files

1. **`docs/delivery/EVIDENCE-DRIVEN-DELIVERY.md`** — the canonical
   methodology hub. The single document that defines every rule and
   binds every other file together. If only one file survives, this is
   it.
2. **`docs/delivery/work/000-packet-template.md`** — the packet
   skeleton. The unit-of-work contract that the Gherkin acceptance,
   Working Log, PR Loop Status, and Links sections all hang from.
   Reproduced verbatim in §2.1 of this digest.
3. **`scripts/delivery/verify_merge_ready.py` (or its agent command
   `.claude/commands/merge-ready.md`)** — the merge gate. The
   structural enforcement that turns "0 unresolved threads" from a
   suggestion into a blocker. This is the lever that the audit history
   shows actually changed the merge-completion rate.

---

A new repo (like Tower) should ship the canonical
`EVIDENCE-DRIVEN-DELIVERY.md` as its hub, the 5 templates in
`docs/delivery/templates/`, the three scripts in `scripts/delivery/`, the
6 agent commands in `.claude/commands/`, the binding paragraph in
`AGENTS.md`, and the `ADR-0001` bootstrap ADR. Optional extensions —
a flaky-check override in the merge gate, a deploy-governance doc, a
napkin corrections log, legacy-tracker archive handling — are layered on
only if they apply.

## Appendix: One-paragraph summary

> Most delivery slips start in one gap. Someone says a thing is done,
> and it isn't. The code "works." The fix "should be fine." Staging is
> "deployed." Then launch day finds the hole.
>
> I got tired of that gap, so I started running delivery on evidence
> instead of claims. The rule is simple. A status is only true when
> there's a receipt for it. Not a sentence in Slack. An actual artifact
> anyone can check.
>
> Here is the starter kit. Write down what each claim requires before
> the work starts. "Code is review-ready" means focused tests ran, plus
> a PR link. "Merge-ready" means CI is green and the review threads sit
> at zero. "Deployed to production" means a commit, a smoke check, and a
> rollback path. Pick the lightest evidence that actually proves the
> claim, and no lighter than that.
>
> Then add one honest rule. If you can't confirm something, say so. When
> an outside system hasn't confirmed the order yet, the note reads
> "external confirmation pending." You don't round up.
>
> Run a short review gate before any go or no-go call. Does the evidence
> support the claimed status? Where it doesn't, the work isn't done.
> It's just described.
>
> The habit is small. Write the evidence bar before the work, not after.

(This summary names no specific project,
client, tracker, or system. It has cleared the Definition of
Shippable gate.)
