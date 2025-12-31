<?php

namespace Drupal\commerce_dpd\Service;

interface DpdAuthTokenManagerInterface {
  
  public function getMode(): string;
  
  public function getDelisId(): string;
  
  public function getPassword(): string;
  
  public function getCachedTokenData(): ?array;
  
  public function cacheTokenData(array $data): void;
  
  public function clear(): void;
}
