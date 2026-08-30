<?php

namespace Drupal\neo_image\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\neo\Plugin\Field\FieldFormatter\NeoEntityReferenceLinkTrait;
use Drupal\neo\Plugin\Field\FieldFormatter\NeoEntityReferenceSelectionTrait;
use Drupal\neo_image\NeoImage;
use Drupal\neo_image\NeoImageUtility;
use Psr\Log\LoggerInterface;

/**
 * Plugin implementation of the 'neo_image_image' formatter.
 */
class NeoImageBaseFormatter extends EntityReferenceFormatterBase {

  use NeoEntityReferenceLinkTrait;
  use NeoEntityReferenceSelectionTrait;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The logger channel named for this module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a NeoModalMediaFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel named for this module.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, RendererInterface $renderer, LoggerInterface $logger) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->renderer = $renderer;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('renderer'),
      $container->get('logger.channel.neo_image')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'image' => [
        'dimensions' => [
          'sm' => [
            'width' => 640,
            'height' => '',
          ],
        ],
      ],
    ] + self::linkDefaultSettings() + self::selectionDefaultSettings() + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   *
   * This has to be overridden because FileFormatterBase expects $item to be
   * of type \Drupal\file\Plugin\Field\FieldType\FileItem and calls
   * isDisplayed() which is not in FieldItemInterface.
   */
  protected function needsEntityLoad(EntityReferenceItem $item) {
    return !$item->hasNewEntity();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['image'] = [
      '#type' => 'neo_settings',
      '#title' => $this->t('Image Settings'),
      '#settings_id' => 'neo_image',
      '#open' => FALSE,
      '#default_value' => $this->getSetting('image'),
      '#theme_wrappers' => ['container'],
    ];

    $element += $this->linkSettingsForm($element, $form_state);
    $element += $this->selectionSettingsForm($element, $form_state);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $imageSettings = $this->getSetting('image');
    $imageDimensions = $imageSettings['dimensions'] ?? [];
    if ($dimensionSummary = NeoImage::summaryFromDimensions($imageDimensions)) {
      $summary[] = $this->t('<strong>@label:</strong>', [
        '@label' => $this->t('Dimensions'),
      ]);
      foreach ($dimensionSummary as $sum) {
        $summary[] = $sum;
      }
    }

    return array_merge($summary, $this->linkSettingsSummary(), $this->selectionSettingsSummary());
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $entities = $this->getEntitiesToView($items, $langcode);
    $imageSettings = $this->getSetting('image');
    $imageDimensions = $imageSettings['dimensions'] ?? [];
    // Every reference this render skips, named as the loop passes it. The
    // list is local to the call and drained after the loop, because the
    // diagnosis is about one field render: state that outlived the method is
    // how a second field's diagnosis gets silently swallowed.
    $skipped = [];

    foreach ($entities as $delta => $entity) {
      // Ask for the resolved file before building anything. This one check is
      // also the type narrowing: the entities are typed as plain entities, and
      // an entity that is neither a media nor a file has no file either — it is
      // skipped the same way rather than being handed to a factory whose
      // parameter it does not satisfy.
      if ((!$entity instanceof MediaInterface && !$entity instanceof FileInterface) || !NeoImageUtility::resolvedFile($entity)) {
        // Name what was skipped. A warning that only said an image was missing
        // would be the silence this replaces with extra steps. The loop only
        // collects; the report is filed once, after it.
        $skipped[] = $this->describeSkippedReference($entity);
        continue;
      }

      // No blanket catch: the one predictable failure is guarded above, and
      // anything still thrown from here is a bug that a swallowed exception
      // would leave with neither a rendered image nor a trace.
      $image = NeoImage::createFromEntity($entity);
      $image->autoFromDimensions($imageDimensions);
      $elements[$delta] = $image->toRenderable();
      if ($url = $this->getLinkUrl($items->getEntity(), $entity)) {
        $elements[$delta]['#url'] = $url;
      }

      // Add cacheability of each item in the field. This is now a merge of
      // something the render already declared — the factory attaches the
      // subject cacheability, the subject entity's own metadata merged with
      // its resolved file's. It stays anyway: deleting it would make this
      // formatter's correctness depend on the factory having done the work,
      // which is the coupling being removed in the other direction. The
      // resolved file's tag is the half this line never declared and cannot.
      $this->renderer->addCacheableDependency($elements[$delta], $entity);
    }

    // One record for the whole field render rather than one per delta. A
    // field with twelve unrenderable references filed twelve near-identical
    // records that arrived together, each describing a condition constant for
    // the field, which is one diagnosis repeated until the interesting
    // entries around it are pushed off the page. Severity does not move with
    // it: both conditions the guard above can see are faults — a target that
    // is neither a media nor a file is a misconfigured display, a media with
    // no resolved file is a data fault — and the entity type in each fragment
    // already says which one applied, so one message at one severity carries
    // the whole distinction without a second string to keep in step.
    if ($skipped) {
      $this->logger->warning('No image was rendered for @skipped_count of the references in @field_name: @references. A reference is skipped when its target is neither a media nor a file, or when it resolves to no file.', [
        '@skipped_count' => (string) count($skipped),
        '@field_name' => $items->getName(),
        '@references' => implode(', ', $skipped),
      ]);
    }

    return $elements;
  }

  /**
   * Names one skipped reference for the record, never answering NULL.
   *
   * `{entity type}:{id}`, with the label parenthesised where there is one:
   * machine-readable first, human-readable second, and it degrades cleanly
   * when either half is missing.
   *
   * The composition is the point. `FormattableMarkup` hands every placeholder
   * value to `Html::escape()`, whose parameter is a non-nullable `string`, so
   * a NULL is a `TypeError` at the moment the stored entry is rendered — which
   * is to say when a site owner opens the report to read the warning. This is
   * the same guard the toolkit-unsupported warning in `neo_image.module`
   * applies, written for the same reason.
   *
   * Both NULL values are reachable here. An autocreated reference that has not
   * been saved has no id — `prepareView()` marks such an item loaded on
   * `hasNewEntity()`, so it arrives in this loop — and `new` states the only
   * fact that can produce it rather than hiding it. A label is absent whenever
   * the referenced entity's label field is empty, which this branch admits by
   * construction because it is the branch for entities of every type; the
   * parenthetical is dropped rather than filled with an invented string where
   * a reader expects an editor's words.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The reference that was skipped.
   *
   * @return string
   *   The fragment naming it.
   */
  protected function describeSkippedReference(EntityInterface $entity): string {
    $fragment = $entity->getEntityTypeId() . ':' . ($entity->id() ?? 'new');
    $label = (string) ($entity->label() ?? '');
    return $label === '' ? $fragment : $fragment . ' (' . $label . ')';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntitiesToView(EntityReferenceFieldItemListInterface $items, $langcode) {
    $entities = parent::getEntitiesToView($items, $langcode);
    $entities = $this->filterSelectionEntities($entities);
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
    return $entity->access('view', NULL, TRUE)
      ->andIf(parent::checkAccess($entity));
  }

}
