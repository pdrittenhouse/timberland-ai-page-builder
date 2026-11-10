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

**Gap** — A request that no component in the library can express.

A gap is a first-class, structured result of the generation pipeline — not an error, and not an
absence. *(Wording adopted deliberately per plan item L3, which found this definition already
stated tightly elsewhere and chose to reuse it rather than invent a looser one.)* Two properties follow from the definition and are part of it:

- A gap is **reported, never silently approximated.** Picking the nearest available block and
  rendering it produces a page that validates as correct and is wrong, which reads as product
  quality rather than as an out-of-scope request.
- **Reporting a gap is not authoring a component.** The pipeline names what it cannot build; it
  does not invent a block to cover it.

**Projection**

One artifact rendered for one audience. Same source, different shape — **not** a second source.

- **Editor projection** — blocks + registered block patterns + synced patterns.
- **Dev projection** — blocks + design-system patterns + the dependency graph.

A projection is distinct from a *copy*: a copy drifts from its source and catches nothing, which
is why `docs/library/` propagated a documentation error unchanged.

**Selection index**

The only artifact that is ever placed in a prompt.

- Holds one line of `best_for` and one of `avoid_when` per component — **not** full schemas.
- **90 rows today** (42 design-system patterns + 48 blocks) ≈ 2,229 tokens, against a
  3,000–8,000 token budget it shares with brand context.
- The full catalog and the brand context are separate artifacts and are **not** prompted.

---

## Schema vocabulary

Settled 2026-11-08 (AI-INTEGRATION PS1).

**Component schema** — a JSON-Schema draft-07 document describing one component's configurable
values. `type: object` at the root, `additionalProperties: false`, a `required[]` list. One per
component: 42 design-system patterns and 48 blocks by the current manifests, 90 in total. None
exists yet — no `.schema.json` is present anywhere in the stack today.

**Storage shape** — the key shape a component's values take *as stored in post content*, which is
what a component schema describes. Distinct from the **render shape**, the nested form a Twig
template reads. The two differ wherever ACF clone with `prefix_name` is used, which is 31% of all
field definitions: clone stores a flat prefixed key and returns it nested.

The schema describes the **storage** shape, not the render shape. That is what makes
`additionalProperties: false` usable — both the validator and the model are working on the stored
side, and `FieldKeyMap::flatten_fields()` already emits exactly these flat prefixed names. The
render shape stays the template's concern, and `FieldKeyMap` remains the single place the
translation happens.

**`x-tl-`** — the extension-keyword prefix for anything a data schema cannot express: display
order, groupings, cross-property invariants, conditional guards, provenance. Verified against
`jsonschema` 4.25.1 — draft-07 accepts both `x-tl-*` and a bare `tl-*` as schema keywords, and
`additionalProperties: false` still rejects an invented *instance* property in both cases, so
extensions in the schema document never weaken instance validation. `x-` is kept because it is
the recognised signal for "extension, not a schema keyword"; a bare `tl-order` is
indistinguishable from a misspelled real keyword without knowing this project.

**Envelope** — the stable, versioned wrapper around per-component schema contents: `$schema`, a
schema-format `version`, and the key names. Versioned **separately from the contents**, because
the two have opposite requirements — a derived artifact changes shape whenever a source does, and
a published API should not. Contents regenerate on every build; a breaking change to the envelope
is a deliberate version bump. This is also the insulation point for the ACF dependency: a
consumer reading the envelope never reads ACF directly, so the ACF-specific derivation stays
swappable behind it.

**Composition schema** — the schema for an ordered list of block instances making one page: the
generation pipeline's output, validated before anything is written. Distinct from a component
schema, and necessary because the layout invariants are **cross-component** and no per-component
schema can state them — `column` only as a child of `row`, the eleven parent-restricted blocks,
and containers not nesting.

**Demo fixture** — the existing `<name>.json` beside each design-system pattern, holding a `demo`
object of placeholder content for Storybook. **It is not a schema and does not become one.** 42 of
them exist; they stay exactly as they are. `block.json` is likewise unrelated: it is WordPress
block registration, present in all 41 framework block directories.

---

## Design-fidelity vocabulary

Settled 2026-11-08 (AI-INTEGRATION G1; shape adopted per FG3).

**Provenance** — Where a value came from, recorded alongside the value.

Per component, the shape is `{file, node, node_mobile, status, deviations[]}` — adopted per plan
item FG3, which takes the *discipline* rather than the implementation — where `status`
distinguishes *Figma-backed* from everything else, and `deviations[]` carries anything a design
specifies for which no design token exists.

A deviation is "a labelled, reviewable open question, never a raw hex sitting in the CSS". The
slot exists in the schema before Figma work begins, so filling it later is a value change rather
than a schema migration.

**approved** — every value traces to a node id, and there are no open deviations. Requires a
`file` AND a `node` AND an empty `deviations[]`.

**custom** — anything else. Not a criticism: a hand-built variant with no Figma source is a
legitimate component, and most of the library is one. It is a *description of what can be
checked*, not a quality judgement.

`status` is **derived, never authored**. A hand-written `approved` is exactly the claim this
exists to make checkable, so writing one by hand would defeat the purpose.

**Provenance is a label, never a gate.** Nothing in the pipeline may refuse to render a component
because its status is `custom` or because it carries deviations. A gate would make people omit
the section, and an omitted section is indistinguishable from an untraced component — which is
the state this is meant to end.

### The three rules

1. **Values are pulled by node id, never eyeballed.** A colour read off a screenshot is not
   provenance.
2. **A raw literal is acceptable only when it is Figma-exact and its node id is recorded
   alongside.** The literal is not the problem; an unattributed literal is.
3. **Anything a design specifies for which no token exists becomes a flagged deviation** — named
   in `deviations[]`, not quietly shipped as a hex.

### Authoring it

`provenance()` in `block-metadata.mjs` reads a `## Design Provenance` section from the
component's `.md`, so it lives beside the prose it describes and travels with the component —
the same reasoning as `best_for`. A structured copy anywhere else is the drift this workstream
exists to prevent.

```markdown
## Design Provenance

- file: aBcDeFgHiJkL
- node: 2503-3665
- node_mobile: 2503:3700
- deviation: card shadow has no token; Figma specifies 0 2px 8px rgba(0,0,0,.08)
```

Node ids are accepted in either spelling and recorded in the API's colon form — a share URL gives
`2503-3665`, the API wants `2503:3665`, and a reviewer should be able to paste either.

**Absent section means `status: custom` with no file or node**, emitted anyway. Writing the key
only when present would make "no Figma source" and "nobody filled this in" indistinguishable.

**Current state: 41/41 blocks are `custom`, 0 deviations recorded** — nothing has been traced to
Figma yet, which is the honest baseline this measures from.
