# CONTEXT — neo_image

Terms specific to Neo's image layer: image styles, the effects behind them, and the Twig and
URL surfaces that render them. One entry per term: what it IS, then the names not to use for it.

## Images (`neo_image`)

**Responsive render** — the `<picture>` a `NeoImage` builds: one `<source>` per neo breakpoint plus
the controlling `<img>` that carries the fallback. Reached from a template by `neo_image()` with
breakpoint keys, and from the module's two field formatters. _Avoid:_ "multi-breakpoint image", "the
picture path".

**Single-style render** — the one `<img>` a `NeoImageStyle` builds for a single set of effects.
Reached by `neo_image_style()`, and by the controlling image of a **responsive render**, so it is the
last layer every neo_image `<img>` passes through. _Avoid:_ "image style render", "non-responsive
image", "the flat path".

**Entity-sourced image** — a render whose subject is a media or file entity rather than a URI string.
Both renders accept either. _Avoid:_ "media image", "image entity".

**Authored alt** — the alt text, or the title, stored on the image field item a media's source
declares. An **entity-sourced image** falls back to it when the caller supplies none. A file entity
has none, and neither does a media whose source field is not an image field. _Avoid:_ "media alt",
"field alt", "default alt".

**Supplied alt** — the alt, or the title, a caller passes to a render. NULL and the empty string both
mean *unsupplied*, so both fall back to the **authored alt**; only a non-empty value overrides it.
_Avoid:_ "explicit alt", "override alt".

**Alt floor** — the guarantee that every `<img>` a neo_image render emits carries an `alt` attribute,
empty when nothing supplies one, so an image with no alt text is a decorative image rather than an
invalid one. _Avoid:_ "default alt", "empty alt", "alt fallback".

**Unstyleable source** — a URI no derivative can be built from: an external URL, an animated GIF, or
an SVG. A **single-style render** emits it as-is at the dimensions its style declares, because there
is no local raster for the toolkit to measure. _Avoid:_ "external image", "raw image", "the
passthrough".

**Public-path rewrite** — the step that turns a public-files web path into a stream URI before
anything else looks at it, reading the site's configured public files path rather than assuming the
default. Written once and reached from every **entry point** that accepts a URI string. Without it a
local image reads as an **unstyleable source**, because this module calls anything not on the public
stream external. _Avoid:_ "path normalisation", "uri conversion", "the str_replace".

**Entry point** — a public method callers reach neo_image through, of three kinds: a *factory*
answering a render object, a *render* answering a render array, and a *URL* answering a string. Each
kind has its own **failure contract**. _Avoid:_ "the public API", "the facade".

**Resolved file** — the file entity an **entity-sourced image** answers for rendering: a media's
thumbnail, or a file itself. Nothing, when there is neither. Every **entry point** asks the same
resolution step for it, and callers may ask it directly instead of catching. _Avoid:_ "source file",
"the thumbnail", "image file lookup".

**Failure contract** — what an **entry point** does when there is no **resolved file**: a factory
throws, a render answers an empty render array, a URL answers `'#'`. Stated in each method's
docblock, so a caller chooses between catching and branching without reading an implementation.
_Avoid:_ "error handling", "the null case", "the missing-file behaviour".

**Derivative ensure** — the optional step a URL **entry point** takes before handing out a
derivative's URL: build the derivative URI and write the derivative if it is not already on disk, so
the first request does not have to generate it. _Avoid:_ "eager derivative", "pre-generate", "the
ensure flag".

**Style id** — the `neo-…` string naming a dynamically generated image style: serialised from the
style's parameters, carried in the derivative URL, and parsed back by the param converter on core's
public and private image-style routes. Never a configured image style's machine name. _Avoid:_
"style name", "the neo slug", "image style id".

**Codec** — the pair of methods that convert a style's parameters to a **style id** and back. The
serialise half is total and never refuses; the parse half faces untrusted input and refuses anything
outside the **id grammar**. _Avoid:_ "the parser", "the serialiser" for the pair, "the converter"
(that is the param converter).

**Id grammar** — the closed vocabulary a **style id** must match: the `neo-` prefix, effects joined
by `~`, properties joined by `_`, and per effect the properties it allows and requires. It is read
off the setter methods rather than invented, so it accepts every id the module can produce and
nothing else. Declared once, with the effect keys and labels. _Avoid:_ "the format", "the id spec",
"validation rules".

