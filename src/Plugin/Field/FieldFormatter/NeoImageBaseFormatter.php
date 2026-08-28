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

    foreach ($entities as $delta => $entity) {
      // Ask for the resolved file before building anything. This one check is
      // also the type narrowing: the entities are typed as plain entities, and
      // an entity that is neither a media nor a file has no file either — it is
      // skipped the same way rather than being handed to a factory whose
      // parameter it does not satisfy.
      if ((!$entity instanceof MediaInterface && !$entity instanceof FileInterface) || !NeoImageUtility::resolvedFile($entity)) {
        // Name what was skipped. A warning that only said an image was missing
        // would be the silence this replaces with extra steps.
        $this->logger->warning('No image was rendered for @entity_type @entity_id (%label) referenced by @field_name: it resolves to no file.', [
          '@entity_type' => $entity->getEntityTypeId(),
          '@entity_id' => $entity->id(),
          '%label' => $entity->label(),
          '@field_name' => $items->getName(),
        ]);
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

      // Add cacheability of each item in the field.
      $this->renderer->addCacheableDependency($elements[$delta], $entity);
    }

    return $elements;
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
