# 0015 — Every flush addresses a derivative directory by name, never by parsed style

**Status:** accepted · **Date:** 2026-08-29
**Context:** `neo_image` — the **style flush**, the **per-file flush** and the **directory style**
between them
**Issue:** jacerider/neo_image#12

**Decision.** Both flushes take **derivative directory** *names* from the **style scan** and never
the styles the **codec** parsed. The **per-file flush**, which needs a derivative's full uri inside
each directory rather than the directory itself, gets there through a **directory style**: an
`ImageStyle` carrying the name and the **format conversion**, handed to core's `buildUri()`. The
list is uniform — a well-formed **style id** is addressed the same way as a **rejected id** — so no
flush consults the grammar.

**Why it needs recording.** The rule is one a reader will meet as its consequence, not as itself. A
**directory style** is a style with no effects, in a module whose styles are made of effects, built
for a name that may be unparseable — it looks like a workaround, and the tempting simplification is
to iterate `getStyles()` and let the flush skip what the codec cannot read. That is what the
per-file flush did, and the damage was invisible: the parsed styles *are* every directory a healthy
site has, so the flush looks complete and only misses the directories nothing else can reach either.
A file deleted from such a directory leaves an orphan derivative pointing at a file the site no
longer has, and nothing but the admin button ever removes it. The refusal has to be a reading rule,
not a flushing rule: refusing a name protects what the module builds *next*, and has no business
deciding what it may delete. Anything this module wrote, it can remove.

**Rejected.**
- Iterate the parsed styles and fall back to a name-built style where the parse fails — the same
  answer through two code paths, one of which drags the codec, its throw and its warning back onto a
  path that runs on every file move.
- Join the derivative uri as a string instead of building a style — puts a second copy of core's
  scheme fallback and extension rules in this module, where a drift deletes nothing and reports
  success.
- Widen the grammar until nothing on disk is refused — inverts the direction: the grammar exists to
  refuse untrusted ids at the route, and loosening it to please a cleanup path re-opens what ADR
  0014's admission was careful to keep narrow.

**Cost.** A derivative uri is now computed for every directory on disk on every per-file flush,
including ones no style could render, so the flush does slightly more work than the styles justify —
memoised per request, it is one unsaved entity per directory. The two flushes stay separate
implementations of one rule, and the rule lives here rather than in a shared method, because one
deletes directories and the other deletes files inside them.
