CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Twig
 * Responsive Image Generator
 * Image Style Generator
 * Image Style Naming Conventions


INTRODUCTION
------------

Improved responsive image handler along with other image enhancements.


REQUIREMENTS
------------

This module requires the webp contrib module.

- https://www.drupal.org/project/webp


INSTALLATION
------------

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/node/1897420 for further information.


TWIG
----

There are 3 twig helpers available:

## Single image

```twig

<!-- Auto will scale if only width or height is provided. Will focus crop if with and height are provided. -->
{{ neo_image_style(src, {auto: {width: 100}}, alt) }}
<!-- Exact will scale the image to width or height (whichever is greater) and then set the image to the width/height provided. -->
{{ neo_image_style(src, {exact: {width: 100, height: 100}}, alt) }}
<!-- Available size ops are: auto, size, scale, scaleCrop, crop, focal, focalWidth, exact. -->
{{ neo_image_style(src, {scale: {width: 100}}, alt) }}
{{ neo_image_style(src, {scaleCrop: {width: 100, height: 100}}, alt) }}
<!-- You can also provide a file or media entity directly -->
{{ neo_image_style(entity, {scale: {width: 100}}) }}

```

## Responsive image

```twig

<!-- Available size ops are: auto, size, scale, scaleCrop, crop, focal, focalWidth, exact. -->
{{ neo_image(src, {sm: {op: 'exact', width: 100, height: 100}, md: {op: 'exact', width: 200, height: 200}}, alt) }}
<!-- You can also provide a file or media entity directly -->
{{ neo_image(entity, {sm: {op: 'exact', width: 100, height: 100}, md: {op: 'exact', width: 200, height: 200}}, alt) }}

```


RESPONSIVE IMAGE GENERATOR
---------

Build a picture element that provides breakpoint media query images along with
webp support.

## From Media or File Entity

```php
/** @var \Drupal\media\MediaInterface $entity */
$entity = \Drupal::entityTypeManager()->getStorage('media')->load(1);
$neoImage = NeoImage::createFromEntity($entity);
$neoImage->getSm()->scaleCrop(200, 200);
$neoImage->getMd()->crop(400, 300, 'left-top');
$neoImage->getLg()->scale(600);
// The auto() method will use 'focal_point_scale_and_crop' when width and height
// are provided, and 'image_scale' when width or height are provided.
$neoImage->getXl()->auto(1400, 800);
// The focal() method uses the focal_point module to perserve the focused area
// with the crop.
$neoImage->get2Xl()->focal(1600, 1000);
$build = $neoImage->toRenderable();
```

IMAGE STYLE GENERATOR
---------------------

Build a dynamic image style that will utilize on-demand effects.

```php
/** @var \Drupal\media\MediaInterface $entity */
$entity = \Drupal::entityTypeManager()->getStorage('media')->load(1);
$neoImageStyle = new NeoImageStyle();
$neoImageStyle->focal(300, 300);
$neoImageStyle->toRenderableFromEntity($entity, 'Alt Text', 'Title Text');

$uri = 'public://image/image.png';
$neoImageStyle = new NeoImageStyle();
$neoImageStyle->focal(300, 300);
$neoImageStyle->toRenderableFromUri($uri, 'Alt Text', 'Title Text');
```

IMAGE STYLE NAMING CONVENTIONS
------------------------------

Image styles are automatically created based on the effects added to a given
style. The style names are such that they are a short as possible and URL-safe.

## Image Effect Conversion

| Shortcode | Image Effect | Properties |
| -------- | ------- | ------- |
| r | image_resize | [w*, h*](#property-conversion) |
| s | image_scale | [w, h](#property-conversion) |
| c | image_crop | [w*, h*, a*](#property-conversion) |
| cs | image_crop_sides | |
| sc | image_scale_and_crop | [w*, h*, a*](#property-conversion) |
| f | focal_point_scale_and_crop | [w*, h*](#property-conversion) |
| fw | focal_point_crop_by_width | [w*](#property-conversion) |
| e | exact | [w, h, a*](#property-conversion) |

## Property Conversion

| Shortcode | Property | Value |
| -------- | ------- | ------- |
| w | width | number |
| h | height | number |
| a | anchor | [Anchor Conversion](#anchor-conversion) |

## Anchor Conversion

| Shortcode | Anchor Position |
| -------- | ------- |
| lt | left-top |
| ct | center-top |
| rt | right-top |
| l | left-center |
| c | center-center |
| r | right-center |
| lb | left-bottom |
| cb | center-bottom |
| rb | right-bottom |
