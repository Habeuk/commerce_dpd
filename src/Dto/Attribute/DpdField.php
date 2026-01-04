<?php

namespace Drupal\commerce_dpd\Dto\Attribute;

use Attribute;

/**
 * Attribute pour mapper les champs DPD aux propriétés PHP.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DpdField {
  
  public function __construct(public ?string $source = null, public ?string $type = null, public bool $required = false, public ?string $factory = null) {
  }
}