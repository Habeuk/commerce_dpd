<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;
use Drupal\commerce_dpd\Dto\Attribute\DpdField;
use Drupal\commerce_dpd\Dto\Hydrator\DpdHydrator;

/**
 * Représente un point relais DPD avec toutes ses données.
 */
class ParcelShop extends BaseData {
  // === IDENTIFICATION ===
  #[DpdField(source: 'parcelShopId', type: 'int', required: true)]
  public readonly int $id;
  #[DpdField(source: 'pudoId', type: 'string', required: true)]
  public readonly string $pudoId;
  #[DpdField(source: 'customerNo', type: 'string')]
  public readonly ?string $customerNo;
  #[DpdField(source: 'companyDepotDatabaseId', type: 'int')]
  public readonly ?int $companyDepotDatabaseId;
  
  // === INFORMATIONS ENTREPRISE ===
  #[DpdField(source: 'company', type: 'string', required: true)]
  public readonly string $company;
  
  // === ADRESSE ===
  // Note: street, houseNo, etc. sont dans l'objet Address
  // mais gardons l'accès direct pour compatibilité
  #[DpdField(factory: Address::class . '::fromDpd')]
  public readonly ?Address $address;
  #[DpdField(source: 'country', type: 'string')]
  public readonly ?string $country;
  #[DpdField(source: 'countryNum', type: 'int')]
  public readonly ?int $countryNum;
  #[DpdField(source: 'state', type: 'string')]
  public readonly ?string $state;
  #[DpdField(source: 'zipCode', type: 'string', required: true)]
  public readonly string $zipCode;
  #[DpdField(source: 'city', type: 'string')]
  public readonly ?string $city;
  #[DpdField(source: 'town', type: 'string')]
  public readonly ?string $town;
  
  // === COORDONNÉES ===
  #[DpdField(factory: null)]
  public readonly ?Coordinates $coordinates;
  
  // === DISTANCE ===
  #[DpdField(source: 'distance', type: 'float')]
  public readonly float $distance;
  
  // === CONTACT ===
  #[DpdField(factory: Contact::class . '::fromDpd')]
  public readonly ?Contact $contact;
  
  // === HORAIRES ===
  #[DpdField(source: 'openingHours', factory: OpeningHours::class . '::createCollectionFromDpdData')]
  public readonly array $openingHours;
  
  // === SERVICES ===
  #[DpdField(source: 'services.service', factory: Service::class . '::createCollectionFromDpdData')]
  public readonly array $services;
  
  // === INFORMATIONS SUPPLÉMENTAIRES ===
  #[DpdField(source: 'expressPickupTime', type: 'string')]
  public readonly ?string $expressPickupTime;
  #[DpdField(source: 'extraInfo', type: 'string')]
  public readonly ?string $extraInfo;
  
  // === MÉTHODES UTILITAIRES ===
  
  /**
   * Constructeur privé - utilisation via factory uniquement.
   */
  private function __construct() {
  }
  
  // Getters
  public function getId(): int {
    return $this->id;
  }
  
  public function getPudoId(): string {
    return $this->pudoId;
  }
  
  public function getCompany(): string {
    return $this->company;
  }
  
  public function getAddress(): ?Address {
    return $this->address;
  }
  
  public function getZipCode(): string|int {
    return $this->zipCode;
  }
  
  public function getCoordinates(): Coordinates {
    return $this->coordinates;
  }
  
  public function getDistance(): float {
    return $this->distance;
  }
  
  public function getContact(): Contact {
    return $this->contact;
  }
  
  public function getOpeningHours(): array {
    return $this->openingHours;
  }
  
  public function getServices(): array {
    return $this->services;
  }
  
  public function getExpressPickupTime(): ?string {
    return $this->expressPickupTime;
  }
  
  public function getExtraInfo(): ?string {
    return $this->extraInfo;
  }
  
  // Méthodes utilitaires
  public function getFormattedAddress(): string {
    return $this->address->getFormatted();
  }
  
  public function isOpenToday(): bool {
    $today = date('N'); // 1=lundi, 7=dimanche
    foreach ($this->openingHours as $hours) {
      if ($hours->getWeekdayNum() === $today) {
        return !$hours->isDayOff();
      }
    }
    return false;
  }
  
  public function hasService(string $code): bool {
    foreach ($this->services as $service) {
      if ($service->getCode() === $code && $service->isAvailable()) {
        return true;
      }
    }
    return false;
  }
  
  public function toArray(): array {
    return [
      'id' => $this->id,
      'pudo_id' => $this->pudoId,
      'company' => $this->company,
      'address' => $this->address?->toArray() ?? [],
      'coordinates' => $this->coordinates?->toArray() ?? [],
      'distance' => $this->distance,
      'contact' => $this->contact?->toArray() ?? [],
      'opening_hours' => array_map(fn ($h) => $h->toArray(), $this->openingHours),
      'services' => array_map(fn ($s) => $s->toArray(), $this->services),
      'express_pickup_time' => $this->expressPickupTime,
      'extra_info' => $this->extraInfo
    ];
  }
  
  /**
   * Factory method : crée un ParcelShop depuis les données brutes DPD.
   *
   * @param array|object $data
   * @return self
   */
  public static function createFromDpdData(array|object $data, DpdHydrator $hydrator): self {
    return $hydrator->hydrate(ParcelShop::class, $data);
  }
  
  /**
   * Crée une collection depuis une réponse DPD.
   *
   * @param object $response
   * @return array
   */
  public static function createCollectionFromResponse(object $response): array {
    $parcelShops = [];
    if (!empty($response->parcelShop)) {
      $hydrator = new DpdHydrator();
      foreach ($response->parcelShop as $shopData) {
        $parcelShops[] = self::createFromDpdData($shopData, $hydrator);
      }
    }
    return $parcelShops;
  }
}