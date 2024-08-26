<?php

namespace Drupal\neo_image\Settings;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\neo_image\NeoImage;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\NeoImageStyleManager;
use Drupal\neo_settings\Plugin\SettingsBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings.
 *
 * @Settings(
 *   id = "neo_image",
 *   label = @Translation("Neo Image"),
 *   config_name = "neo_image.settings",
 *   menu_title = @Translation("Image"),
 *   route = "/admin/config/neo/neo-image",
 *   admin_permission = "administer neo_image",
 *   variation_allow = false,
 *   variation_conditions = false,
 *   variation_ordering = false,
 * )
 */
final class ImageSettings extends SettingsBase {

  /**
   * The style manager.
   *
   * @var \Drupal\neo_image\NeoImageStyleManager
   */
  protected NeoImageStyleManager $styleManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    FormBuilderInterface $form_builder,
    NeoImageStyleManager $style_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger, $form_builder);
    $this->styleManager = $style_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('form_builder'),
      $container->get('neo_image.style_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFormConfig() {
    return [
      'breakpoints' => ['sm', 'md', 'lg', 'xl', '2xl'],
    ] + parent::defaultFormConfig();
  }

  /**
   * {@inheritdoc}
   *
   * Instance settings are settings that are set both in the base form and the
   * variation form. They are editable in both forms and the values are merged
   * together.
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $sizeOptions = [
      '' => $this->t('- Custom -'),
    ];
    $sizeOptionsByDimension = [];
    $styles = $this->styleManager->getStyles(['f', 's']);
    foreach ($styles as $id => $style) {
      if ($style->getEffectCount() !== 1) {
        continue;
      }
      $sizeOptions[$id] = $style->label();
      $width = $style->getWidth();
      $height = $style->getHeight();
      $parts = explode('--', $id);
      $sizeOptionsByDimension[$parts[1]] = $id;
    }

    $form['dimensions'] = [
      '#type' => 'table',
      '#header' => [
        'title' => $this->t('Image Size'),
        'style' => $this->t('Style'),
        'width' => $this->t('Width'),
        'height' => $this->t('Height'),
      ],
    ];

    $breakpoints = array_intersect_key(NeoImage::getBreakpoints(), array_flip($this->getFormConfigValue('breakpoints')));
    foreach ($breakpoints as $size => $breakpoint) {
      $width = $this->getValue(['dimensions', $size, 'width']);
      $height = $this->getValue(['dimensions', $size, 'height']);
      $dimentionKey = [];
      if ($width) {
        $dimentionKey[] = 'w-' . $width;
      }
      if ($height) {
        $dimentionKey[] = 'h-' . $height;
      }
      $dimentionKey = implode('_', $dimentionKey);
      $style = $sizeOptionsByDimension[$dimentionKey] ?? NULL;

      if (count($breakpoints) === 1) {
        unset($form['dimensions']['#header']['title']);
      }
      else {
        $form['dimensions'][$size]['title']['#markup'] = $breakpoint['label'] . '<br><small>' . $breakpoint['mediaQuery'] . '</small>';
      }
      $form['dimensions'][$size]['style'] = [
        '#type' => 'select',
        '#options' => $sizeOptions,
        '#default_value' => $style,
      ];

      $form['dimensions'][$size]['width'] = [
        '#type' => 'number',
        '#default_value' => $width,
        '#min' => 0,
        '#size' => 4,
        '#field_suffix' => 'px',
        '#states' => [
          'invisible' => [
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][style]' . '"]' => ['!value' => ''],
          ],
        ],
      ];
      $form['dimensions'][$size]['height'] = [
        '#type' => 'number',
        '#default_value' => $height,
        '#min' => 0,
        '#size' => 4,
        '#field_suffix' => 'px',
        '#states' => [
          'invisible' => [
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][style]' . '"]' => ['!value' => ''],
          ],
        ],
      ];
    }

    $form['flush'] = [
      '#type' => 'submit',
      '#value' => $this->t('Flush Image Styles'),
      '#submit' => [[$this, 'flushImageStyles']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateForm(array $form, FormStateInterface $form_state) {
    foreach ($form_state->getValue(['dimensions']) as $size => $config) {
      if (!empty($config['style'])) {
        $neoImageStyle = new NeoImageStyle();
        $neoImageStyle->setParameters($neoImageStyle->convertIdToParams($config['style']));
        $form_state->setValue(['dimensions', $size, 'width'], $neoImageStyle->getWidth() ?? '');
        $form_state->setValue(['dimensions', $size, 'height'], $neoImageStyle->getHeight() ?? '');
      }
      $form_state->unsetValue(['dimensions', $size, 'style']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function flushImageStyles(array &$form, FormStateInterface $form_state) {
    foreach ($this->styleManager->getStyles() as $name => $style) {
      $this->styleManager->flushStyle($name);
    }
    // $this->styleManager->flushStyles();
    // $this->messenger()->addMessage($this->t('Image styles flushed.'));
  }

}
