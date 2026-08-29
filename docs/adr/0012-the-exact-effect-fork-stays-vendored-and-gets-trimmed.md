# 0012 — The `exact` effect's `image_effects` fork stays vendored, and gets trimmed rather than replaced

**Status:** accepted
**Date:** 2026-08-28
**Context:** `neo_image` — the **vendored effect fork**: the rectangle, utility, effect and toolkit-operation
classes copied from the `image_effects` contributed module to support the **exact effect**
**Plan:** `docs/plans/neo-image-canvas-rectangle/`

## Decision

`neo_image` keeps its copy of `image_effects`' canvas machinery and reduces it to what the **exact
effect** actually uses. It does **not** take a dependency on `image_effects` and delete the copy,
even though the copy is 1,206 lines — a third of the module — and even though roughly half of its
largest class is unreachable.

The trim is the deletion of the **canvas rectangle**'s rotation, translation, resize and grid
algebra. The fork's remaining four files keep every line they have.

## Why this needs recording

Every future reader arrives at the same place. A proprietary package carries a third of its source as
a recognisable copy of a contributed module, and the obvious question — why not just require it? —
has an obvious-looking answer that is wrong, so it will keep being asked. Recording it here is
cheaper than answering it once per scan.

The fork exists for a reason that is invisible from the code: core removed the positioned-rectangle
helper `image_effects` built on, so the copy was taken to keep one effect working under Drupal 11.
That reason has expired — the copy is now self-contained — which makes "just depend on the module"
look strictly better than it is.

## What it costs

**A duplication that will keep being reported.** Any scan, review or similarity pass over this module
surfaces 1,206 lines with a visible upstream, and each time someone has to arrive back here.

**Divergence becomes permanent.** Trimming the copy means it is no longer a copy. Upstream fixes to
that rectangle's arithmetic — should there be any — will not apply cleanly, and nobody will notice
they exist. This is accepted knowingly: the surviving surface is thirty lines of integer arithmetic
with no reported defects in eighteen months, and the algebra being deleted is the part upstream would
be fixing.

**The fork's other defects stay ours.** The effect's configuration form declares two elements of a
type only `image_effects` provides, so it cannot render on a site without that module — a fragment
copied without its dependency. Keeping the fork means that is this module's bug to fix, not one that
disappears with an adoption.

## Alternatives considered

**Require `image_effects` and delete all 1,206 lines.** Rejected, and it is the alternative the
improvement scan itself recorded as weaker. It is not a dependency swap. The **style id** grammar
encodes this effect as the letter `e`, every generated derivative is written to a directory named by
that grammar, and the style manager reconstructs styles by parsing those directory names — so
adopting a different module's effect plugin means a codec change, a derivative migration and an image
flush on roughly thirty sites, to remove code that costs nothing to run. It also moves a hard
dependency into a package that currently requires only core and two Neo packages, for one effect.

**Keep the fork and leave it whole**, on the grounds that a copy should stay a copy so it can be
diffed against upstream. Rejected because nothing has diffed it against upstream in eighteen months
and nothing is set up to: there is no recorded upstream version, no update procedure, and three of the
five files have never been edited. The copy's fidelity is a property nobody is maintaining, so paying
two hundred unreachable lines to preserve it buys nothing that exists.

**Extract the fork into its own `jacerider/*` package** so the duplication is at least isolated.
Rejected as the worst of both: it creates a package for one effect, adds a release to every
`neo_image` change that touches it, and does not reduce a single line.

## Consequences

The **canvas rectangle** loses its algebra and is marked `@internal` to `neo_image`, which is the
boundary this decision draws: the fork is module-private, so trimming it further later is a decision
about this module rather than a fleet compatibility question. The remaining fork files are untouched
and stay eligible for the same treatment under their own candidates.

Anyone proposing the adoption again should expect to answer the codec and derivative-migration
question first, and to say what it buys beyond line count. That is the argument this ADR is asking
for, not a re-run of the line count.
