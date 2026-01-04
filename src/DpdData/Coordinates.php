<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;

class Coordinates extends BaseData {
  private float $latitude;
  private float $longitude;
  private ?float $coordinateX;
  private ?float $coordinateY;
  private ?float $coordinateZ;
  
  public function __construct(float $latitude, float $longitude, ?float $coordinateX = null, ?float $coordinateY = null, ?float $coordinateZ = null) {
    $this->latitude = $latitude;
    $this->longitude = $longitude;
    $this->coordinateX = $coordinateX;
    $this->coordinateY = $coordinateY;
    $this->coordinateZ = $coordinateZ;
  }
  
  public function getLatitude(): float {
    return $this->latitude;
  }
  
  public function getLongitude(): float {
    return $this->longitude;
  }
  
  public function getCoordinateX(): ?float {
    return $this->coordinateX;
  }
  
  public function getCoordinateY(): ?float {
    return $this->coordinateY;
  }
  
  public function getCoordinateZ(): ?float {
    return $this->coordinateZ;
  }
  
  public function toArray(): array {
    return [
      'latitude' => $this->latitude,
      'longitude' => $this->longitude,
      'coordinate_x' => $this->coordinateX,
      'coordinate_y' => $this->coordinateY,
      'coordinate_z' => $this->coordinateZ
    ];
  }
  
  public static function createFromDpdData(array|object $dataRaw): self {
    $data = self::normalizeStdClassToArray($dataRaw);
    return new self((float) ($data['latitude'] ?? 0.0), (float) ($data['longitude'] ?? 0.0), isset($data['coordinateX']) ? (float) $data['coordinateX'] : null, isset($data['coordinateY']) ? (float) $data['coordinateY'] : null, isset(
      $data['coordinateZ']) ? (float) $data['coordinateZ'] : null);
  }
}