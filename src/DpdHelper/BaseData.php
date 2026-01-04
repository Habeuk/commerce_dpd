<?php

namespace Drupal\commerce_dpd\DpdHelper;

class BaseData {
  
  /**
   * Convertit récursivement stdClass en array.
   */
  public static function normalizeStdClassToArray(mixed $data): mixed {
    if ($data instanceof \stdClass) {
      $data = (array) $data;
    }
    if (is_array($data)) {
      foreach ($data as $key => $value) {
        $data[$key] = self::normalizeStdClassToArray($value);
      }
    }
    return $data;
  }
}