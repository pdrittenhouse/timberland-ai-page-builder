# Context

Vocabulary specific to the AI page builder. **Terms only — no decisions.** Decisions live in
`timberland-starter/AI-INTEGRATION.html` and `timberland-plugin-ai-plan.md`.

**This file does not define the stack's vocabulary.** The words *pattern* (five senses), *block*,
*template*, *page*, *component*, *manifest* (seven), *critical*, *baseline*, *variant*, *fixture*,
*target*, the atomic-design levels and the layout primitives (`section`, `row`, `column`,
`container`) are all defined once, in
[`pdrittenhouse/timberland`](https://github.com/pdrittenhouse/timberland)'s `CONTEXT.md`. They are
deliberately not duplicated, because two copies of a glossary drift and the drift is silent.

Read that file first. Everything below assumes it.

---

## Generation vocabulary

Settled 2026-11-08 (AI-INTEGRATION G1).

### Gap

**A request that no component in the library can express.**

A gap is a first-class, structured result of the generation pipeline — not an error, and not an
absence. *(Wording adopted deliberately per plan item L3, which found this definition already
stated tightly elsewhere and chose to reuse it rather than invent a looser one.)* Two properties follow from the definition and are part of it:

- A gap is **reported, never silently approximated.** Picking the nearest available block and
  rendering it produces a page that validates as correct and is wrong, which reads as product
  quality rather than as an out-of-scope request.
- **Reporting a gap is not authoring a component.** The pipeline names what it cannot build; it
  does not invent a block to cover it.

### Projection

One artifact rendered for one audience. Same source, different shape — **not** a second source.

- **Editor projection** — blocks + registered block patterns + synced patterns.
- **Dev projection** — blocks + design-system patterns + the dependency graph.

A projection is distinct from a *copy*: a copy drifts from its source and catches nothing, which
is why `docs/library/` propagated a documentation error unchanged.

### Selection index

The only artifact that is ever placed in a prompt.

- Holds one line of `best_for` and one of `avoid_when` per component — **not** full schemas.
- **90 rows today** (42 design-system patterns + 48 blocks) ≈ 2,229 tokens, against a
  3,000–8,000 token budget it shares with brand context.
- The full catalog and the brand context are separate artifacts and are **not** prompted.

---

## Design-fidelity vocabulary

Settled 2026-11-08 (AI-INTEGRATION G1; shape adopted per FG3).

### Provenance

**Where a value came from, recorded alongside the value.**

Per component, the shape is `{file, node, node_mobile, status, deviations[]}` — adopted per plan
item FG3, which takes the *discipline* rather than the implementation — where `status`
distinguishes *Figma-backed* from everything else, and `deviations[]` carries anything a design
specifies for which no design token exists.

A deviation is "a labelled, reviewable open question, never a raw hex sitting in the CSS". The
slot exists in the schema before Figma work begins, so filling it later is a value change rather
than a schema migration.
