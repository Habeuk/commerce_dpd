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
 * Provides the DPD ParcelShop shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "dpd_parcelshop",
 *   label = @Translation("DPD ParcelShop"),
 *   services = {
 *     "dpd_parcelshop_classic" = @Translation("DPD ParcelShop Classic"),
 *     "dpd_parcelshop_express" = @Translation("DPD ParcelShop Express"),
 *   },
 * )
 */
class DpdParcelshop extends ShippingMethodBase {
  
  /**
   * The DPD API client.
   *
   * @var DpdApiClientInterface
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
      'parcelshop_required' => TRUE,
      'default_results_limit' => 10,
      'max_search_radius' => 20, // km
      'hide_closed_shops' => TRUE,
      'show_opening_hours' => TRUE,
      'show_distance' => TRUE,
      'parcelshop_fee' => '4.90',
      'express_fee' => '2.00',
      'free_parcelshop_threshold' => '',
      'parcelshop_pickup_days' => 3,
      'notification_enabled' => TRUE,
      'notification_channels' => [
        'email'
      ],
      'widget_style' => 'list',
      'map_enabled' => TRUE,
      'map_provider' => 'google',
      'map_api_key' => '',
      'auto_select_nearest' => FALSE
    ] + parent::defaultConfiguration();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    
    // Hériter des paramètres API de DpdHomeDelivery ou les redéfinir
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
      '#default_value' => $this->configuration['api_mode']
    ];
    
    // ... (similaire à DpdHomeDelivery pour les credentials)
    
    $form['parcelshop_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('ParcelShop Settings')
    ];
    
    $form['parcelshop_settings']['parcelshop_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require ParcelShop selection'),
      '#default_value' => $this->configuration['parcelshop_required'],
      '#description' => $this->t('Customers must select a ParcelShop before proceeding.')
    ];
    
    $form['parcelshop_settings']['default_results_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Default results limit'),
      '#default_value' => $this->configuration['default_results_limit'],
      '#min' => 1,
      '#max' => 50,
      '#description' => $this->t('Maximum number of ParcelShops to show in search results.')
    ];
    
    $form['parcelshop_settings']['max_search_radius'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum search radius'),
      '#default_value' => $this->configuration['max_search_radius'],
      '#min' => 1,
      '#max' => 100,
      '#field_suffix' => 'km',
      '#description' => $this->t('Maximum distance from address to search for ParcelShops.')
    ];
    
    $form['parcelshop_settings']['hide_closed_shops'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide closed shops'),
      '#default_value' => $this->configuration['hide_closed_shops'],
      '#description' => $this->t('Do not show ParcelShops that are currently closed.')
    ];
    
    $form['display_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Display Settings')
    ];
    
    $form['display_settings']['show_opening_hours'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show opening hours'),
      '#default_value' => $this->configuration['show_opening_hours'],
      '#description' => $this->t('Display opening hours for each ParcelShop.')
    ];
    
    $form['display_settings']['show_distance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show distance'),
      '#default_value' => $this->configuration['show_distance'],
      '#description' => $this->t('Display distance from shipping address.')
    ];
    
    $form['display_settings']['widget_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Widget style'),
      '#options' => [
        'list' => $this->t('List'),
        'grid' => $this->t('Grid'),
        'map' => $this->t('Map')
      ],
      '#default_value' => $this->configuration['widget_style'],
      '#description' => $this->t('How to display ParcelShop selection widget.')
    ];
    
    $form['display_settings']['auto_select_nearest'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-select nearest ParcelShop'),
      '#default_value' => $this->configuration['auto_select_nearest'],
      '#description' => $this->t('Automatically select the nearest ParcelShop when only one is available.')
    ];
    
    $form['map_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Map Settings'),
      '#states' => [
        'visible' => [
          [
            ':input[name="plugin[0][target_plugin_configuration][display_settings][widget_style]"]' => [
              'value' => 'map'
            ]
          ],
          'or',
          [
            ':input[name="plugin[0][target_plugin_configuration][display_settings][map_enabled]"]' => [
              'checked' => TRUE
            ]
          ]
        ]
      ]
    ];
    
    $form['map_settings']['map_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable map'),
      '#default_value' => $this->configuration['map_enabled'],
      '#description' => $this->t('Show map with ParcelShop locations.')
    ];
    
    $form['map_settings']['map_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Map provider'),
      '#options' => [
        'google' => $this->t('Google Maps'),
        'openstreetmap' => $this->t('OpenStreetMap'),
        'leaflet' => $this->t('Leaflet')
      ],
      '#default_value' => $this->configuration['map_provider'],
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][map_settings][map_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['map_settings']['map_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Map API Key'),
      '#default_value' => $this->configuration['map_api_key'],
      '#description' => $this->t('API key for the map provider (if required).'),
      '#states' => [
        'visible' => [
          [
            ':input[name="plugin[0][target_plugin_configuration][map_settings][map_enabled]"]' => [
              'checked' => TRUE
            ]
          ],
          [
            ':input[name="plugin[0][target_plugin_configuration][map_settings][map_provider]"]' => [
              'value' => 'google'
            ]
          ]
        ]
      ]
    ];
    
    $form['pricing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pricing')
    ];
    
    $form['pricing']['parcelshop_fee'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('ParcelShop fee'),
      '#default_value' => [
        'number' => $this->configuration['parcelshop_fee'],
        'currency_code' => 'EUR'
      ],
      '#description' => $this->t('Base fee for ParcelShop delivery.')
    ];
    
    $form['pricing']['express_fee'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Express fee'),
      '#default_value' => [
        'number' => $this->configuration['express_fee'],
        'currency_code' => 'EUR'
      ],
      '#description' => $this->t('Additional fee for express ParcelShop delivery.')
    ];
    
    $form['pricing']['free_parcelshop_threshold'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Free ParcelShop threshold'),
      '#default_value' => $this->configuration['free_parcelshop_threshold'] ? [
        'number' => $this->configuration['free_parcelshop_threshold'],
        'currency_code' => 'EUR'
      ] : NULL,
      '#description' => $this->t('Order amount above which ParcelShop delivery is free.')
    ];
    
    $form['notification'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Notification Settings')
    ];
    
    $form['notification']['notification_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable notifications'),
      '#default_value' => $this->configuration['notification_enabled'],
      '#description' => $this->t('Send notifications when parcel is ready for pickup.')
    ];
    
    $form['notification']['notification_channels'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Notification channels'),
      '#options' => [
        'email' => $this->t('Email'),
        'sms' => $this->t('SMS'),
        'push' => $this->t('Push notification')
      ],
      '#default_value' => $this->configuration['notification_channels'],
      '#description' => $this->t('Channels to use for pickup notifications.'),
      '#states' => [
        'visible' => [
          ':input[name="plugin[0][target_plugin_configuration][notification][notification_enabled]"]' => [
            'checked' => TRUE
          ]
        ]
      ]
    ];
    
    $form['pickup_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Pickup Settings')
    ];
    
    $form['pickup_settings']['parcelshop_pickup_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Pickup days'),
      '#default_value' => $this->configuration['parcelshop_pickup_days'],
      '#min' => 1,
      '#max' => 14,
      '#field_suffix' => $this->t('days'),
      '#description' => $this->t('Number of days parcel is held at ParcelShop before return.')
    ];
    
    return $form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      
      // API settings
      $this->configuration['api_mode'] = $values['api_settings']['api_mode'];
      $this->configuration['test_delis_id'] = $values['api_settings']['test_credentials']['test_delis_id'] ?? '';
      $this->configuration['test_auth_token'] = $values['api_settings']['test_credentials']['test_auth_token'] ?? '';
      $this->configuration['live_delis_id'] = $values['api_settings']['live_credentials']['live_delis_id'] ?? '';
      $this->configuration['live_auth_token'] = $values['api_settings']['live_credentials']['live_auth_token'] ?? '';
      
      // ParcelShop settings
      $this->configuration['parcelshop_required'] = !empty($values['parcelshop_settings']['parcelshop_required']);
      $this->configuration['default_results_limit'] = $values['parcelshop_settings']['default_results_limit'];
      $this->configuration['max_search_radius'] = $values['parcelshop_settings']['max_search_radius'];
      $this->configuration['hide_closed_shops'] = !empty($values['parcelshop_settings']['hide_closed_shops']);
      
      // Display settings
      $this->configuration['show_opening_hours'] = !empty($values['display_settings']['show_opening_hours']);
      $this->configuration['show_distance'] = !empty($values['display_settings']['show_distance']);
      $this->configuration['widget_style'] = $values['display_settings']['widget_style'];
      $this->configuration['auto_select_nearest'] = !empty($values['display_settings']['auto_select_nearest']);
      
      // Map settings
      $this->configuration['map_enabled'] = !empty($values['map_settings']['map_enabled']);
      $this->configuration['map_provider'] = $values['map_settings']['map_provider'];
      $this->configuration['map_api_key'] = $values['map_settings']['map_api_key'] ?? '';
      
      // Pricing
      $this->configuration['parcelshop_fee'] = $values['pricing']['parcelshop_fee']['number'] ?? '4.90';
      $this->configuration['express_fee'] = $values['pricing']['express_fee']['number'] ?? '2.00';
      $this->configuration['free_parcelshop_threshold'] = $values['pricing']['free_parcelshop_threshold']['number'] ?? '';
      
      // Notification
      $this->configuration['notification_enabled'] = !empty($values['notification']['notification_enabled']);
      $this->configuration['notification_channels'] = array_filter($values['notification']['notification_channels'] ?? []);
      
      // Pickup settings
      $this->configuration['parcelshop_pickup_days'] = $values['pickup_settings']['parcelshop_pickup_days'];
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
    
    // Vérifier si l'adresse est valide pour la recherche de points relais
    if (!$this->isAddressValidForParcelShop($shipment)) {
      return $rates;
    }
    
    // Tarif de base pour ParcelShop Classic
    $classic_amount = new Price($this->configuration['parcelshop_fee'], $currency_code);
    
    // Tarif pour ParcelShop Express (tarif de base + supplément)
    $express_amount = new Price($this->configuration['parcelshop_fee'], $currency_code);
    $express_amount = $express_amount->add(new Price($this->configuration['express_fee'], $currency_code));
    
    // Appliquer la livraison gratuite si seuil atteint
    if ($this->isFreeParcelShop($order)) {
      $classic_amount = new Price('0.00', $currency_code);
      $express_amount = new Price('0.00', $currency_code);
    }
    
    // Service Classic
    $rates[] = new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => new ShippingService('dpd_parcelshop_classic', $this->t('DPD ParcelShop Classic')),
      'amount' => $classic_amount,
      'description' => $this->t('Delivery to ParcelShop in 2-3 working days')
    ]);
    
    // Service Express
    $rates[] = new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => new ShippingService('dpd_parcelshop_express', $this->t('DPD ParcelShop Express')),
      'amount' => $express_amount,
      'description' => $this->t('Delivery to ParcelShop by 10:00 AM next working day')
    ]);
    
    return $rates;
  }
  
  /**
   * Check if address is valid for ParcelShop search.
   */
  protected function isAddressValidForParcelShop(ShipmentInterface $shipment): bool {
    $shipping_profile = $shipment->getShippingProfile();
    if (!$shipping_profile) {
      return FALSE;
    }
    
    $address = $shipping_profile->get('address')->first();
    if (!$address) {
      return FALSE;
    }
    
    // Vérifier que le pays est supporté par DPD
    $supported_countries = [
      'DE',
      'AT',
      'CH',
      'FR',
      'BE',
      'NL',
      'LU',
      'ES',
      'IT',
      'GB'
    ];
    return in_array($address->getCountryCode(), $supported_countries);
  }
  
