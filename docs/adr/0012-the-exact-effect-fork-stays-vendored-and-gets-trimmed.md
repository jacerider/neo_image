# 0012 — The `exact` effect's `image_effects` fork stays vendored and gets trimmed, not replaced

**Status:** accepted · **Date:** 2026-08-28
**Context:** `neo_image` — the **vendored effect fork** and the **canvas rectangle** inside it
**Issue:** jacerider/neo_image#7  ·  **Plan:** `neo-image-canvas-rectangle` on wps

**Decision.** `neo_image` keeps its copy of `image_effects`' canvas machinery and reduces it to what
the **exact effect** uses: the **canvas rectangle** loses its rotation, translation, resize and grid
algebra and is marked `@internal`. It does not take a dependency on `image_effects` and delete the
copy, even though the copy is 1,206 lines — a third of the module — and roughly half of its
largest class is unreachable. The fork's remaining four files keep every line they have.

**Why it needs recording.** A proprietary package carrying a third of its source as a recognisable
copy of a contributed module invites "why not just require it?", and the obvious answer is wrong.
The fork exists for a reason invisible from the code: core removed the positioned-rectangle helper
`image_effects` built on, so the copy was taken to keep one effect working under Drupal 11. That
reason has expired — the copy is now self-contained — which makes the dependency look strictly
better than it is. It is not a dependency swap: the **id grammar** encodes this effect as the letter
`e`, every **derivative directory** is named by that grammar, and the style manager reconstructs
styles by parsing those names, so another module's effect plugin means a **codec** change.

**Rejected.**
- Require `image_effects` and delete all 1,206 lines — the improvement scan itself recorded it as
  weaker: a codec change, a derivative migration and an image flush on roughly thirty sites to
  remove code that costs nothing to run, and a hard dependency in a package that requires only core
  and two Neo packages, for one effect.
- Keep the fork whole so it can be diffed against upstream — nothing has diffed it in eighteen
  months and nothing is set up to: no recorded upstream version, no update procedure, three of the
  five files never edited. Two hundred unreachable lines buy a fidelity nobody maintains.
- Extract the fork into its own `jacerider/*` package — the worst of both: a package for one
  effect, a release on every `neo_image` change that touches it, and not one line fewer.

**Cost.** The 1,206 lines with a visible upstream will keep being reported by every scan. Divergence
becomes permanent: upstream fixes to the rectangle's arithmetic will not apply cleanly and nobody
will notice they exist — accepted because the surviving surface is thirty lines of integer
arithmetic with no reported defects in eighteen months, and the deleted algebra is the part upstream
would be fixing. The fork's other defects stay ours: the effect's configuration form declares two
elements of a type only `image_effects` provides, so it cannot render without that module. The
`@internal` mark makes further trimming a module decision, not a fleet compatibility question, and
anyone proposing adoption again must answer the codec and derivative-migration question first.
