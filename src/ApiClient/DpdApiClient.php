<?php

namespace Drupal\commerce_dpd\ApiClient;

use Drupal\commerce_dpd\Service\DpdAuthTokenManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * DPD SOAP API client.
 */
final class DpdApiClient implements DpdApiClientInterface {
  protected \SoapClient $shipmentClient;
  protected \SoapClient $parcelShopClient;
  protected \SoapClient $client;
  protected DpdAuthTokenManagerInterface $tokenManager;
  protected $logger;
  
  public function __construct(LoggerChannelFactoryInterface $logger_factory, DpdAuthTokenManagerInterface $token_manager) {
    $this->logger = $logger_factory->get('commerce_dpd');
    $this->tokenManager = $token_manager;
  }
  
  private function getShipmentClient(): \SoapClient {
    if (!isset($this->shipmentClient))
      $this->initializeSoapClients();
    
    return $this->shipmentClient;
  }
  
  private function getParcelShopClient(): \SoapClient {
    if (!isset($this->parcelShopClient))
      $this->initializeSoapClients();
    // à chaque requette on reconstruit l'authentification, car le token peut
    // expirer.
    $this->parcelShopClient->__setSoapHeaders([
      $this->buildAuthHeader()
    ]);
    
    return $this->parcelShopClient;
  }
  
  private function getClient(): \SoapClient {
    if (!isset($this->client))
      $this->initializeSoapClients();
    
    return $this->client;
  }
  
  private function initializeSoapClients(): void {
    $mode = $this->tokenManager->getMode();
    if (!in_array($mode, [
      'sandbox',
      'production'
    ])) {
      throw new \RuntimeException('Invalid DPD mode. Must be "sandbox" or "production".');
    }
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
    $this->parcelShopClient = new \SoapClient($base . 'ParcelShopFinderService/V5_0/?wsdl', $soap_options);
    
    // authentificate
    $this->client = new \SoapClient($base . 'LoginService/V2_0/?wsdl', [
      'soap_version' => SOAP_1_1,
      'exceptions' => TRUE,
      'trace' => TRUE
    ]);
  }
  
  private function buildAuthHeader(): \SoapHeader {
    return new \SoapHeader('http://dpd.com/common/service/types/Authentication/2.0', 'authentication', [
      'delisId' => $this->getDelisId(),
      'authToken' => $this->getToken(),
      'messageLanguage' => 'de_DE'
    ]);
  }
  
  /**
   * Returns a valid cached authentication token.
   * Le nombre de token ne doit pas deppassé 10/jour.
   * Il est recommandé d'utiliser le meme token pendant 23h59 ou d'attendre son
   * expiration.
   */
  public function getToken(): string {
    $cachedData = $this->tokenManager->getCachedTokenData();
    if ($cachedData && !empty($cachedData['authToken']))
      return $cachedData['authToken'];
    return $this->generateNewToken();
  }
  
