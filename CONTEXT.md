# CONTEXT — shared vocabulary

**Terms only. No decisions.** Where a term is ambiguous in the stack, this file fixes one name
per sense and says where each sense is verifiable. It deliberately does not say what to *do*
about any of them — that belongs in the plans, not here.

Every claim below was checked against the code at the path given, not transcribed from a plan.
Where a figure appears, it is a count taken from the source.

---

## 1. "Pattern" — three unrelated things

This is the term that has already cost one design error: an early draft of AM2 carried sense 1
into the manifest, which would have spent scarce prompt budget on primitives the editor cannot
place while omitting the thing the generation flow actually asks for.

### 1a. Design-system pattern
A Twig component primitive under `timberland/src/design-system/patterns/<level>/<name>`.

- Six levels: `00-protons`, `01-atoms`, `02-molecules`, `03-organisms`, `04-templates`, `05-pages`.
- **42** entries in `timberland-starter/src/manifests/pattern-manifest.json`, each with
  `{name, level, dependencies[], requires_vendor[], css, js, demo}`.
- Composed by developers in Twig. **The editor cannot place one.**
- Its CSS is delivered per-page by `PatternLoader`, resolved from registered asset bases, and
  only when the pattern is detected for that request.

### 1b. Registered block pattern
Pre-composed block markup offered in the WordPress inserter, via `register_block_pattern()`.

- Declared in `timberland/src/BlockPatterns.php`. **Exactly one** is registered today:
  `core/query/card`. Further registrations are expected through the
  `timberland/block_patterns/register` action.
- Lives in PHP, ships with the theme, appears in the inserter.
- `timberland/src/ThemeSupport.php` also calls `unregister_block_pattern()`, so the set the
  editor sees is theme-curated rather than simply core's.

### 1c. Synced pattern (reusable block)
A `wp_block` post in the database.

- Authored by editors, stored as content, not as code.
- **This is what the prototype's `ManifestBuilder::collect_editor_patterns()` actually reads** —
  `get_posts(['post_type' => 'wp_block', ...])`.
- Gated behind the `include_editor_patterns` setting.

**Naming rule for this codebase:** write *design-system pattern*, *registered block pattern*,
or *synced pattern*. Bare "pattern" is ambiguous and should not appear in a manifest key, a
prompt, or a schema field name.

> The business plan's generation flow says the AI receives "available patterns (pre-composed
> block groups)" — senses **1b and 1c**, not 1a.

---

## 2. Block

An ACF or core block type — the unit the editor places and the unit a generated composition is
made of.

- **48** entries in `timberland-starter/src/manifests/block-manifest.json`.
- The prototype derives block schemas by scanning `block.json` directories
  (`ManifestBuilder::collect_blocks()`), not by reading prose documentation.
- A block may *consume* design-system patterns (1a) internally; that relationship is the
  dependency graph, not an equivalence.

---

## 3. The three tiers

From the plugin plan. These describe **audiences**, and each has a different trust boundary and
a different latency budget.

| Tier | Audience | Vehicle |
|---|---|---|
| **Dev tier** | developers using Claude Code | MCP server (`timberland-ai-mcp`) querying the live site |
| **Editor tier** | content editors in wp-admin | AI Page Layout Generator |
| **Visitor tier** | site visitors | RAG chat and semantic search |

"Tier" is also used in this stack for the **four-layer architecture** (framework → plugin →
parent theme → child theme) and for **asset-base precedence** (`child` > `parent` >
`framework` in `timberland/pattern_loader/asset_bases`). Those are unrelated to the three
audience tiers above.

**Naming rule:** write *audience tier* or *architecture tier* when either could be meant.

---

## 4. Gap

**A request that no component in the library can express.**

A gap is a first-class, structured result of the generation pipeline — not an error, and not an
absence. Two properties follow from the definition and are part of it:

- A gap is **reported, never silently approximated.** Picking the nearest available block and
  rendering it produces a page that validates as correct and is wrong, which reads as product
  quality rather than as an out-of-scope request.
- **Reporting a gap is not authoring a component.** The pipeline names what it cannot build; it
  does not invent a block to cover it.

---

## 5. Provenance

**Where a value came from, recorded alongside the value.**

Per component, the shape is `{file, node, node_mobile, status, deviations[]}`, where `status`
distinguishes *Figma-backed* from everything else, and `deviations[]` carries anything a design
specifies for which no design token exists.

A deviation is "a labelled, reviewable open question, never a raw hex sitting in the CSS". The
slot exists in the schema before Figma work begins, so filling it later is a value change rather
than a schema migration.

---

## 6. Projection

One artifact rendered for one audience. Same source, different shape — **not** a second source.

- **Editor projection** — blocks + registered block patterns + synced patterns.
- **Dev projection** — blocks + design-system patterns + the dependency graph.

A projection is distinct from a *copy*: a copy drifts from its source and catches nothing, which
is why `docs/library/` propagated a documentation error unchanged.

---

## 7. Selection index

The only artifact that is ever placed in a prompt.

- Holds one line of `best_for` and one of `avoid_when` per component — **not** full schemas.
- **90 rows today** (42 design-system patterns + 48 blocks) ≈ 2,229 tokens, against a
  3,000–8,000 token budget it shares with brand context.
- The full catalog and the brand context are separate artifacts and are **not** prompted.

---

## 8. Derivation vs transcription

- **Derived** — produced from the code it describes, so a change to the code changes it.
- **Transcribed** — written by hand or copied, so it can disagree with the code silently.

The distinction is load-bearing because comparing derived sources against each other is what
surfaces defects. Three were found that way:

- `tabs.md` documents **10** parameters where `tabs.stories.ts` declares **23** — 43% coverage.
  The 13 undocumented ones include `setHash`, `rotate`, `animation`, `fluidHeight` and the three
  `scrollToAccordion*` options.
- `docs/library/patterns.md` contains a self-contradicting sentence about `fill_justify`.
- `docs/library/` — a verbatim copy — propagated that error unchanged.

The first figure is itself an example: the plan this glossary draws on recorded it as "12
parameters / 24", transcribed rather than counted. Deriving it gave 10/23. The claim held; the
numbers did not.

---

## 9. Check vs test vs eval

Three things that assert correctness, distinguished by **when they run** and **who pays for
them**.

| | Runs | Cost | Blocks a user? |
|---|---|---|---|
| **Check** | inside a request, synchronously | microseconds, no browser | yes — refuses the work |
| **Test** | in the suite, on demand | seconds | no |
| **Eval** | separately, networked | money and minutes, non-deterministic | no |

A check and the test that proves the check works are **one implementation with two callers**,
never two copies. Two copies drift, and the drift is invisible: the suite passes while
production accepts what it rejects.

No eval ever runs inside the deterministic suite.
