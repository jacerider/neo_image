# 0011 — The two `neo_image` URL **entry points** stay separate, because "external" includes `private://`

**Status:** accepted
**Date:** 2026-08-27
**Context:** `neo_image` — the URI **entry point** and the entity **entry point** that both answer an
image-style URL, and the **resolved file** step this plan puts underneath them
**Plan:** `docs/plans/neo-image-file-resolver/`

## Decision

The entity URL **entry point** does **not** delegate to the URI URL **entry point**, even though
after this plan the only thing separating them is four lines. They share the **derivative ensure**
step through a private helper and nothing else, and a test pins the one behaviour that differs.

## Why this needs recording

The two methods will look like a duplicate to every future reader. After the **resolved file** step
lands, the entity one is *resolve the file, take its URI, ensure, build the URL* and the URI one is
*rewrite the path, ensure, build the URL*. Collapsing the first into the second is the obvious
simplification, it is a two-line diff, and it is wrong.

`NeoImageStyle::isExternalUri()` answers TRUE for two different things: a genuinely external URL, and
**any URI that is not on the public stream**. A `private://` file is the second. The URI entry point
returns its argument unchanged when that predicate is TRUE, so a delegating entity entry point would
answer a raw `private://` string for every private file — not a URL, not routable, not an image.
Today the entity entry point never consults the predicate: it builds the style URL unconditionally,
which is what makes core's `image.style_private` route deliver private derivatives at all.

So the two do not differ by accident of history. They differ on private files, in the direction where
only one of them is correct, and the incorrect one is the one that looks like the general case.

## What it costs

**A duplication that will keep being reported.** Two nearly identical public methods survive a plan
whose entire subject is collapsing four copies of one step into one. Any future scan, review or
static-analysis pass that ranks by similarity will surface this pair, and each time someone has to
arrive back here.

**The asymmetry stays asymmetric.** The URI entry point remains wrong for private files — it hands
back a raw stream URI — and this ADR is a decision *not to fix that as part of this plan*, because
changing what a caller receives is a behaviour change with no candidate behind it. The result is a
module where the same private file gets a working URL through one entry point and a broken string
through the other, now deliberately.

## Alternatives considered

**Collapse them and narrow `isExternalUri()`** so that only genuinely external URIs count as
external. Rejected as out of proportion: that predicate is consulted by the single-style render's
preprocess to decide whether a source can be styled at all, where treating a non-public stream as
unstyleable is load-bearing. Redefining it to fix a URL method would change which images get
derivatives across roughly thirty sites, inside a plan whose stated blast radius is "no behaviour
change at the public boundary".

**Collapse them and pass a flag** — delegate, with a parameter saying whether to honour the external
short-circuit. Rejected because it is the same two implementations with a worse signature: the caller
now has to know the answer to the question the split already answers, and a boolean parameter naming
an edge case is how the four failure contracts this plan removes came to exist.

**Fix the URI entry point instead** — make it build a style URL for any local stream and reserve the
short-circuit for real external URLs. Rejected for this plan only, and this is the alternative most
likely to win later: it is the correct end state, and it is a behaviour change that deserves its own
candidate, its own blast-radius paragraph, and its own release. The test this plan writes pins the
current answer, so that change becomes a deliberate edit to an assertion rather than an accident.

## Consequences

The private-file case is asserted in the module's kernel tests from now on: the entity entry point
answers an image-style URL, the URI entry point answers the raw URI. A future collapse fails that
test, which is the entire mechanism this ADR is choosing — the record exists so the failure is
legible, but the test is what stops the change.

Anyone fixing the URI entry point's private-file behaviour should expect to edit that assertion and
should say so out loud. That is the signal that the third alternative above is being taken, not that
a test broke.
