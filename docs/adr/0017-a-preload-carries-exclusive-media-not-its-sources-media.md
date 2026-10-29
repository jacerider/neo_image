# 0017 — A preload carries exclusive media, not its source's media

**Status:** accepted · **Date:** 2026-10-29
**Context:** `neo_image` — the preload links a **priority** render attaches

**Decision.** A **priority** render attaches one preload per image it can paint — each `<source>`
and the controlling `<img>` — and each preload's `media` is that image's **exclusive media**: the
source's own query as the lower bound, the complement of the next larger style's query as the upper
bound, and no lower bound at all for the controlling image. `NeoImage::getPreloadMediaQueries()`
builds them from the styles present. The href is the URL the element emits, verbatim.

**Why it needs recording.** The obvious implementation — and the one the request for this feature
described — copies each `<source>`'s `media` onto its preload, so the two look like they match. They
do not behave alike. A `<picture>` paints the *first* source whose query matches, and its sources'
queries overlap (`(width >= 48rem)` and `(width >= 80rem)` both match at 90rem); order settles it.
Preload links have no order: every link whose media matches downloads. Copied queries would fetch
one image per breakpoint below the viewport, plus the fallback, and paint one of them — the double
download the feature exists to prevent, on exactly the wide viewports nobody tests on a phone.

**Rejected.**
- Copy the source queries and omit a preload for the `<img>` — still overlapping, and the phone,
  the viewport priority matters most on, gets no preload.
- `imagesrcset`/`imagesizes` on the preload — the sources carry a single URL with no descriptor
  and no `sizes`, so `href` is the same fetch, and one browsers without `imagesrcset` honour too.
- Reorder or rewrite the `<source>` queries to be exclusive themselves — changes every picture this
  module emits, priority or not, for a problem only preloads have.

**Cost.** The preload queries are not textually the source queries, so a reader comparing them will
think they drifted. `PriorityRenderTest` compares each preload with the element it must fetch, and
breaks if they are made equal. The upper bound reads `minWidth`, which the breakpoint table carries
beside `mediaQuery`; a breakpoint whose query is not a plain `width >=` needs both edited.
