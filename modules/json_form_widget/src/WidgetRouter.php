<?php

namespace Drupal\json_form_widget;

use Drupal\Component\Uuid\Php;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\metastore\Service;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class WidgetRouter.
 */
class WidgetRouter implements ContainerInjectionInterface {

  /**
   * Uuid Service.
   *
   * @var \Drupal\Component\Uuid\Php
   */
  protected $uuidService;

  /**
   * StringHelper Service.
   *
   * @var \Drupal\json_form_widget\StringHelper
   */
  protected $stringHelper;

  /**
   * Metastore Service.
   *
   * @var \Drupal\metastore\Service
   */
  protected $metastore;

  /**
   * Inherited.
   *
   * @{inheritdocs}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('uuid'),
      $container->get('json_form.string_helper'),
      $container->get('dkan.metastore.service')
    );
  }

  /**
   * Constructor.
   *
   * @param Drupal\Component\Uuid\Php $uuid
   *   Uuid service.
   * @param StringHelper $string_helper
   *   String Helper service.
   * @param Drupal\metastore\Service $metastore
   *   Metastore service.
   */
  public function __construct(Php $uuid, StringHelper $string_helper, Service $metastore) {
    $this->uuidService = $uuid;
    $this->stringHelper = $string_helper;
    $this->metastore = $metastore;
  }

  /**
   * Helper function for getting element with configured widget.
   *
   * @param mixed $spec
   *   Object with spec for UI options.
   * @param array $element
   *   Element to apply UI options.
   *
   * @return array
   *   Element with widget configuration based on UI options.
   */
  public function getConfiguredWidget($spec, array $element) {
    if ($spec->widget == 'hidden') {
      $element['#access'] = FALSE;
    }
    elseif ($spec->widget == 'textarea') {
      $element['#type'] = 'textarea';
      $element = $this->getTextareaOptions($spec, $element);
    }
    elseif ($spec->widget == 'dkan_uuid') {
      $element['#default_value'] = !empty($element['#default_value']) ? $element['#default_value'] : $this->uuidService->generate();
      $element['#access'] = FALSE;
    }
    elseif ($spec->widget == 'upload_or_link') {
      $element = $this->handleUploadOrLink($element, $spec);
    }
    elseif ($spec->widget == 'list') {
      $element = $this->handleDropdown($element, $spec);
    }
    return $element;
  }

  /**
   * Flatten array elements and unset actions if hideActions is set.
   *
   * @param mixed $spec
   *   Object with spec for UI options.
   * @param array $element
   *   Element to apply UI options.
   *
   * @return array
   *   Return flattened element without actions.
   */
  public function flattenArrays($spec, array $element) {
    unset($element['actions']);
    $default_value = [];
    foreach ($element[$spec->child] as $key => $item) {
      $default_value = array_merge($default_value, $this->formatArrayDefaultValue($item));
      if ($key != 0) {
        unset($element[$spec->child][$key]);
      }
    }
    $element[$spec->child][0]['#default_value'] = $default_value;
    return $element;
  }

  /**
   * Format default values for arrays (flattened).
   */
  private function formatArrayDefaultValue($item) {
    if (!empty($item['#default_value'])) {
      return [$item['#default_value'] => $item['#default_value']];
    }
    return [];
  }

  /**
   * Handle configuration for dropdown elements.
   */
  public function handleDropdown($element, $spec) {
    if (isset($spec->titleProperty)) {
      if (isset($element[$spec->titleProperty])) {
        $element[$spec->titleProperty] = $this->getDropdownElement($element[$spec->titleProperty], $spec, $spec->titleProperty);
      }
    }
    else {
      $element = $this->getDropdownElement($element, $spec);
    }
    return $element;
  }