**Round-trip safety** — the guarantee that parsing a **style id** and serialising it again answers
the same id, and that serialising a setter's parameters and parsing them back answers the same
parameters. It is what makes a **derivative directory**'s name the id that was requested. _Avoid:_
"idempotent", "stable id", "canonical id".

**Rejected id** — a string outside the **id grammar**. The **codec** throws on it, the param
converter answers nothing so the route 404s, and the style manager skips the directory and logs it.
_Avoid:_ "invalid style", "bad style name", "malformed style".

**Derivative directory** — the `styles/{style id}/` directory a generated derivative is written
under. Its name is a **style id**, which is why **round-trip safety** decides whether the static-file
shortcut can ever serve it and whether an image flush can ever delete it. _Avoid:_ "cache directory",
"the styles folder".

**Style scan** — the manager's listing of every writable stream wrapper's `styles/` directory, taken
once per request and read two ways: the `neo-` names on disk, and the styles parsed from them. It is
the module's entire registry of generated styles, because nothing stores one. _Avoid:_ "the directory
walk", "style discovery", "loading the styles".

**Built style** — the unsaved `ImageStyle` a neo style constructs from its parameters on demand: one
effect per parameter, plus the **format conversion** last. Remembered per style and keyed on its
**style id**, so it is built once per parameter set and shared read-only with every caller. _Avoid:_
"the image style" unqualified (that is a configured one), "the generated style".

**Style flush** — the removal of one or more **derivative directories** from every writable stream
wrapper, by name. It works on a name rather than a style, so a directory holding a **rejected id** is
still removable. _Avoid:_ "clearing the styles", "image flush" (that is core's, per configured style).

**Format conversion** — the core convert effect the module appends to every generated style, last and
unconditionally: AVIF on the core versions that provide that effect, WebP otherwise — a question
answered once from the running core version rather than per **built style**. It is why a derivative is
never in its source's format, and why a style carrying no effects at all still produces a file rather
than a copy. It needs no contributed module. _Avoid:_ "webp conversion" (the output is often AVIF),
"the webp effect", "webp support".

**WebP sidecar** — the `.webp` file the `webp` contributed module writes beside a derivative on a site
that installs it. neo_image neither creates, serves nor depends on one; its only dealing with a
sidecar is deleting it along with its derivative when an image style is flushed, and only where that
module is present. _Avoid:_ "webp derivative" (that is what **format conversion** produces), "the webp
file".

**Exact effect** — the module's own image effect that scales a source and lays it over a canvas of
exactly the requested width and height, filled with a background colour. It is the `e` of the **id
grammar**, and the only thing the **vendored effect fork** exists to support. _Avoid:_ "canvas
effect", "set canvas", "the exact size effect".

**Canvas rectangle** — the axis-aligned rectangle the **exact effect** hands the draw operation so it
can fill the canvas: four corner points at the inclusive bounds of a width and a height, plus the
bounding corners around them. Only its corner points are ever read. _Avoid:_ "positioned rectangle",
"the rectangle algebra".

**Vendored effect fork** — the rectangle, utility, effect and toolkit-operation classes `neo_image`
carries as a copy of the `image_effects` contributed module, taken because core removed the
positioned-rectangle helper that module built on. Module-private, and it supports the **exact effect**
and nothing else. See ADR 0012. _Avoid:_ "the image_effects code", "the copied classes", "the image
effects dependency".

**Breakpoint options** — an options array keyed by neo breakpoint (`sm`, `md`, `lg`, `xl`, `2xl`),
each key holding one dimension set. It is the shape a **responsive render** is built from, as opposed
to the effect-keyed shape a **single-style render** takes. Present or absent is the only question the
**render dispatch** asks of an options array. _Avoid:_ "sizes", "the responsive options", "dimensions".

**Render dispatch** — the step that decides whether a render is a **responsive render** or a
**single-style render**, by looking for **breakpoint options**. Both Twig render functions reach the
same dispatch, so the shape follows the options and never the function name. _Avoid:_ "the router",
"the delegation", "picking the renderer".

**Placeholder swap** — the step that rewrites the `{width}x{height}` in an external placeholder
image's URL to the largest dimensions the options ask for, so an example image previews at the size
the component will really render. It reads an options array of either shape and leaves every other
URI untouched. _Avoid:_ "placeholder resize", "the placehold.co hack".