  private function generateNewToken(): string {
    try {
      // Vérifier et incrémenter le compteur journalier
      $this->tokenManager->incrementTokenGenerationCount();
      $response = $this->getClient()->getAuth([
        'delisId' => $this->getDelisId(),
        'password' => $this->getPassword(),
        'messageLanguage' => 'de_DE'
      ]);
      $login = $response->return ?? NULL;
      if (!$login || empty($login->authToken)) {
        throw new \RuntimeException('DPD LoginService returned no authToken.');
      }
      $values = [
        'authToken' => $login->authToken,
        'customerUid' => $login->customerUid ?? NULL,
        'depot' => $login->depot ?? NULL
      ];
      //
      $this->tokenManager->cacheTokenData($values);
      $countToday = $this->tokenManager->getTokenGenerationCountToday();
      $this->logger->info('DPD auth token generated in "@mode". Count today: @count/@max',
        [
          '@mode' => $this->tokenManager->getMode(),
          '@count' => $countToday,
          '@max' => \Drupal\commerce_dpd\Service\DpdAuthTokenManager::MAX_TOKENS_PER_DAY
        ]);
      return $login->authToken;
    }
    catch (\SoapFault $e) {
      $this->logger->error('DPD LoginService authentication failed (@code): @message', [
        '@code' => $e->faultcode ?? 'UNKNOWN',
        '@message' => $e->getMessage()
      ]);
      throw $e;
    }
    catch (\RuntimeException $e) {
      // Relancer les erreurs de limite sans les logger comme des erreurs
      // techniques
      $this->logger->warning($e->getMessage());
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->error('DPD LoginService authentication ERROR (@code): @message', [
        '@code' => $e->getCode() ?? 'UNKNOWN',
        '@message' => $e->getMessage()
      ]);
      throw $e;
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function storeOrders(array $orders): array {
    $payload = [
      'auth' => [
        'delisId' => $this->getDelisId(),
        'authToken' => $this->getToken()
      ],
      'order' => $this->normalizeIso88591($orders)
    ];
    
    try {
      return (array) $this->getShipmentClient()->storeOrders($payload);
    }
    catch (\SoapFault $e) {
      // If token expired, refresh once and retry.
      if ($this->isAuthExpiredFault($e)) {
        $this->tokenManager->clear();
        //
        return (array) $this->getShipmentClient()->storeOrders($payload);
      }
      
      $this->logger->error('DPD ShipmentService error: @msg', [
        '@msg' => $e->getMessage()
      ]);
      throw $e;
    }
  }
  
  /**
   * il faut tenir compte de ce code d'erreur: PARCELSHOPFINDER_NO_GEODATA_FOUND
   * ( apres avoir mit sur peid la map ).
   *
   * {@inheritdoc}
   */
  public function findParcelShops(array $criteria): array {
    $request = $criteria + [
      'limit' => 10,
      'hideClosed' => TRUE,
      'availabilityDate' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i'),
      'country' => 'DE',
      'services' => [
        'service' => [
          [
            'code' => '100', // ParcelShops - chaîne de 3 caractères
            'available' => TRUE
          ]
        ]
      ]
    ];
    $payload = $this->normalizeIso88591($request);
    
    \Stephane888\Debug\debugLog::symfonyDebug($payload, 'findParcelShops__payload', true);
    try {
      $response = $this->getParcelShopClient()->findParcelShops($payload);
      return (array) $response;
    }
    catch (\SoapFault $e) {
      if ($this->isAuthExpiredFault($e)) {
        $this->tokenManager->clear();
        $response = $this->getParcelShopClient()->findParcelShops($payload);
        return (array) $response;
      }
      \Stephane888\Debug\debugLog::symfonyDebug($e, 'findParcelShops__error', true);
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
  public function getLastRequestShipmentClient(): string|null {
    return $this->getShipmentClient()->__getLastRequest();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastResponseShipmentClient(): string|null {
    return $this->getShipmentClient()->__getLastResponse();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastRequestParcelShopClient(): string|null {
    return $this->getParcelShopClient()->__getLastRequest();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastResponseParcelShopClient(): string|null {
    return $this->getParcelShopClient()->__getLastResponse();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastRequestClient(): string|null {
    return $this->getClient()->__getLastRequest();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastResponseClient(): string|null {
    return $this->getClient()->__getLastResponse();
  }
  
  /**
   * Convertit seulement les champs d'adresse en ISO-8859-1.
   * Ne touche pas aux codes postaux, téléphones, emails, etc.
   */
  protected function normalizeIso88591(array $data): array {
    $addressFields = [
      'name',
      'name1',
      'name2',
      'addressLine1',
      'addressLine2',
      'remarks',
      'street',
      'houseNo',
      'city',
      'company',
      'contact',
      'floor',
      'building',
      'department',
      'room',
      'deliveryInstruction'
    ];
    
    array_walk_recursive($data,
      function (&$value, $key) use ($addressFields) {
        if (is_string($value) && in_array($key, $addressFields)) {
          $converted = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);
          if ($converted !== false) {
            $value = $converted;
          }
        }
      });
    return $data;
  }
  
  protected function getDelisId(): string {
    $delisId = $this->tokenManager->getDelisId();
    if (empty($delisId)) {
      throw new \RuntimeException('DPD credentials are not configured.');
    }
    return $delisId;
  }
  
  protected function getPassword(): string {
    $password = $this->tokenManager->getPassword();
    if (empty($password)) {
      throw new \RuntimeException('DPD credentials are not configured.');
    }
    return $password;
  }
  
  /**
   * Checks whether the SOAP fault indicates an expired/invalid token.
   */
  protected function isAuthExpiredFault(\SoapFault $e): bool {
    $msg = $e->getMessage();
    return str_contains($msg, 'LOGIN_5') || str_contains($msg, 'LOGIN_6');
  }
}
