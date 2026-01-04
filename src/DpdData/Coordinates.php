<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;
use Drupal\commerce_dpd\Dto\Attribute\DpdField;
use Drupal\commerce_dpd\Dto\Hydrator\DpdHydrator;

/**
 * Représente les coordonnées géographiques d'un point relais DPD.
 */
class Coordinates extends BaseData {
  // === COORDONNÉES GÉOGRAPHIQUES ===
  #[DpdField(source: 'latitude', type: 'float', required: true)]
  public readonly float $latitude;
  #[DpdField(source: 'longitude', type: 'float', required: true)]
  public readonly float $longitude;
  
  // === COORDONNÉES CARTÉSIENNES (optionnelles) ===
  #[DpdField(source: 'coordinateX', type: 'float')]
  public readonly ?float $coordinateX;
  #[DpdField(source: 'coordinateY', type: 'float')]
  public readonly ?float $coordinateY;
  #[DpdField(source: 'coordinateZ', type: 'float')]
  public readonly ?float $coordinateZ;
  
  /**
   * Constructeur privé - utilisation via factory uniquement.
   */
  private function __construct() {
  }
  
  // Getters
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
  
  /**
   * Calcule la distance en km entre ces coordonnées et d'autres.
   */
  public function distanceTo(Coordinates $other): float {
    $earthRadius = 6371; // Rayon de la Terre en km
    
    $latFrom = deg2rad($this->latitude);
    $lonFrom = deg2rad($this->longitude);
    $latTo = deg2rad($other->getLatitude());
    $lonTo = deg2rad($other->getLongitude());
    
    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;
    
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
    
    return $angle * $earthRadius;
  }
  
  /**
   * Vérifie si les coordonnées sont valides.
   */
  public function isValid(): bool {
    return $this->latitude >= -90 && $this->latitude <= 90 && $this->longitude >= -180 && $this->longitude <= 180;
  }
  
  /**
   * Retourne les coordonnées au format WGS84 (standard GPS).
   */
  public function getWgs84(): string {
    $latDir = $this->latitude >= 0 ? 'N' : 'S';
    $lonDir = $this->longitude >= 0 ? 'E' : 'W';
    
    return sprintf('%.6f°%s, %.6f°%s', abs($this->latitude), $latDir, abs($this->longitude), $lonDir);
  }
  
  public function toArray(): array {
    return [
      'latitude' => $this->latitude,
      'longitude' => $this->longitude,
      'coordinate_x' => $this->coordinateX,
      'coordinate_y' => $this->coordinateY,
      'coordinate_z' => $this->coordinateZ,
      'wgs84' => $this->getWgs84(),
      'is_valid' => $this->isValid()
    ];
  }
  
  /**
   * Factory method : crée un Coordinates depuis les données brutes DPD.
   */
  public static function createFromDpdData(array|object $data, DpdHydrator $hydrator): self {
    return $hydrator->hydrate(Coordinates::class, $data);
  }
}