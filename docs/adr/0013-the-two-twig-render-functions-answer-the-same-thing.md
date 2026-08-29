# 0013 — `neo_image`'s two Twig render functions answer the same thing, and the options choose the shape

**Status:** accepted
**Date:** 2026-08-28
**Context:** `neo_image` — the **render dispatch** behind `neo_image()` and `neo_image_style()`, and the
**breakpoint options** that decide which shape a template gets
**Plan:** `docs/plans/neo-image-twig-surface/`

## Decision

`neo_image()` and `neo_image_style()` stay **exact synonyms**. Both reach one **render dispatch**, the
dispatch reads the options and nothing else, and neither function name narrows what a template can ask
for. After this plan they are two one-line public methods with identical bodies, and that is the
intended end state rather than a step towards separating them.

The names survive because they are the registered contract of every call site in the fleet, not because they
mean different things.

## Why this needs recording

The equivalence is invisible today and will look like a defect the moment it is visible. Right now the
two functions delegate to each other on complementary conditions — one hands over when **breakpoint
options** are absent, the other when they are present — so a reader has to hold both bodies in mind to
see that every argument reaches the same place. This plan makes that explicit, and the result is two
public methods that differ only in name.

Every future reader will then reach for one of two obvious edits: collapse them into one function, or
make each name mean its own shape — `neo_image()` responsive, `neo_image_style()` single-style, as the
names plainly say and as the module's own README, the Alchemist editor's per-prop Twig hints and this
site's component skill documentation all describe them. The second is the dangerous one, it is the one
that looks like a bug fix, and it is wrong.

It is wrong because the dispatch is load-bearing in both directions. `neo_alchemist`'s Media Image Size
value plugin answers **breakpoint options** and its documented template line feeds them to
`neo_image_style()`; the image-size shape answers effect-keyed options through the same line. One Twig
call in one template therefore receives either shape depending on which value plugin a site builder
attached, and it renders correctly today because the function does not care. Narrowing
`neo_image_style()` to single-style renders would turn every such placement from a `<picture>` into one
`<img>`, silently, on any site that uses that plugin — and this site cannot see it happen, because no
component here currently has that plugin attached.

## What it costs

**A duplication that will keep being reported.** Two public statics with identical bodies is the
clearest possible signal to a similarity scan, a review or a static-analysis pass, and each one will
surface the pair. That is the second neo_image ADR in this position, after 0011, and the module now
carries two deliberate near-duplicates for two unrelated reasons.

**The names stay misleading.** `neo_image_style()` reads as "render one image style" and does not mean
that. A template author who reasons from the name will be right about the common case and wrong about
what the function will accept, and no amount of docblock fixes a name. This ADR accepts that rather
than fixing it, because the fix costs a fleet-wide markup change.

**The dispatch is now the only place the shape is decided.** Anything that wants a genuinely
single-style render has no way to demand one — passing effect-keyed options is the only way to get one,
and a value plugin upstream can change the options without the template knowing.

## Alternatives considered

**Make each name mean its shape.** Rejected — it is the change this ADR exists to stop. It is a
behaviour change to rendered markup across roughly thirty sites, it fires only where a particular value
plugin is attached, and it produces no error anywhere: the affected pages keep returning 200 with a
smaller, wrong image. If it is ever taken it needs its own candidate, its own blast-radius paragraph and
a fleet survey of which sites attach that plugin, not a tidy-up inside a typing plan.

**Register both Twig names against one method and delete the other.** Rejected as a public-API removal
bought for nothing. Both methods are `public static` on a package shared by roughly thirty sites; no PHP
caller in this site uses either, but the site cannot speak for the fleet, and the saving is one
delegating line. Two thin methods also keep each registered name a place to document, which is where the
difference between the two — which is none — is best stated.

**Deprecate `neo_image()` in favour of `neo_image_style()`.** Rejected because it picks the more
misleading of the two names as the survivor and puts a rename in front of every call site in the fleet and the
Alchemist editor's generated Twig hints, to remove a synonym that costs one line.

## Consequences

The equivalence is asserted in the module's kernel tests from now on: for every combination of subject
and option shape, the two functions answer the same render array. Anyone separating them fails that
test, which is the mechanism this ADR is choosing — the record exists so the failure is legible, but the
test is what stops the change.

Anyone who does intend to separate them should expect to edit that assertion, to say so out loud, and to
answer which sites attach a value plugin that produces **breakpoint options** first.
