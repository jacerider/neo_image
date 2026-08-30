# 0014 — The bare prefix is a legal style id, naming the identity style

**Status:** accepted · **Date:** 2026-08-29
**Context:** `neo_image` — the **id grammar** and the parse half of the **codec**
**Issue:** jacerider/neo_image#11

**Decision.** `neo-` alone is inside the **id grammar**: it parses to no parameters and names the
**identity style**, whose **built style** is the **format conversion** and nothing else. The
serialise half stays total, so the pair are inverses over the empty parameter set as over every
other. The admission is the whole id only — an empty *segment* (`neo-cs~`, `neo-~cs`) and an empty
property list (`neo-s--`) stay **rejected ids**.

**Why it needs recording.** The grammar was written days earlier with the opposite rule, and the
message it throws — *"names no effect"* — reads like a deliberate closure rather than an oversight;
a later reader, or a scan, will see an id that names nothing being admitted and take it for the hole
in the grammar. It is the reverse. The serialiser can produce this id and does: a style built with
no options serialises to it, `getImageStyleName()` cannot refuse without turning a bad parameter
into a white screen, and the name escapes through more doors than the URL **entry points** — any
caller reaching `getImageStyle()->buildUri()` writes a **derivative directory** called `neo-`. With
the grammar closed, every one of those is a permanent 404 plus a **style scan** warning per request.
The module had also already decided what the style *means*: an effect-less **built style** is the
format conversion alone, which is why it produces a file rather than a copy.

**Rejected.**
- Refuse `[]` in the serialiser — breaks the codec's own asymmetry, which exists because the
  serialise half runs on every render; a template's missing options would become a white screen.
- Give the URL entry points a zero-effect branch answering the source URL — closes one door and
  leaves `buildUri()` and the directories already on disk behind it, and gives the identity style a
  second meaning per entry point.
- Leave it refused and treat `neo-` as junk to be flushed — the id is producible by supported calls,
  so this makes the module's own output permanently unservable on every site that makes one.

**Cost.** The two halves of the module now answer an effect-less style differently, deliberately and
visibly: a URL builds the converted derivative, while `template_preprocess_neo_image_style()`'s
zero-effect branch emits the source untouched. Unifying them is a fleet-wide markup or URL change
and needs its own candidate. A `neo-` directory can now appear on disk as a listed style; it stays
out of the image settings select, which admits only single-effect styles.
`StyleIdCodecTest`'s round-trip case over the bare prefix and
`RejectedIdConsumerReactionsTest`'s param-converter case pin both directions.