  /**
   * Check if order qualifies for free ParcelShop shipping.
   */
  protected function isFreeParcelShop($order): bool {
    if (empty($this->configuration['free_parcelshop_threshold'])) {
      return FALSE;
    }
    
    $threshold = new Price($this->configuration['free_parcelshop_threshold'], 'EUR');
    $order_total = $order->getTotalPrice();
    
    return $order_total && $order_total->greaterThanOrEqual($threshold);
  }
  
  /**
   * Get available ParcelShops for an address.
   */
  public function getAvailableParcelShops(ShipmentInterface $shipment): array {
    $cache_key = 'dpd_parcelshops:' . $shipment->id();
    $cache = $this->cache->get($cache_key);
    
    if ($cache) {
      return $cache->data;
    }
    
    $shipping_profile = $shipment->getShippingProfile();
    if (!$shipping_profile) {
      return [];
    }
    
    $address = $shipping_profile->get('address')->first();
    if (!$address) {
      return [];
    }
    
    try {
      $shops = $this->dpdApiClient->findParcelShops(
        [
          'country' => $address->getCountryCode(),
          'zipCode' => $address->getPostalCode(),
          'city' => $address->getLocality(),
          'street' => $address->getAddressLine1(),
          'limit' => $this->configuration['default_results_limit'],
          'hideClosed' => $this->configuration['hide_closed_shops'],
          'availabilityDate' => date('Y-m-d H:i')
        ]);
      
      // Cache pour 1 heure
      $this->cache->set($cache_key, $shops, time() + 3600);
      
      return $shops;
    }
    catch (\Exception $e) {
      \Drupal::logger('commerce_dpd')->error('Failed to fetch ParcelShops: @error', [
        '@error' => $e->getMessage()
      ]);
      return [];
    }
  }
  
  /**
   * Check if ParcelShop selection is required for this shipment.
   */
  public function isParcelShopSelectionRequired(ShipmentInterface $shipment): bool {
    return $this->configuration['parcelshop_required'] && $shipment->getShippingService() && strpos($shipment->getShippingService(), 'dpd_parcelshop') === 0;
  }
  
  /**
   * Get DPD product code for ParcelShop service.
   */
  public function getProductCodeForService($service_id): string {
    $mapping = [
      'dpd_parcelshop_classic' => 'CL',
      'dpd_parcelshop_express' => 'E10'
    ];
    
    return $mapping[$service_id] ?? 'CL';
  }
  
  /**
   * Check if service is ParcelShop express.
   */
  public function isExpressService($service_id): bool {
    return $service_id === 'dpd_parcelshop_express';
  }
}