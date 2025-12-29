<?php

namespace Drupal\commerce_dpd\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Manages DPD authentication tokens (LoginService V2.0).
 *
 * Conforms to LoginService-Public_2_0 specification.
 */
final class DpdAuthTokenManager {
  private const STORE_KEY = 'dpd_auth';
  protected $config;
  protected $store;
  protected $logger;
  protected \SoapClient $client;
  
  public function __construct(ConfigFactoryInterface $config_factory, KeyValueFactoryInterface $key_value_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->config = $config_factory->get('commerce_dpd.settings');
    $this->store = $key_value_factory->get('commerce_dpd');
    $this->logger = $logger_factory->get('commerce_dpd');
    
    $base = $this->config->get('mode') === 'production' ? 'https://public-ws.dpd.com/services/' : 'https://public-ws-stage.dpd.com/services/';
    
    $this->client = new \SoapClient($base . 'LoginService/V2_0/?wsdl', [
      'soap_version' => SOAP_1_1,
      'exceptions' => TRUE,
      'trace' => TRUE
    ]);
  }
  
  /**
   * Returns a valid cached authentication token.
   */
  public function getToken(): string {
    $data = $this->store->get(self::STORE_KEY);
    if (!empty($data['authToken'])) {
      return $data['authToken'];
    }
    return $this->refreshToken();
  }
  
  /**
   * Calls LoginService getAuth and stores token data.
   */
  public function refreshToken(): string {
    try {
      $response = $this->client->getAuth([
        'delisId' => $this->getDelisId(),
        'password' => $this->getPassword(),
        'messageLanguage' => 'de_DE'
      ]);
      $login = $response->return ?? NULL;
      if (!$login || empty($login->authToken)) {
        throw new \RuntimeException('DPD LoginService returned no authToken.');
      }
      $this->store->set(self::STORE_KEY, [
        'authToken' => $login->authToken,
        'customerUid' => $login->customerUid ?? NULL,
        'depot' => $login->depot ?? NULL,
        'created' => time()
      ]);
      $this->logger->info('DPD auth token successfully generated.');
      return $login->authToken;
    }
    catch (\SoapFault $e) {
      $this->logger->error('DPD LoginService authentication failed (@code): @message', [
        '@code' => $e->faultcode ?? 'UNKNOWN',
        '@message' => $e->getMessage()
      ]);
      throw $e;
    }
  }
  
  /**
   * Clears cached authentication data.
   */
  public function clear(): void {
    $this->store->delete(self::STORE_KEY);
  }
  
  protected function getDelisId(): string {
    return $this->config->get($this->config->get('mode') === 'production' ? 'production_delis_id' : 'sandbox_delis_id');
  }
  
  protected function getPassword(): string {
    return $this->config->get($this->config->get('mode') === 'production' ? 'production_password' : 'sandbox_password');
  }
}
