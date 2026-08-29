# 0011 — The two URL entry points stay separate, because "external" includes `private://`

**Status:** accepted · **Date:** 2026-08-27
**Context:** `neo_image` — the URI and entity URL **entry points** that both answer an image-style
URL, and the **resolved file** step this plan puts underneath them
**Issue:** jacerider/neo_image#3  ·  **Plan:** `neo-image-file-resolver` on wps

**Decision.** The entity URL **entry point** does not delegate to the URI URL **entry point**, even
though after the **resolved file** step lands only four lines separate them. They share the
**derivative ensure** step through a private helper and nothing else, and a kernel test pins the one
behaviour that differs. The URI entry point's private-file behaviour is deliberately not fixed here.

**Why it needs recording.** After this plan the entity method reads *resolve the file, take its URI,
ensure, build the URL* and the URI method *rewrite the path, ensure, build the URL*; collapsing the
first into the second is a two-line diff, and it is wrong. `NeoImageStyle::isExternalUri()` answers
TRUE for a genuinely external URL and for any URI not on the public stream, and a `private://` file
is the second. The URI entry point returns its argument unchanged when that predicate is TRUE, so a
delegating entity entry point would answer a raw `private://` string for every private file: not a
URL, not routable, not an image. The entity entry point never consults the predicate; it builds the
style URL unconditionally, which is what makes core's `image.style_private` route deliver private
derivatives at all. The two differ on private files, and the wrong one looks like the general case.

**Rejected.**
- Collapse them and narrow `isExternalUri()` to genuinely external URIs — out of proportion: the
  single-style render's preprocess relies on it to treat a non-public stream as unstyleable, so
  redefining it changes which images get derivatives across roughly thirty sites, inside a plan
  whose stated blast radius is "no behaviour change at the public boundary".
- Collapse them and pass a flag that honours the short-circuit — the same two implementations with
  a worse signature; a boolean naming an edge case is how the four failure contracts this plan
  removes came to exist.
- Fix the URI entry point to build a style URL for any local stream — rejected for this plan only,
  and the alternative most likely to win later: it is the correct end state, but a behaviour change
  that deserves its own candidate, its own blast-radius paragraph and its own release.

**Cost.** Two nearly identical public methods survive a plan whose subject is collapsing four copies
of one step into one, and every similarity scan will report the pair. The asymmetry stays: the same
private file gets a working URL through one entry point and a raw stream URI through the other, now
deliberately. The kernel test asserts the private-file case — the entity entry point answers an
image-style URL, the URI entry point answers the raw URI — so a collapse fails it. Anyone taking the
third alternative should expect to edit that assertion and say so out loud.
