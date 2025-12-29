<?php

namespace Drupal\commerce_dpd\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the DPD Home Delivery shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "dpd_home_delivery",
 *   label = @Translation("DPD Home Delivery"),
 *   services = {
 *     "dpd_home" = @Translation("DPD Home Delivery"),
 *   },
 * )
 */
class DpdHomeDelivery extends ShippingMethodBase {
  
  /**
   *
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager);
    
    $this->services = [];
    $service = new ShippingService('dpd_home', $this->pluginDefinition['services']['dpd_home']);
    $this->services[$service->getId()] = $service;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $rates = [];
    
    // Get configuration
    $config = \Drupal::config('commerce_dpd.settings');
    
    // Calculate rate based on weight, destination, etc.
    // This is a simplified example - adjust based on DPD pricing
    $weight = $shipment->getWeight()->getNumber();
    
    // Base price + price per kg
    $base_price = new Price('5.90', 'EUR');
    $price_per_kg = new Price('0.50', 'EUR');
    
    $total = $base_price->add($price_per_kg->multiply((string) $weight));
    
    $rates[] = new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => $this->services['dpd_home'],
      'amount' => $total
    ]);
    
    return $rates;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    
    $form['home_delivery_price'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Base price for home delivery'),
      '#default_value' => [
        'number' => '5.90',
        'currency_code' => 'EUR'
      ],
      '#required' => TRUE
    ];
    
    $form['price_per_kg'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Price per kg'),
      '#default_value' => [
        'number' => '0.50',
        'currency_code' => 'EUR'
      ],
      '#required' => TRUE
    ];
    
    return $form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['home_delivery_price'] = $values['home_delivery_price'];
    $this->configuration['price_per_kg'] = $values['price_per_kg'];
  }
}