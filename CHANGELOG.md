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
