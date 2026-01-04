<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;
use Drupal\commerce_dpd\Dto\Attribute\DpdField;
use Drupal\commerce_dpd\Dto\Hydrator\DpdHydrator;

/**
 * Représente un service disponible dans un point relais DPD.
 */
class Service extends BaseData {
  // === CODE SERVICE ===
  #[DpdField(source: 'code', type: 'string', required: true)]
  public readonly string $code;
  
  // === DISPONIBILITÉ ===
  #[DpdField(source: 'available', type: 'bool', required: true)]
  public readonly bool $available;
  
  // === DESCRIPTION ===
  #[DpdField(source: 'description', type: 'string')]
  public readonly string $description;
  
  // === DÉTAILS ===
  #[DpdField(source: 'serviceDetail', factory: ServiceDetail::class . '::createCollectionFromDpdData')]
  public readonly ?array $details;
  
  /**
   * Constructeur privé - utilisation via factory uniquement.
   */
  private function __construct() {
  }
  
  // Getters
  public function getCode(): string {
    return $this->code;
  }
  
  public function isAvailable(): bool {
    return $this->available;
  }
  
  public function getDescription(): string {
    return $this->description;
  }
  
  public function getDetails(): array {
    return $this->details;
  }
  
  /**
   * Vérifie si le service a des détails spécifiques.
   */
  public function hasDetails(): bool {
    return !empty($this->details);
  }
  
  /**
   * Trouve un détail spécifique par son code.
   */
  public function findDetailByCode(string $detailCode): ?ServiceDetail {
    foreach ($this->details as $detail) {
      if ($detail->getCode() === $detailCode) {
        return $detail;
      }
    }
    return null;
  }
  
  /**
   * Filtre les détails par disponibilité.
   *
   * @return ServiceDetail[]
   */
  public function getAvailableDetails(): array {
    return array_filter($this->details, fn ($detail) => $detail->isAvailable());
  }
  
  /**
   * Retourne les codes de tous les détails.
   */
  public function getDetailCodes(): array {
    return array_map(fn ($detail) => $detail->getCode(), $this->details);
  }
  
  /**
   * Vérifie si un détail spécifique est disponible.
   */
  public function hasDetailAvailable(string $detailCode): bool {
    $detail = $this->findDetailByCode($detailCode);
    return $detail !== null && $detail->isAvailable();
  }
  
  public function toArray(): array {
    return [
      'code' => $this->code,
      'available' => $this->available,
      'description' => $this->description,
      'details' => array_map(fn ($d) => $d->toArray(), $this->details),
      'has_details' => $this->hasDetails(),
      'detail_codes' => $this->getDetailCodes()
    ];
  }
  
  /**
   * Factory method : crée un Service depuis les données brutes DPD.
   */
  public static function createFromDpdData(array|object $data, DpdHydrator $hydrator): self {
    return $hydrator->hydrate(Service::class, $data);
  }
  
  public static function createCollectionFromDpdData(array $servicesData): array {
    $instances = [];
    $hydrator = new DpdHydrator();
    foreach ($servicesData as $key => $serviceData) {
      $instances[$key] = self::createFromDpdData($serviceData, $hydrator);
    }
    return $instances;
  }
  
  /**
   * Crée une collection depuis les données brutes DPD.
   *
   * @param array|object $servicesData
   * @return Service[]
   */
  public static function createCollectionFromDpdData0(array|object $servicesData): array {
    $instances = [];
    $hydrator = new DpdHydrator();
    
    // Normaliser les données
    $data = self::normalizeStdClassToArray($servicesData);
    
    if (empty($data)) {
      return [];
    }
    
    // Structure DPD : peut être directement un tableau de services
    // ou un objet avec une propriété "service"
    if (isset($data['service'])) {
      $servicesList = $data['service'];
    }
    else {
      $servicesList = $data;
    }
    
    // S'assurer que c'est un tableau
    if (!is_array($servicesList) || isset($servicesList['code'])) {
      $servicesList = [
        $servicesList
      ];
    }
    
    foreach ($servicesList as $serviceData) {
      try {
        $instances[] = self::createFromDpdData($serviceData, $hydrator);
      }
      catch (\Exception $e) {
        \Drupal::logger('commerce_dpd')->warning('Error creating Service: @error', [
          '@error' => $e->getMessage()
        ]);
      }
    }
    
    return $instances;
  }
  
  /**
   * Filtre les services par disponibilité.
   *
   * @param Service[] $services
   * @return Service[]
   */
  public static function filterAvailable(array $services): array {
    return array_filter($services, fn ($service) => $service->isAvailable());
  }
  
  /**
   * Trouve un service par son code.
   *
   * @param Service[] $services
   * @param string $code
   * @return Service|null
   */
  public static function findByCode(array $services, string $code): ?Service {
    foreach ($services as $service) {
      if ($service->getCode() === $code) {
        return $service;
      }
    }
    return null;
  }
  
  /**
   * Vérifie si un service avec un code spécifique est disponible.
   *
   * @param Service[] $services
   * @param string $code
   * @return bool
   */
  public static function isServiceAvailable(array $services, string $code): bool {
    $service = self::findByCode($services, $code);
    return $service !== null && $service->isAvailable();
  }
  
  /**
   * Retourne les codes de tous les services.
   *
   * @param Service[] $services
   * @return string[]
   */
  public static function getAllCodes(array $services): array {
    return array_map(fn ($service) => $service->getCode(), $services);
  }
  
  /**
   * Constantes pour les codes de service DPD courants.
   */
  public const CODE_PARCEL_SHOP = '100'; // Point Relais standard
  public const CODE_EXPRESS_PARCEL_SHOP = '101'; // Point Relais Express
  public const CODE_PICKUP = '102'; // Retrait en dépôt
  public const CODE_LOCKER = '103'; // Casier automatique
  public const CODE_HOME_DELIVERY = '104'; // Livraison à domicile
  public const CODE_CASH_ON_DELIVERY = '105'; // Paiement à la livraison
  public const CODE_RETURN = '106'; // Retour de colis
  public const CODE_INSURANCE = '107'; // Assurance
  public const CODE_SATURDAY = '108'; // Livraison samedi
  public const CODE_EVENING = '109';
  
  // Livraison soirée
  
  /**
   * Retourne la description standard pour un code de service.
   */
  public static function getStandardDescription(string $code): string {
    $descriptions = [
      self::CODE_PARCEL_SHOP => 'Point Relais standard',
      self::CODE_EXPRESS_PARCEL_SHOP => 'Point Relais Express',
      self::CODE_PICKUP => 'Retrait en dépôt',
      self::CODE_LOCKER => 'Casier automatique',
      self::CODE_HOME_DELIVERY => 'Livraison à domicile',
      self::CODE_CASH_ON_DELIVERY => 'Paiement à la livraison',
      self::CODE_RETURN => 'Retour de colis',
      self::CODE_INSURANCE => 'Assurance',
      self::CODE_SATURDAY => 'Livraison samedi',
      self::CODE_EVENING => 'Livraison soirée'
    ];
    
    return $descriptions[$code] ?? 'Service inconnu';
  }
}