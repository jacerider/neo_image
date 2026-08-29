<?php

namespace Drupal\neo_image\Settings;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\neo_image\NeoImage;
use Drupal\neo_image\NeoImageStyleManager;
use Drupal\neo_settings\Plugin\SettingsBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings.
 *
 * @Settings(
 *   id = "neo_image",
 *   label = @Translation("Image"),
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
   *
   * The style manager is required rather than optional, which phpstan's plugin
   * rule objects to. It is required because there is no honest fallback: the
   * only alternative is a container lookup in the body, which is the very thing
   * `create()` exists to avoid and which phpcs would then object to in turn.
   * Every instance of this plugin comes from `create()`.
   *
   * @phpstan-ignore parameter.notOptional
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    FormBuilderInterface $form_builder,
    NeoImageStyleManager $style_manager,
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
    $styles = $this->styleManager->getStyles(['f', 's', 'e']);
    foreach ($styles as $id => $style) {
      if ($style->getEffectCount() !== 1) {
        continue;
      }
      $sizeOptions[$id] = $style->label();
      $parts = explode('--', $id);
      $key = $parts[1];
      if ($style->isExact()) {
        $key = 'e_' . $key;
      }
      $sizeOptionsByDimension[$key] = $id;
    }

    natsort($sizeOptions);

    $form['dimensions'] = [
      '#type' => 'table',
      '#header' => [
        'title' => $this->t('Image Size'),
        'style' => $this->t('Style'),
        'settings' => $this->t('Settings'),
      ],
    ];

    $breakpoints = array_intersect_key(NeoImage::getBreakpoints(), array_flip($this->getFormConfigValue('breakpoints')));
    foreach ($breakpoints as $size => $breakpoint) {
      $width = $this->getValue(['dimensions', $size, 'width']);
      $height = $this->getValue(['dimensions', $size, 'height']);
      $exact = $this->getValue(['dimensions', $size, 'exact'], $width && $height);
      $bg = $exact ? $this->getValue(['dimensions', $size, 'bg']) : NULL;
      $dimensionKey = [];
      if ($exact) {
        $dimensionKey[] = 'e';
      }
      if ($width) {
        $dimensionKey[] = 'w-' . $width;
      }
      if ($height) {
        $dimensionKey[] = 'h-' . $height;
      }
      if ($bg) {
        $dimensionKey[] = 'bg-' . $bg;
      }
      $dimensionKey = implode('_', $dimensionKey);
      $style = $sizeOptionsByDimension[$dimensionKey] ?? NULL;

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

      $form['dimensions'][$size]['settings'] = [
        '#type' => 'container',
        '#neo_size' => 'xs',
      ];

      $form['dimensions'][$size]['settings']['width'] = [
        '#type' => 'number',
        '#title' => $this->t('Width'),
        '#default_value' => $width,
        '#min' => 0,
        '#size' => 4,
        '#field_suffix' => 'px',
        '#states' => [
          'invisible' => [
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][style]"]' => ['!value' => ''],
          ],
        ],
      ];
      $form['dimensions'][$size]['settings']['height'] = [
        '#type' => 'number',
        '#title' => $this->t('Height'),
        '#default_value' => $height,
        '#min' => 0,
        '#size' => 4,
        '#field_suffix' => 'px',
        '#states' => [
          'invisible' => [
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][style]"]' => ['!value' => ''],
          ],
        ],
      ];
      $form['dimensions'][$size]['settings']['exact'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Exact'),
        '#default_value' => $exact,
        '#states' => [
          'visible' => [
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][style]"]' => ['value' => ''],
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][settings][width]"]' => ['filled' => TRUE],
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][settings][height]"]' => ['filled' => TRUE],
          ],
        ],
      ];
      $form['dimensions'][$size]['settings']['bg'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Background'),
        '#description' => $this->t('A hex color value (e.g. #FF0000). Leave empty for a transparent canvas color.'),
        '#default_value' => $bg,
        '#size' => 10,
        '#maxlength' => 7,
        '#placeholder' => 'FFFFFF',
        '#field_prefix' => '#',
        '#states' => [
          'visible' => [
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][style]"]' => ['value' => ''],
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][settings][width]"]' => ['filled' => TRUE],
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][settings][height]"]' => ['filled' => TRUE],
            ':input[name="' . $form['#input_selector'] . '[dimensions][' . $size . '][settings][exact]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildBaseSettingsForm(array $form, FormStateInterface $form_state) {
    $form['flush'] = [
      '#type' => 'submit',
      '#value' => $this->t('Flush Image Styles'),
      '#submit' => [[$this, 'flushImageStyles']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * This form is no longer a **codec** consumer. It used to re-parse the id its
   * own select had just handed it, which was both a third call site into the
   * **id grammar** and — once the grammar started refusing — a place a tampered
   * submission could throw inside an admin form. It asks the **style manager**
   * for the style it already parsed instead, so a value the manager does not
   * know is reported on the form rather than fataling it.
   */
  protected function validateForm(array $form, FormStateInterface $form_state) {
    $styles = $this->styleManager->getStyles();
    foreach ($form_state->getValue(['dimensions']) as $size => $config) {
      $config += $config['settings'];
      unset($config['settings']);
      if (empty($config['style']) && empty($config['width']) && empty($config['height'])) {
        $form_state->unsetValue(['dimensions', $size]);
        continue;
      }
      $form_state->setValue(['dimensions', $size], $config);
      if (!empty($config['style'])) {
        if (!isset($styles[$config['style']])) {
          // The select's options come from the manager, so a value it does not
          // know arrived by tampering or from a directory the manager skipped.
          $form_state->setError($form['dimensions'][$size]['style'], $this->t('%style is not an image size this site can build.', [
            '%style' => $config['style'],
          ]));
          continue;
        }
        $neoImageStyle = $styles[$config['style']];
        $form_state->setValue(['dimensions', $size, 'width'], $neoImageStyle->getWidth() ?? '');
        $form_state->setValue(['dimensions', $size, 'height'], $neoImageStyle->getHeight() ?? '');
        $form_state->setValue(['dimensions', $size, 'exact'], $neoImageStyle->isExact());
        $form_state->setValue(['dimensions', $size, 'bg'], $neoImageStyle->getBg());
      }
      elseif (empty($config['width']) || empty($config['height'])) {
        $form_state->setValue(['dimensions', $size, 'exact'], FALSE);
      }
      $form_state->unsetValue(['dimensions', $size, 'style']);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Directory names from disk, not styles the manager parsed. A directory the
   * **id grammar** refuses is skipped by `getStyles()`, so iterating styles
   * here would have made the junk this plan stops creating undeletable through
   * the one button a site owner has for it. This is that cleanup path.
   *
   * The whole list goes over in one call rather than one name at a time, so
   * the flush costs one directory listing and one writable-wrapper listing in
   * total. Looping here cost a wrapper listing per name — twenty-four of them
   * on this site — and the names and the styles already come from the same
   * **style scan**.
   */
  public function flushImageStyles(array &$form, FormStateInterface $form_state) {
    $this->styleManager->flushStyles($this->styleManager->getStyleNames());
  }

}
