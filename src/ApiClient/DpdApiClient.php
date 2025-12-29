<?php

namespace Drupal\commerce_dpd\ApiClient;

use Drupal\commerce_dpd\Service\DpdAuthTokenManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * DPD SOAP API client.
 */
final class DpdApiClient implements DpdApiClientInterface {
  protected \SoapClient $shipmentClient;
  protected \SoapClient $parcelShopClient;
  protected DpdAuthTokenManager $tokenManager;
  protected $config;
  protected $logger;
  
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, DpdAuthTokenManager $token_manager) {
    $this->config = $config_factory->get('commerce_dpd.settings');
    $this->logger = $logger_factory->get('commerce_dpd');
    $this->tokenManager = $token_manager;
    
    $mode = $this->config->get('mode');
    $base = ($mode === 'production') ? 'https://public-ws.dpd.com/services/' : 'https://public-ws-stage.dpd.com/services/';
    
    $soap_options = [
      'trace' => TRUE,
      'exceptions' => TRUE,
      'soap_version' => SOAP_1_1,
      'features' => SOAP_SINGLE_ELEMENT_ARRAYS
    ];
    
    // Shipment service (labels).
    $this->shipmentClient = new \SoapClient($base . 'ShipmentService/V4_4/?wsdl', $soap_options);
    
    // ParcelShopFinder service (pickup points).
    // NOTE: The exact service version may differ on your portal.
    // Keep this version configurable if needed.
    $this->parcelShopClient = new \SoapClient($base . 'ParcelShopFinderService/V5_0/?wsdl', $soap_options);
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function storeOrders(array $orders): array {
    $payload = [
      'auth' => [
        'delisId' => $this->getDelisId(),
        'authToken' => $this->tokenManager->getToken()
      ],
      'order' => $this->normalizeIso88591($orders)
    ];
    
    try {
      return (array) $this->shipmentClient->storeOrders($payload);
    }
    catch (\SoapFault $e) {
      // If token expired, refresh once and retry.
      if ($this->isAuthExpiredFault($e)) {
        $this->tokenManager->clear();
        $payload['auth']['authToken'] = $this->tokenManager->getToken();
        return (array) $this->shipmentClient->storeOrders($payload);
      }
      
      $this->logger->error('DPD ShipmentService error: @msg', [
        '@msg' => $e->getMessage()
      ]);
      throw $e;
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function findParcelShops(array $criteria): array {
    // Mandatory fields recommended by DPD guidelines:
    // address + limit=10 + availabilityDate + hideClosed=true + searchCountry
    // and service code 100 (ParcelShop) / 901 (Pickup station) filters.
    // :contentReference[oaicite:1]{index=1}
    $defaults = [
      'limit' => 10,
      'hideClosed' => TRUE,
      'availabilityDate' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
      'searchCountry' => $criteria['searchCountry'] ?? 'DE',
      'country' => $criteria['country'] ?? 'DE',
      'services' => [
        'service' => [
          [
            'code' => 100, // ParcelShops
            'available' => TRUE
          ]
        ]
      ]
    ];
    
    // Merge + normalize.
    $request = array_replace_recursive($defaults, $criteria);
    $request = $this->normalizeIso88591($request);
    
    $payload = [
      'auth' => [
        'delisId' => $this->getDelisId(),
        'authToken' => $this->tokenManager->getToken()
      ]
    ] + $request;
    
    try {
      // Operation name is typically "findParcelShops".
      // Some WSDLs wrap parameters differently; if you get a SOAP fault,
      // we'll adjust the payload shape to match your WSDL exactly.
      $response = $this->parcelShopClient->findParcelShops($payload);
      return (array) $response;
    }
    catch (\SoapFault $e) {
      if ($this->isAuthExpiredFault($e)) {
        $this->tokenManager->clear();
        $payload['auth']['authToken'] = $this->tokenManager->getToken();
        $response = $this->parcelShopClient->findParcelShops($payload);
        return (array) $response;
      }
      
      $this->logger->error('DPD ParcelShopFinder error: @msg', [
        '@msg' => $e->getMessage()
      ]);
      throw $e;
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastRequest(): string {
    // If you want, you can add a parameter to choose shipment vs parcelShop.
    // Here we return the last request for whichever client was used last
    // (SOAP keeps its own last request per client).
    return $this->shipmentClient->__getLastRequest();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastResponse(): string {
    return $this->shipmentClient->__getLastResponse();
  }
  
  protected function getDelisId(): string {
    return $this->config->get($this->config->get('mode') === 'production' ? 'production_delis_id' : 'sandbox_delis_id');
  }
  
  /**
   * Converts UTF-8 strings to ISO-8859-1 as required by DPD for address data.
   * :contentReference[oaicite:2]{index=2}
   */
  protected function normalizeIso88591(array $data): array {
    array_walk_recursive($data, function (&$value) {
      if (is_string($value)) {
        $value = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);
      }
    });
    return $data;
  }
  
  /**
   * Checks whether the SOAP fault indicates an expired/invalid token.
   */
  protected function isAuthExpiredFault(\SoapFault $e): bool {
    $msg = $e->getMessage();
    return str_contains($msg, 'LOGIN_5') || str_contains($msg, 'LOGIN_6');
  }
}
