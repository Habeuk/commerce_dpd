<?php

namespace Drupal\commerce_dpd\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_dpd\ApiClient\DpdApiClientInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Provides the DPD Home Delivery shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "dpd_home_delivery",
 *   label = @Translation("DPD Home Delivery"),
 *   services = {
 *     "dpd_classic" = @Translation("DPD Classic (2-3 days)"),
 *     "dpd_express_830" = @Translation("DPD Express 8:30"),
 *     "dpd_express_1000" = @Translation("DPD Express 10:00"),
 *     "dpd_express_1200" = @Translation("DPD Express 12:00"),
 *     "dpd_express_1800" = @Translation("DPD Express 18:00"),
 *     "dpd_priority" = @Translation("DPD Priority"),
 *     "dpd_max" = @Translation("DPD MAX"),
 *   },
 * )
 */
class DpdHomeDelivery extends ShippingMethodBase {
  
  /**
   * The DPD API client.
   *
   * @var \Drupal\commerce_dpd\ApiClient\DpdApiClientInterface
   */
  protected $dpdApiClient;
  
  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->dpdApiClient = $container->get('commerce_dpd.api_client');
    $instance->cache = $container->get('cache.default');
    return $instance;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_mode' => 'test',
      'test_delis_id' => '',
      'test_auth_token' => '',
      'live_delis_id' => '',
      'live_auth_token' => '',
      'default_product' => 'CL',
      'insurance_enabled' => FALSE,
      'insurance_min_value' => '100',
      'insurance_max_value' => '5000',
      'insurance_fee_percentage' => '1.5',
      'saturday_delivery_enabled' => FALSE,
      'saturday_delivery_fee' => '5.00',
      'cod_enabled' => FALSE,
      'cod_fee' => '2.50',
      'free_shipping_threshold' => '',
      'weight_limits' => [
        'min' => '0',
        'max' => '31500'
      ],
      'dimension_limits' => [
        'length' => '120',
        'width' => '60',
        'height' => '60'
      ]
    ] + parent::defaultConfiguration();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    
    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#weight' => -10
    ];
    
    $form['api_settings']['api_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('API Mode'),
      '#options' => [
        'test' => $this->t('Test Mode'),
        'live' => $this->t('Live Mode')
      ],
      '#default_value' => $this->configuration['api_mode'],
      '#description' => $this->t('Use test mode for development and live mode for production.')
    ];
    
    $form['api_settings']['test_credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Credentials'),
      '#open' => $this->configuration['api_mode'] === 'test',
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][api_settings][api_mode]"]' => [
            'value' => 'test'
          ]
        ]
      ]
    ];
    
    $form['api_settings']['test_credentials']['test_delis_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Delis ID'),
      '#default_value' => $this->configuration['test_delis_id'],
      '#required' => FALSE
    ];
    
    $form['api_settings']['test_credentials']['test_auth_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Auth Token'),
      '#default_value' => $this->configuration['test_auth_token'],
      '#required' => FALSE
    ];
    
    $form['api_settings']['live_credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Live Credentials'),
      '#open' => $this->configuration['api_mode'] === 'live',
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][api_settings][api_mode]"]' => [
            'value' => 'live'
          ]
        ]
      ]
    ];
    
    $form['api_settings']['live_credentials']['live_delis_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Delis ID'),
      '#default_value' => $this->configuration['live_delis_id'],
      '#required' => FALSE
    ];
    
    $form['api_settings']['live_credentials']['live_auth_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Auth Token'),
      '#default_value' => $this->configuration['live_auth_token'],
      '#required' => FALSE
    ];
    
    $form['product_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Product Settings')
    ];
    
    $form['product_settings']['default_product'] = [
      '#type' => 'select',
      '#title' => $this->t('Default DPD Product'),
      '#options' => [
        'CL' => $this->t('DPD CLASSIC (2-3 days)'),
        'E830' => $this->t('DPD 8:30'),
        'E10' => $this->t('DPD 10:00'),
        'E12' => $this->t('DPD 12:00'),
        'E18' => $this->t('DPD 18:00'),
        'PM4' => $this->t('DPD Priority'),
        'MAX' => $this->t('DPD MAX')
      ],
      '#default_value' => $this->configuration['default_product'],
      '#description' => $this->t('Default product to use when no specific service is selected.')
    ];
    
    $form['insurance'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Insurance Options')
    ];
    
    $form['insurance']['insurance_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable higher insurance'),
      '#default_value' => $this->configuration['insurance_enabled'],
      '#description' => $this->t('Allow customers to purchase additional insurance for their shipments.')
    ];
    
    $form['insurance']['insurance_min_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum value for insurance'),
      '#default_value' => $this->configuration['insurance_min_value'],
      '#min' => 0,
      '#field_suffix' => '€',
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][insurance][insurance_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['insurance']['insurance_max_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum insured value'),
      '#default_value' => $this->configuration['insurance_max_value'],
      '#min' => 0,
      '#field_suffix' => '€',
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][insurance][insurance_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['insurance']['insurance_fee_percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Insurance fee percentage'),
      '#default_value' => $this->configuration['insurance_fee_percentage'],
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.1,
      '#field_suffix' => '%',
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][insurance][insurance_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['saturday_delivery'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Saturday Delivery')
    ];
    
    $form['saturday_delivery']['saturday_delivery_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Saturday delivery'),
      '#default_value' => $this->configuration['saturday_delivery_enabled'],
      '#description' => $this->t('Allow customers to choose Saturday delivery (only for DPD 12:00).')
    ];
    
    $form['saturday_delivery']['saturday_delivery_fee'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Saturday delivery fee'),
      '#default_value' => [
        'number' => $this->configuration['saturday_delivery_fee'],
        'currency_code' => 'EUR'
      ],
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][saturday_delivery][saturday_delivery_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['cod'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cash on Delivery')
    ];
    
    $form['cod']['cod_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Cash on Delivery'),
      '#default_value' => $this->configuration['cod_enabled'],
      '#description' => $this->t('Allow customers to pay on delivery.')
    ];
    
    $form['cod']['cod_fee'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('COD fee'),
      '#default_value' => [
        'number' => $this->configuration['cod_fee'],
        'currency_code' => 'EUR'
      ],
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][cod][cod_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['pricing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pricing')
    ];
    
    $form['pricing']['free_shipping_threshold'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Free shipping threshold'),
      '#default_value' => $this->configuration['free_shipping_threshold'] ? [
        'number' => $this->configuration['free_shipping_threshold'],
        'currency_code' => 'EUR'
      ] : NULL,
      '#description' => $this->t('Order amount above which shipping is free. Leave empty for no free shipping.')
    ];
    
    $form['limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Size and Weight Limits')
    ];
    
    $form['limits']['weight_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Weight Limits (grams)'),
      '#tree' => TRUE
    ];
    
    $form['limits']['weight_limits']['min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum weight'),
      '#default_value' => $this->configuration['weight_limits']['min'],
      '#min' => 0,
      '#field_suffix' => 'g'
    ];
    
    $form['limits']['weight_limits']['max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum weight'),
      '#default_value' => $this->configuration['weight_limits']['max'],
      '#min' => 0,
      '#field_suffix' => 'g'
    ];
    
    $form['limits']['dimension_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Dimension Limits (cm)'),
      '#tree' => TRUE
    ];
    
    $form['limits']['dimension_limits']['length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum length'),
      '#default_value' => $this->configuration['dimension_limits']['length'],
      '#min' => 0,
      '#field_suffix' => 'cm'
    ];
    
    $form['limits']['dimension_limits']['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum width'),
      '#default_value' => $this->configuration['dimension_limits']['width'],
      '#min' => 0,
      '#field_suffix' => 'cm'
    ];
    
    $form['limits']['dimension_limits']['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum height'),
      '#default_value' => $this->configuration['dimension_limits']['height'],
      '#min' => 0,
      '#field_suffix' => 'cm'
    ];
    
    return $form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    
    $values = $form_state->getValue($form['#parents']);
    $api_mode = $values['api_settings']['api_mode'];
    
    if ($api_mode === 'test') {
      if (empty($values['api_settings']['test_credentials']['test_delis_id'])) {
        $form_state->setError($form['api_settings']['test_credentials']['test_delis_id'], $this->t('Test Delis ID is required in test mode.'));
      }
    }
    else {
      if (empty($values['api_settings']['live_credentials']['live_delis_id'])) {
        $form_state->setError($form['api_settings']['live_credentials']['live_delis_id'], $this->t('Live Delis ID is required in live mode.'));
      }
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      
      $this->configuration['api_mode'] = $values['api_settings']['api_mode'];
      $this->configuration['test_delis_id'] = $values['api_settings']['test_credentials']['test_delis_id'] ?? '';
      $this->configuration['test_auth_token'] = $values['api_settings']['test_credentials']['test_auth_token'] ?? '';
      $this->configuration['live_delis_id'] = $values['api_settings']['live_credentials']['live_delis_id'] ?? '';
      $this->configuration['live_auth_token'] = $values['api_settings']['live_credentials']['live_auth_token'] ?? '';
      
      $this->configuration['default_product'] = $values['product_settings']['default_product'];
      
      $this->configuration['insurance_enabled'] = !empty($values['insurance']['insurance_enabled']);
      $this->configuration['insurance_min_value'] = $values['insurance']['insurance_min_value'] ?? '100';
      $this->configuration['insurance_max_value'] = $values['insurance']['insurance_max_value'] ?? '5000';
      $this->configuration['insurance_fee_percentage'] = $values['insurance']['insurance_fee_percentage'] ?? '1.5';
      
      $this->configuration['saturday_delivery_enabled'] = !empty($values['saturday_delivery']['saturday_delivery_enabled']);
      $this->configuration['saturday_delivery_fee'] = $values['saturday_delivery']['saturday_delivery_fee']['number'] ?? '5.00';
      
      $this->configuration['cod_enabled'] = !empty($values['cod']['cod_enabled']);
      $this->configuration['cod_fee'] = $values['cod']['cod_fee']['number'] ?? '2.50';
      
      $this->configuration['free_shipping_threshold'] = $values['pricing']['free_shipping_threshold']['number'] ?? '';
      
      $this->configuration['weight_limits'] = $values['limits']['weight_limits'];
      $this->configuration['dimension_limits'] = $values['limits']['dimension_limits'];
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $rates = [];
    $order = $shipment->getOrder();
    $store = $order->getStore();
    $currency_code = $store->getDefaultCurrencyCode();
    
    // Vérifier les limites de poids et dimensions
    if (!$this->validateShipmentLimits($shipment)) {
      return $rates;
    }
    
    // Calculer le tarif de base
    $base_amount = $this->calculateBaseRate($shipment);
    
    // Services disponibles
    $services = $this->getServices();
    
    foreach ($services as $service_id => $service_label) {
      $service_amount = clone $base_amount;
      
      // Appliquer les suppléments selon le service
      switch ($service_id) {
        case 'dpd_express_830':
          $service_amount = $service_amount->add(new Price('10.00', $currency_code));
          break;
        
        case 'dpd_express_1000':
          $service_amount = $service_amount->add(new Price('8.00', $currency_code));
          break;
        
        case 'dpd_express_1200':
          $service_amount = $service_amount->add(new Price('6.00', $currency_code));
          break;
        
        case 'dpd_express_1800':
          $service_amount = $service_amount->add(new Price('4.00', $currency_code));
          break;
        
        case 'dpd_priority':
          $service_amount = $service_amount->add(new Price('12.00', $currency_code));
          break;
        
        case 'dpd_max':
          $service_amount = $service_amount->add(new Price('15.00', $currency_code));
          break;
      }
      
      // Appliquer la livraison gratuite si seuil atteint
      if ($this->isFreeShipping($order)) {
        $service_amount = new Price('0.00', $currency_code);
      }
      
      $rates[] = new ShippingRate([
        'shipping_method_id' => $this->parentEntity->id(),
        'service' => new ShippingService($service_id, $service_label),
        'amount' => $service_amount,
        'description' => $this->getServiceDescription($service_id)
      ]);
    }
    
    return $rates;
  }
  
  /**
   * Validate shipment against weight and dimension limits.
   */
  protected function validateShipmentLimits(ShipmentInterface $shipment): bool {
    $total_weight = 0;
    foreach ($shipment->getItems() as $item) {
      $total_weight += $item->getWeight()->getNumber();
    }
    
    // Convertir en grammes si nécessaire
    $total_weight_g = $total_weight * 1000;
    
    if ($total_weight_g > $this->configuration['weight_limits']['max'] || $total_weight_g < $this->configuration['weight_limits']['min']) {
      return FALSE;
    }
    
    // TODO: Valider les dimensions des colis
    // Pour l'instant, on accepte tout
    return TRUE;
  }
  
  /**
   * Calculate base shipping rate.
   */
  protected function calculateBaseRate(ShipmentInterface $shipment): Price {
    $order = $shipment->getOrder();
    $store = $order->getStore();
    $currency_code = $store->getDefaultCurrencyCode();
    
    // Tarif de base : 6.90€
    $base_price = new Price('6.90', $currency_code);
    
    // Supplément par kg au-dessus de 5kg
    $total_weight = 0;
    foreach ($shipment->getItems() as $item) {
      $total_weight += $item->getWeight()->getNumber();
    }
    
    if ($total_weight > 5) {
      $extra_weight = $total_weight - 5;
      $extra_charge = $extra_weight * 0.5; // 0.50€ par kg supplémentaire
      $base_price = $base_price->add(new Price((string) $extra_charge, $currency_code));
    }
    
    return $base_price;
  }
  
  /**
   * Check if order qualifies for free shipping.
   */
  protected function isFreeShipping($order): bool {
    if (empty($this->configuration['free_shipping_threshold'])) {
      return FALSE;
    }
    
    $threshold = new Price($this->configuration['free_shipping_threshold'], 'EUR');
    $order_total = $order->getTotalPrice();
    
    return $order_total && $order_total->greaterThanOrEqual($threshold);
  }
  
  /**
   * Get service description.
   */
  protected function getServiceDescription($service_id): string {
    $descriptions = [
      'dpd_classic' => $this->t('Delivery in 2-3 working days'),
      'dpd_express_830' => $this->t('Delivery by 8:30 AM next working day'),
      'dpd_express_1000' => $this->t('Delivery by 10:00 AM next working day'),
      'dpd_express_1200' => $this->t('Delivery by 12:00 PM next working day'),
      'dpd_express_1800' => $this->t('Delivery by 6:00 PM next working day'),
      'dpd_priority' => $this->t('Priority delivery with tracking'),
      'dpd_max' => $this->t('Delivery of heavy and bulky goods')
    ];
    
    return $descriptions[$service_id] ?? '';
  }
  
  /**
   * Get DPD product code for service.
   */
  public function getProductCodeForService($service_id): string {
    $mapping = [
      'dpd_classic' => 'CL',
      'dpd_express_830' => 'E830',
      'dpd_express_1000' => 'E10',
      'dpd_express_1200' => 'E12',
      'dpd_express_1800' => 'E18',
      'dpd_priority' => 'PM4',
      'dpd_max' => 'MAX'
    ];
    
    return $mapping[$service_id] ?? $this->configuration['default_product'];
  }
  
  /**
   * Check if Saturday delivery is available for service.
   */
  public function isSaturdayDeliveryAvailable($service_id): bool {
    return $this->configuration['saturday_delivery_enabled'] && $service_id === 'dpd_express_1200';
  }
}