  /**
   * Helper function to build a dropdown element.
   *
   * @param mixed $element
   *   Element to apply UI options.
   * @param mixed $spec
   *   Object with spec for UI options.
   * @param mixed $titleProperty
   *   The title property name in which the dropdown should be added (or FALSE).
   *
   * @return array
   *   The dropdown element configured.
   */
  public function getDropdownElement($element, $spec, $titleProperty = FALSE) {
    $element['#type'] = $this->getSelectType($spec);
    $element['#options'] = $this->getDropdownOptions($spec->source, $titleProperty);
    if ($element['#type'] === 'select_or_other_select') {
      $element = $this->handleSelectOtherDefaultValue($element, $element['#options']);
      $element['#input_type'] = isset($spec->other_type) ? $spec->other_type : 'textfield';
    }
    if ($element['#type'] === 'select2') {
      $element['#multiple'] = isset($spec->multiple) ? TRUE : FALSE;
      $element['#autocreate'] = isset($spec->allowCreate) ? TRUE : FALSE;
    }
    if (isset($element['#autocreate'])) {
      $element['#target_type'] = 'node';
    }
    return $element;
  }

  /**
   * Helper function to get type of pick list.
   *
   * @param mixed $spec
   *   Object with spec for UI options.
   *
   * @return string
   *   The type of dropdown element to use.
   */
  public function getSelectType($spec) {
    if (isset($spec->type) && $spec->type === 'select_other') {
      return 'select_or_other_select';
    }
    elseif (isset($spec->type) && $spec->type === 'autocomplete') {
      return 'select2';
    }
    return 'select';
  }

  /**
   * Helper function to get options for dropdowns.
   *
   * @param mixed $source
   *   Source object from UI options.
   * @param mixed $titleProperty
   *   The title property name in which the dropdown should be added (or FALSE).
   *
   * @return array
   *   Array with options for the dropdown.
   */
  public function getDropdownOptions($source, $titleProperty = FALSE) {
    $options = [];
    if (isset($source->enum)) {
      $options = $this->stringHelper->getSelectOptions($source);
    }
    if (isset($source->metastoreSchema)) {
      $options = $this->getOptionsFromMetastore($source, $titleProperty);
    }
    return $options;
  }

  /**
   * Helper function to get options from metastore.
   *
   * @param mixed $source
   *   Source object from UI options.
   * @param mixed $titleProperty
   *   The title property name in which the dropdown should be added (or FALSE).
   *
   * @return array
   *   Array with options from metastore for the dropdown.
   */
  public function getOptionsFromMetastore($source, $titleProperty = FALSE) {
    $options = [];
    $values = $this->metastore->getAll($source->metastoreSchema);
    foreach ($values as $value) {
      if ($titleProperty) {
        $options[$value->data->{$titleProperty}] = $value->data->{$titleProperty};
      }
      else {
        $options[$value->data] = $value->data;
      }
    }
    return $options;
  }

  /**
   * Helper function to add the value of other to current list of options.
   */
  private function handleSelectOtherDefaultValue($element, $options) {
    if (!empty($element['#default_value'])) {
      if (!array_key_exists($element['#default_value'], $options)) {
        $element['#options'][$element['#default_value']] = $element['#default_value'];
      }
    }
    return $element;
  }

  /**
   * Handle configuration for upload_or_link elements.
   *
   * @param mixed $element
   *   Element to convert into upload_or_link.
   * @param mixed $spec
   *   Object with spec for UI options.
   *
   * @return array
   *   The element configured as upload_or_link.
   */
  public function handleUploadOrLink($element, $spec) {
    $element['#type'] = 'upload_or_link';
    $element['#upload_location'] = 'public://uploaded_resources';
    if (isset($element['#default_value'])) {
      $element['#uri'] = $element['#default_value'];
      unset($element['#default_value']);
    }
    if (isset($spec->extensions)) {
      $element['#upload_validators']['file_validate_extensions'][] = $spec->extensions;
    }
    return $element;
  }

  /**
   * Helper function for getting textarea options.
   */
  private function getTextareaOptions($spec, $element) {
    if (isset($spec->rows)) {
      $element['#rows'] = $spec->rows;
    }
    if (isset($spec->cols)) {
      $element['#cols'] = $spec->cols;
    }
    return $element;
  }

}
