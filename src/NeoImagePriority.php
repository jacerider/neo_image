<?php

namespace Drupal\neo_image;

use Drupal\Component\Utility\Crypt;

/**
 * The **priority** a render may carry, and the **priority grant** offering it.
 *
 * A render carrying priority is the page's likely largest contentful paint:
 * its `<img>` loads eagerly at high fetch priority, and the page head preloads
 * the exact image each viewport will paint. A caller asks for it with the
 * `priority` option of either Twig render function, or with `#priority` on a
 * `neo_image` or `neo_image_style` render array.
 *
 * The **priority grant** is for a caller that knows which part of a page comes
 * first but not which image inside it will render first — a page builder
 * handing priority to its first component without editing that component's
 * template. It grants before rendering that part and withdraws after, and the
 * first neo_image render in between that has not declined priority claims it.
 *
 * Every rendered image reaches the claim through the theme layer, so a field
 * formatter's image claims a grant as a template's does. That also makes the
 * claim a render-time event: a render served from the render cache runs no
 * preprocess and claims nothing, which is why the caller that grants must
 * decide *where* to grant from something the cache cannot skip, and let its
 * own cache entry vary on that decision.
 */
final class NeoImagePriority {

  /**
   * The attributes a priority image's `<img>` carries.
   *
   * They replace whatever the caller set for the same names: a priority image
   * that stays `loading="lazy"` is not discovered until layout, which is the
   * delay priority exists to remove.
   */
  public const IMAGE_ATTRIBUTES = [
    'loading' => 'eager',
    'fetchpriority' => 'high',
    'decoding' => 'async',
  ];

  /**
   * Whether a grant is outstanding.
   *
   * @var bool
   */
  protected bool $granted = FALSE;

  /**
   * Offers priority to the next neo_image render that does not decline it.
   *
   * A second grant while one is outstanding changes nothing: there is one
   * offer, and the first render to claim it takes it.
   */
  public function grant(): void {
    $this->granted = TRUE;
  }

  /**
   * Withdraws an unclaimed grant.
   *
   * Called by whoever granted, once the part of the page it granted for has
   * rendered, so an offer nothing claimed cannot reach an image further down.
   *
   * @return bool
   *   TRUE when the grant was still outstanding — nothing claimed it — and
   *   FALSE when a render claimed it or none was made.
   */
  public function withdraw(): bool {
    $outstanding = $this->granted;
    $this->granted = FALSE;
    return $outstanding;
  }

  /**
   * Resolves the priority a render carries, claiming an outstanding grant.
   *
   * @param bool|null $requested
   *   What the render asked for. TRUE takes priority and also claims an
   *   outstanding grant, so the grant cannot hand a second image priority on
   *   the same page. FALSE declines it and leaves an outstanding grant for the
   *   next render. NULL, the default of every render, takes priority only by
   *   claiming a grant.
   *
   * @return bool
   *   Whether the render carries priority.
   */
  public function claim(?bool $requested = NULL): bool {
    if ($requested === FALSE) {
      return FALSE;
    }
    $granted = $this->granted;
    $this->granted = FALSE;
    return $requested === TRUE || $granted;
  }

  /**
   * Builds one preload link for an `html_head` attachment.
   *
   * @param string $href
   *   The URL the image element paints, exactly as it emits it.
   * @param string|null $media
   *   The media query the preload is limited to, or NULL for every viewport.
   *   It must be one of an **exclusive media** set, never a `<source>`'s own
   *   query copied — see `NeoImage::getPreloadMediaQueries()`.
   * @param string|null $type
   *   The MIME type a `<source>` declares, or NULL when the image declares
   *   none. An empty string is treated as none.
   *
   * @return array
   *   A pair for `#attached['html_head']`: the link element and its key. The
   *   key is derived from what the link fetches, so the same image rendered
   *   twice on a page is preloaded once.
   */
  public static function preloadLink(string $href, ?string $media = NULL, ?string $type = NULL): array {
    $attributes = [
      'rel' => 'preload',
      'as' => 'image',
      'href' => $href,
    ];
    if ($media !== NULL && $media !== '') {
      $attributes['media'] = $media;
    }
    if ($type !== NULL && $type !== '') {
      $attributes['type'] = $type;
    }
    $attributes['fetchpriority'] = 'high';
    return [
      [
        '#tag' => 'link',
        '#attributes' => $attributes,
      ],
      'neo_image_preload:' . Crypt::hashBase64($href . '|' . ($attributes['media'] ?? '')),
    ];
  }

}
