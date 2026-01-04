<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;

class Address extends BaseData {
  private string $street;
  private string $houseNo;
  private string $zipCode;
  private string $city;
  private string $town;
  private string $country;
  private string $state;
  
  public function __construct(string $street, string $houseNo, string $zipCode, string $city, string $town, string $country, string $state) {
    $this->street = $street;
    $this->houseNo = $houseNo;
    $this->zipCode = $zipCode;
    $this->city = $city;
    $this->town = $town;
    $this->country = $country;
    $this->state = $state;
  }
  
  public function getStreet(): string {
    return $this->street;
  }
  
  public function getHouseNo(): string {
    return $this->houseNo;
  }
  
  public function getZipCode(): string {
    return $this->zipCode;
  }
  
  public function getCity(): string {
    return $this->city;
  }
  
  public function getTown(): string {
    return $this->town;
  }
  
  public function getCountry(): string {
    return $this->country;
  }
  
  public function getState(): string {
    return $this->state;
  }
  
  public function getFormatted(): string {
    return trim(sprintf('%s %s, %s %s', $this->street, $this->houseNo, $this->zipCode, $this->city));
  }
  
  public function toArray(): array {
    return get_object_vars($this);
  }
  
  public static function createFromDpdData(array|object $dataRaw): self {
    $data = self::normalizeStdClassToArray($dataRaw);
    return new self((string) ($data['street'] ?? ''), (string) ($data['houseNo'] ?? ''), (string) ($data['zipCode'] ?? ''), (string) ($data['city'] ?? ''), (string) ($data['town'] ?? ''), (string) ($data['country'] ?? ''), (string) ($data['state'] ?? ''));
  }
}