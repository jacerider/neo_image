<?php

namespace Drupal\neo_image\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\neo_image\NeoImage;

/**
 * Plugin implementation of the 'neo_image_media' formatter.
 */
#[FieldFormatter(
  id: 'neo_image_media',
  label: new TranslatableMarkup('Neo | Image'),
  field_types: [
    'entity_reference',
  ]
)]
final class NeoImageMediaFormatter extends EntityReferenceFormatterBase {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

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
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, RendererInterface $renderer) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->renderer = $renderer;
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
      $container->get('renderer')
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
    ] + parent::defaultSettings();
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
        '@label' => $this->t('Thumbnail'),
      ]);
      foreach ($dimensionSummary as $sum) {
        $summary[] = '-- ' . $sum;
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $media_items = $this->getEntitiesToView($items, $langcode);
    $imageSettings = $this->getSetting('image');
    $imageDimensions = $imageSettings['dimensions'] ?? [];

    foreach ($media_items as $delta => $media) {
      $image = NeoImage::createFromEntity($media);
      $image->autoFromDimensions($imageDimensions);
      $elements[$delta] = $image->toRenderable();

      // Add cacheability of each item in the field.
      $this->renderer->addCacheableDependency($elements[$delta], $media);
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // This formatter is only available for entity types that reference
    // media items.
    return ($field_definition->getFieldStorageDefinition()->getSetting('target_type') == 'media');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
    return $entity->access('view', NULL, TRUE)
      ->andIf(parent::checkAccess($entity));
  }

}
