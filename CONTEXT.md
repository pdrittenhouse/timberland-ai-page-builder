# CONTEXT — shared vocabulary

**Terms only. No decisions.** Where a term is ambiguous in the stack, this file fixes one name
per sense and says where each sense is verifiable. It deliberately does not say what to *do*
about any of them — that belongs in the plans, not here.

Every claim below was checked against the code at the path given, not transcribed from a plan.
Where a figure appears, it is a count taken from the source.

---

## 1. "Pattern" — four unrelated things

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

### 1d. Unsynced pattern
The same inserter entry as 1b or 1c, inserted **detached** — its blocks are copied into the post
and no longer track the source.

- WordPress presents 1c and 1d in one UI, distinguished only by a "Synced" toggle.
- The distinction matters to generation: an unsynced insertion produces ordinary block markup a
  model can read and modify; a synced one produces a `wp:block {"ref":N}` reference it cannot.

**Naming rule for this codebase:** write *design-system pattern*, *registered block pattern*,
*synced pattern*, or *unsynced pattern*. Bare "pattern" is ambiguous and should not appear in a
manifest key, a prompt, or a schema field name.

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

## 2a. Atomic-design levels

The six directories under `src/design-system/patterns/`. **Checked against the filesystem, because
the names are not the conventional ones.**

| level | conventional name | populated today |
|---|---|---|
| `00-protons` | "particles" in most atomic-design writing | yes |
| `01-atoms` | atoms | yes |
| `02-molecules` | molecules | yes |
| `03-organisms` | organisms | yes |
| `04-templates` | templates | **empty** |
| `05-pages` | pages | **empty** |

Two things follow:

- **It is `protons`, not `particles`.** Nothing in this stack is called a particle. A prompt or
  schema using that word matches nothing.
- **`04-templates` and `05-pages` are empty.** They are structural placeholders. Any reference to
  a "template" or "page" in this stack therefore means one of the senses in §2b or §2c, never an
  atomic-design level — the collision is real but currently latent, which is the dangerous kind.

A level is always written with its numeric prefix (`02-molecules`), because that is the form the
manifest and the asset paths use.

---

## 2b. "Template" — four things

- **Theme template** — a PHP file in the theme root selected by WordPress's template hierarchy.
  **13 of the 20** PHP files there are hierarchy entries: `404.php`, `archive.php`, `author.php`,
  `front-page.php`, `index.php`, `page.php`, the four `page-template-*.php`, `search.php`,
  `single.php`, `woocommerce.php`. The other 7 are includes and helpers (`header.php`,
  `footer.php`, `sidebar.php`, `searchform.php`, `functions.php`, `cpt.php`,
  `_new-template.php`) and are never selected by the hierarchy.
- **Twig template** — a `.twig` file under `src/templates/`. **139** of them. Rendered by Timber,
  usually from a theme template above.
- **Atomic-design template** — `04-templates`. Empty (§2a).
- **WP block template** — an FSE `templates/*.html` file. **None exist** in this stack; it is not
  a block theme.

"Pattern template" is not a term in this codebase. If it appears, it means either a Twig template
belonging to a design-system pattern (`_tabs.tpl.twig`) or a registered block pattern's `content`
string, and should be written as one of those.

**Naming rule:** always qualify — *theme template*, *Twig template*, *block template*.

---

## 2c. "Page" — three things

- **WP page** — a `page` post-type record. Content.
- **Theme page template** — `page-template-centered.php` and similar: a hierarchy entry an editor
  selects per page. Code.
- **Atomic-design page** — `05-pages`. Empty (§2a).

Related content terms that are *not* interchangeable with any of the above:

- **WP post** — a `post` post-type record. Also used loosely for "any post-type record", which is
  how `get_posts(['post_type' => 'wp_block'])` returns synced patterns: those are posts too.
- **Archive** — a *query result* rendered by `archive.php`, not a stored record. There is no
  archive object to generate; a generated composition cannot be an archive.

**Naming rule:** *WP page* / *WP post* for content, *page template* for the PHP file, and never
bare "page" in a schema field.

---

## 2d. "Component" — three things

- **Bootstrap component** — one of the **26** component stylesheets under
  `dist/wp/css/bootstrap/`, loaded conditionally by `BootstrapLoader` (269 references to the term
  there) and forced on for one fixture via `timberland/bootstrap_loader/all_components`. This is
  the sense the visual gate's `componentCoverage` means.
- **Design-system pattern** — §1a. Called a "component" in casual speech and in some docblocks.
- **Twig component** — the file implementing one, e.g. `_tabs.tpl.twig`.

**Naming rule:** *Bootstrap component* when it is one of the 26; otherwise use the §1a name.
"Component" alone is the single most overloaded word in this stack after "pattern".

