# 0013 — The two Twig render functions answer the same thing, and the options choose the shape

**Status:** accepted · **Date:** 2026-08-28
**Context:** `neo_image` — the **render dispatch** behind `neo_image()` and `neo_image_style()`
**Issue:** jacerider/neo_image#8  ·  **Plan:** `neo-image-twig-surface` on wps

**Decision.** `neo_image()` and `neo_image_style()` stay exact synonyms: both reach one **render
dispatch**, which reads the options and nothing else, so neither name narrows what a template can
ask for. After this plan they are two one-line public statics with identical bodies — the intended
end state, not a step towards separating them — kept only as the fleet's registered contract.

**Why it needs recording.** Today the two delegate to each other on complementary conditions — one
hands over when **breakpoint options** are absent, the other when present — so the equivalence is
invisible. Once explicit, the tempting edit is to make each name mean its shape — `neo_image()`
responsive, `neo_image_style()` single-style, as the README, the Alchemist editor's per-prop Twig
hints and this site's component skill all say. It looks like a bug fix and is wrong:
`neo_alchemist`'s Media Image Size value plugin answers **breakpoint options** and its documented
template line feeds them to `neo_image_style()`; the image-size shape feeds effect-keyed options
through the same line, so one call receives either shape depending on the value plugin attached.
Narrowing `neo_image_style()` would silently turn every such placement from a `<picture>` into one
`<img>`, and this site cannot see it, because no component here attaches that plugin.

**Rejected.**
- Make each name mean its shape — the change this ADR exists to stop: a markup change across
  roughly thirty sites, firing only where one value plugin is attached, with no error — pages keep
  returning 200 with a smaller, wrong image. If ever taken it needs its own candidate, blast-radius
  paragraph and a fleet survey of which sites attach that plugin, not a tidy-up in a typing plan.
- Register both Twig names against one method and delete the other — a public-API removal bought
  for nothing: both are `public static` on a package shared by roughly thirty sites, no PHP caller
  here uses either but the site cannot speak for the fleet, and the saving is one delegating line.
- Deprecate `neo_image()` for `neo_image_style()` — picks the more misleading name as survivor and
  puts a rename in front of every fleet call site and the Alchemist editor's generated Twig hints.

**Cost.** Two identical public statics will be surfaced by every similarity scan — the second
neo_image ADR in this position after 0011, two deliberate near-duplicates for unrelated reasons.
`neo_image_style()` still reads as "render one image style" and does not mean that, and the fix is
a fleet-wide markup change. Nothing can demand a genuinely single-style render: a value plugin
upstream can change the options unseen by the template. The kernel tests assert both functions
answer the same render array for every subject and option shape; anyone separating them should edit
that assertion, say so out loud, and first answer which sites attach that plugin.
