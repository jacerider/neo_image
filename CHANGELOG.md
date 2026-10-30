# Changelog

## Images can be marked as the page's largest contentful paint

**New `priority` option.** Pass `priority: true` beside the dimensions of
`neo_image()` or `neo_image_style()` — or set `#priority` on either render
array from PHP — for the image most likely to be the page's largest contentful
paint, such as a hero. Its `<img>` loads eagerly with `fetchpriority="high"`
and `decoding="async"`, and the page head preloads exactly the image each
viewport paints: one link per `<source>` of a `<picture>` plus one for its
`<img>`, each limited to the viewports where that image wins, so the browser
downloads one image, early. The links are attached rather than printed, so a
render-cached page keeps them.

Without the option, or with `priority: false`, the markup is unchanged.

For code that knows which part of a page comes first but not which image in it
renders first, the `neo_image.priority` service can grant priority instead: the
next neo_image render that does not pass `priority: false` takes it. Withdraw
the grant once that part has rendered. See the README.

## 1.0.38 — 2026-09-28

- A file path lifted out of a public-files web path is URL-decoded, so a file
  whose name has encoded characters resolves.

## 1.0.37 — 2026-09-28

- An SVG rendered through a core image style gets the size the style would
  give it.
- The URL entry points answer an SVG's own file URL.

## 1.0.36 — 2026-08-31

An audit release:

- Every rendered image has an `alt` attribute, and a media item's authored alt
  is used by both the single-style and the responsive render.
- A style id the codec cannot round-trip is refused, and each consumer reacts
  to the refusal. The bare prefix is the identity style, and setters store
  only values the id grammar can carry.
- One image style is built per set of parameters, and a directory style for
  every derivative directory.
- Flushing one file reaches every derivative directory, needs a flushable name
  before any style is deleted, and scans the style directories once.
- Both renders declare their subject's cacheability.
- The site's configured public path is read in one rewrite. Two rewrites that
  never fired, and a dead WebP source branch, are gone.
- A reference that resolves to no file is logged and skipped, and the Twig URL
  function always answers a string.
- The formatter files one log record per field render, and a subclass can
  construct it without the logger. Hooks log to the module's own channel.
- Every Twig parameter and return type is documented.

## 1.0.35 — 2026-08-18

- Coding standard pass.

## 1.0.34 — 2026-08-17

- Configuration schema for the `neo_settings` variation settings type.

## 1.0.33 — 2026-08-17

- `NeoImage::toRenderable()` no longer swaps `#alt` and `#title`.

## 1.0.32 — 2026-07-15

- A source that is not a derivative uses the style's declared dimensions
  instead of a placeholder.

## 1.0.31 — 2026-06-10

- The warning for an image style without a label no longer fails on `NULL`.

## Releases before 1.0.31 are not recorded

This file was started in October 2026. The releases from `1.0.31` on were
recorded afterwards from their commits; earlier ones are in the git history
only.
