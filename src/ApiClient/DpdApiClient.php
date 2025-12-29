<?php

namespace Drupal\commerce_dpd\ApiClient;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * DPD API Client for SOAP Web Services.
 */
class DpdApiClient implements DpdApiClientInterface {
  
  use StringTranslationTrait;
  
  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;
  
  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;
  
  /**
   * SOAP client for LoginService.
   *
   * @var \SoapClient
   */
  protected $loginClient;
  
  /**
   * SOAP client for ShipmentService.
   *
   * @var \SoapClient
   */
  protected $shipmentClient;
  
  /**
   * Authentication token.
   *
   * @var string
   */
  protected $authToken;
  
  /**
   * Constructs a new DpdApiClient object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('commerce_dpd');
    $this->initializeClients();
  }
  
  /**
   * Initializes SOAP clients.
   */
  protected function initializeClients() {
    $config = $this->configFactory->get('commerce_dpd.settings');
    $mode = $config->get('mode');
    
    $wsdl_base = ($mode === 'production') ? 'https://public-ws.dpd.com/services/' : 'https://public-ws-stage.dpd.com/services/';
    
    $options = [
      'soap_version' => SOAP_1_1,
      'trace' => 1,
      'exceptions' => 1,
      'features' => SOAP_SINGLE_ELEMENT_ARRAYS
    ];
    
    try {
      $this->loginClient = new \SoapClient($wsdl_base . 'LoginService/V2_0/?wsdl', $options);
      $this->shipmentClient = new \SoapClient($wsdl_base . 'ShipmentService/V3_2/?wsdl', $options);
    }
    catch (\SoapFault $e) {
      $this->logger->error('Failed to initialize DPD SOAP clients: @error', [
        '@error' => $e->getMessage()
      ]);
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function authenticate(): bool {
    $config = $this->configFactory->get('commerce_dpd.settings');
    $mode = $config->get('mode');
    
    $delisId = ($mode === 'production') ? $config->get('production_delis_id') : $config->get('sandbox_delis_id');
    
    $password = ($mode === 'production') ? $config->get('production_password') : $config->get('sandbox_password');
    
    if (empty($delisId) || empty($password)) {
      $this->logger->error('DPD credentials are not configured.');
      return FALSE;
    }
    
    try {
      $params = [
        'delisId' => $delisId,
        'password' => $password,
        'messageLanguage' => 'de_DE'
      ];
      
      $response = $this->loginClient->getAuth($params);
      
      if (isset($response->return->authToken)) {
        $this->authToken = $response->return->authToken;
        $this->logger->info('DPD authentication successful.');
        return TRUE;
      }
    }
    catch (\SoapFault $e) {
      $this->logger->error('DPD authentication failed: @error', [
        '@error' => $e->getMessage()
      ]);
    }
    
    return FALSE;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function createShipment(array $shipment_data): array {
    if (!$this->authToken && !$this->authenticate()) {
      return [
        'error' => $this->t('Authentication failed.')
      ];
    }
    
    try {
      // Prepare shipment request
      $request = [
        'printOptions' => [
          'printOption' => [
            'outputFormat' => 'PDF',
            'paperFormat' => 'A6'
          ]
        ],
        'order' => [
          'generalShipmentData' => [
            'sendingDepot' => '0593', // Default depot, should be configurable
            'product' => 'CL', // CL = Classic, CN = Classic with notification
            'sender' => $shipment_data['sender'],
            'recipient' => $shipment_data['recipient']
          ],
          'parcels' => [
            'parcelLabelNumber' => 1,
            'weight' => $shipment_data['weight'] * 1000 // Convert kg to g
          ]
        ]
      ];
      
      // Add parcelshop ID if selected
      if (!empty($shipment_data['parcelshop_id'])) {
        $request['order']['parcels']['parcelShopId'] = $shipment_data['parcelshop_id'];
      }
      
      $response = $this->shipmentClient->storeOrders([
        'auth' => [
          'delisId' => $this->getDelisId(),
          'authToken' => $this->authToken
        ],
        'order' => $request
      ]);
      
      if (isset($response->orderResult->parcellabelsPDF)) {
        return [
          'success' => TRUE,
          'tracking_number' => $response->orderResult->shipmentResponses->parcelInformation->parcelLabelNumber,
          'label_pdf' => base64_decode($response->orderResult->parcellabelsPDF)
        ];
      }
    }
    catch (\SoapFault $e) {
      $this->logger->error('DPD shipment creation failed: @error', [
        '@error' => $e->getMessage()
      ]);
      return [
        'error' => $e->getMessage()
      ];
    }
    
    return [
      'error' => $this->t('Unknown error creating shipment.')
    ];
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getParcelshops(string $zip_code, string $country = 'DE'): array {
    // Note: This requires DPD's ParcelShopFinder API
    // Implementation depends on available API endpoints
    return [];
  }
  
  /**
   * Gets the current DelisId based on mode.
   */
  protected function getDelisId(): string {
    $config = $this->configFactory->get('commerce_dpd.settings');
    $mode = $config->get('mode');
    
    return ($mode === 'production') ? $config->get('production_delis_id') : $config->get('sandbox_delis_id');
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastRequest(): string {
    return $this->shipmentClient->__getLastRequest();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getLastResponse(): string {
    return $this->shipmentClient->__getLastResponse();
  }
}