---

## 2e. "Manifest" — seven things, and this plan adds the seventh

**351 uses across the framework docs.** The most overloaded noun in the stack after "pattern",
and the one most likely to be miscommunicated to a model, because the AI work introduces a new
sense while five already exist.

Five are webpack output, and **they do not all land in the same place** — four are committed
under `timberland-starter/src/manifests/`, one is build output under `dist/wp/`:

| file | emitted by | written to | describes |
|---|---|---|---|
| `pattern-manifest.json` | `PatternManifestPlugin` | `src/manifests/` | the 42 design-system patterns |
| `block-manifest.json` | `BlockManifestPlugin` | `src/manifests/` | the 48 blocks |
| `bootstrap-manifest.json` | `BootstrapManifestPlugin` | `src/manifests/` | Bootstrap components |
| `vendor-manifest.json` | `VendorManifestPlugin` | `src/manifests/` | vendor assets |
| `template-manifest.json` | `TemplateManifestPlugin` | **`dist/wp/`** | templates |

Plus two that are not build output:

- **Icon subset manifest** — `src/generated/icon-fonts/subset-manifest.json`, read by
  `icon-glyphs.spec.js`.
- **AI manifest** — the artifact AM1–AM5 define and `ManifestBuilder` produces. **This is a new,
  seventh thing.** It is assembled *from* several of the above; it is not one of them.

**Naming rule:** never write "the manifest". Write *pattern manifest*, *block manifest*, *AI
manifest*, and so on. In schema fields and prompts, prefer the filename.

---

## 2f. "Critical" — four things

- **Critical half of a pattern split** — `PatternCriticalSplitPlugin` divides each pattern's CSS
  into a *critical* (layout) half and a *content* (appearance) half. This is the sense in
  `patterns-critical-*.css`.
- **Paint-critical pattern** — a member of the `paint_critical_patterns` list: a pattern whose
  layout must be inlined because it hides itself until JS runs. A narrower, explicit set.
- **Inline critical budget** — `timberland/performance/inline_critical_budget`, the byte ceiling
  on what gets inlined rather than linked.
- **Bootstrap critical bundle** — `dist/wp/css/bootstrap/critical.css`, an unrelated split of
  Bootstrap's own CSS.

The first two are routinely confused, and the confusion is consequential: being in the critical
*group* only inlines a pattern's layout, so a self-hiding rule must also classify as layout or
the protection is void.

**Naming rule:** *critical half*, *paint-critical*, *inline budget*, *Bootstrap critical*.

---

## 2g. "Baseline" — five things

Five committed-expectation stores, with different portability and different update commands.

| baseline | count today | portable between machines? |
|---|---|---|
| computed-style (`__baselines__/*.json`) | 84 | yes — values |
| PNG screenshots (`__baselines__/screenshots/`) | 6 | **no** — encodes font rasterisation |
| PHPStan (`phpstan-baseline.neon`) | 171 entries | yes |
| PHPCS (`phpcs-baseline.json`) | 132 groups | yes |
| golden render (`tests/golden/__snapshots__/`) | 36 | yes |

A sixth exists per enrolled target: the T5 interactive-state baseline
(`<target>.<viewport>.states.json`).

**Naming rule:** qualify every one. "Re-record the baseline" is ambiguous and the commands
differ — `UPDATE_BASELINES=1`, `--update-snapshots`, `UPDATE_GOLDEN=1`,
`analyse:baseline`, `sniff:baseline` are five different things.

---

## 2h. "Variant" — three things

- **Pattern variant** — `_name~variant.tpl.twig`. **17** such files; a named alternative rendering
  of one design-system pattern.
- **Breakpoint variant** — a per-breakpoint CSS bundle from `BreakpointVariantsPlugin`
  (`styles-bp-lg.css` and siblings).
- **Fixture variant** — F2's one-factor-at-a-time sweep, which renders a block once per value of
  a class-composing field.

---

## 2i. "Fixture" and "target"

- **Fixture** — a page `Fixtures.php` seeds, slug-prefixed `tl-fixture-`. Content that exists so
  the gate has something deterministic to measure. Not a PHPUnit fixture; the PHP suites use none.
- **Target** — an entry in a theme's `visual.config.js` `targets[]`: a URL plus the per-target
  options (`maxBytes`, `canary`, `png`, `a11y`, `states`, `viewports`). Most targets are
  fixtures; the `site-*` ones are real pages and are the exception.

A fixture is content. A target is a test declaration pointing at content. One can exist without
the other, and both have — a fixture with no target is unguarded, and a target naming a
non-existent fixture is silently ignored.

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
