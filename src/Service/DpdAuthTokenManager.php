<?php

namespace Drupal\commerce_dpd\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Manages DPD authentication tokens (LoginService V2.0).
 *
 * Conforms to LoginService-Public_2_0 specification.
 */
final class DpdAuthTokenManager {
  private const STORE_KEY = 'dpd_auth';
  private const TOKEN_MAX_AGE = 86340; // 23h59 en secondes
  protected $config;
  protected $store;
  protected $logger;
  
  public function __construct(ConfigFactoryInterface $config_factory, KeyValueFactoryInterface $key_value_factory, LoggerChannelFactoryInterface $logger_factory, private TimeInterface $time) {
    $this->config = $config_factory->get('commerce_dpd.settings');
    $this->store = $key_value_factory->get('commerce_dpd');
    $this->logger = $logger_factory->get('commerce_dpd');
  }
  
  /**
   * Returns a valid cached authentication token.
   */
  public function getCachedTokenData(): ?array {
    $data = $this->store->get(self::STORE_KEY);
    if (!$data || !isset($data['authToken'])) {
      return null;
    }
    // Vérifier l'expiration
    if ($this->isTokenExpired($data)) {
      $this->clear();
      return null;
    }
    return $data;
  }
  
  /**
   * Sauvegarde les parametres et declenche une erreur au cas ou la sauvegarde
   * echoue.
   *
   * @param array $values
   */
  public function cacheTokenData(array $tokenData): void {
    if (empty($tokenData['authToken'])) {
      throw new \InvalidArgumentException('Token data must contain authToken');
    }
    $tokenData['created'] = $this->time->getCurrentTime();
    $this->store->set(self::STORE_KEY, $tokenData);
  }
  
  /**
   * Clears cached authentication data.
   */
  public function clear(): void {
    $this->store->delete(self::STORE_KEY);
  }
  
  public function getDelisId(): string {
    $mode = $this->config->get('mode');
    $key = $mode === 'production' ? 'production_delis_id' : 'sandbox_delis_id';
    return $this->config->get($key);
  }
  
  public function getPassword(): string {
    $mode = $this->config->get('mode');
    $key = $mode === 'production' ? 'production_password' : 'sandbox_password';
    return $this->config->get($key);
  }
  
  public function getMode(): string {
    return $this->config->get('mode');
  }
  
  private function isTokenExpired(array $tokenData): bool {
    if (!isset($tokenData['created'])) {
      return true;
    }
    $age = $this->time->getCurrentTime() - $tokenData['created'];
    return $age >= self::TOKEN_MAX_AGE;
  }
}
