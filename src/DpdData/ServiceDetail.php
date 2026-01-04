<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;
use Drupal\commerce_dpd\Dto\Attribute\DpdField;
use Drupal\commerce_dpd\Dto\Hydrator\DpdHydrator;

class ServiceDetail extends BaseData {
  #[DpdField(source: 'code', type: 'string')]
  public readonly string $code;
  #[DpdField(source: 'available', type: 'bool')]
  public readonly ?bool $available;
  #[DpdField(source: 'description', type: 'string')]
  public readonly string $description;
  
  private function __construct() {
  }
  
  public function getCode(): string {
    return $this->code;
  }
  
  public function isAvailable(): bool {
    return $this->available;
  }
  
  public function getDescription(): string {
    return $this->description;
  }
  
  public function toArray(): array {
    return get_object_vars($this);
  }
  
  public static function createFromDpdData(array|object $data, DpdHydrator $hydrator): self {
    return $hydrator->hydrate(ServiceDetail::class, $data);
  }
  
  public static function createCollectionFromDpdData(array $detailsData): array {
    $instances = [];
    $hydrator = new DpdHydrator();
    foreach ($detailsData as $key => $detailData) {
      $instances[$key] = self::createFromDpdData($detailData, $hydrator);
    }
    return $instances;
  }
}