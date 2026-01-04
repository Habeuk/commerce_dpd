<?php

namespace Drupal\commerce_dpd\DpdData;

use Drupal\commerce_dpd\DpdHelper\BaseData;

class Contact extends BaseData {
  private string $phone;
  private string $fax;
  private string $email;
  private string $homepage;
  
  public function __construct(string $phone, string $fax, string $email, string $homepage) {
    $this->phone = $phone;
    $this->fax = $fax;
    $this->email = $email;
    $this->homepage = $homepage;
  }
  
  public function getPhone(): string {
    return $this->phone;
  }
  
  public function getFax(): string {
    return $this->fax;
  }
  
  public function getEmail(): string {
    return $this->email;
  }
  
  public function getHomepage(): string {
    return $this->homepage;
  }
  
  public function toArray(): array {
    return get_object_vars($this);
  }
  
  public static function createFromDpdData(array|object $dataRaw): self {
    $data = self::normalizeStdClassToArray($dataRaw);
    return new self((string) ($data['phone'] ?? ''), (string) ($data['fax'] ?? ''), (string) ($data['email'] ?? ''), (string) ($data['homepage'] ?? ''));
  }
}