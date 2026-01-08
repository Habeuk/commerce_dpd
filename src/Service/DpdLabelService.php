<?php

namespace Drupal\commerce_dpd\Service;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_dpd\ApiClient\DpdApiClientInterface;
use Drupal\commerce_dpd\DpdData\Label;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for generating DPD shipping labels.
 */
class DpdLabelService {
  private DpdApiClientInterface $dpdApiClient;
  private LoggerChannelFactoryInterface $loggerFactory;
  private ConfigFactoryInterface $configFactory;
  
  public function __construct(DpdApiClientInterface $dpd_api_client, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->dpdApiClient = $dpd_api_client;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }
  
  /**
   * Generates a DPD label for a shipment.
   */
  public function generateLabel(ShipmentInterface $shipment): Label {
    $order = $shipment->getOrder();
    
    // 1. Build DPD order structure
    $dpd_order = $this->buildDpdOrder($shipment);
    
    // 2. Call storeOrders API
    $response = $this->dpdApiClient->storeOrders([
      $dpd_order
    ]);
    
    if (empty($response['shipmentResponses'])) {
      throw new \RuntimeException('DPD returned no shipment response.');
    }
    
    $shipment_response = $response['shipmentResponses'][0];
    
    // 3. Create Label object
    $label = Label::createFromResponse($shipment_response);
    
    // 4. Log success
    $this->loggerFactory->get('commerce_dpd')->info('DPD label generated for order @order. Tracking: @tracking', [
      '@order' => $order->getOrderNumber(),
      '@tracking' => $label->getTrackingNumber()
    ]);
    
    return $label;
  }
  
  /**
   * Builds DPD order structure for API.
   */
  private function buildDpdOrder(ShipmentInterface $shipment): array {
    $order = $shipment->getOrder();
    $shipping_profile = $shipment->getShippingProfile();
    $config = $this->configFactory->get('commerce_dpd.settings');
    
    $dpd_order = [
      'generalShipmentData' => [
        'sendingDepot' => $config->get('depot'),
        'product' => $this->getProductCode($order),
        'sender' => $this->getSenderAddress(),
        'recipient' => $this->getRecipientAddress($shipping_profile, $order)
      ],
      'parcels' => $this->getParcelData($shipment),
      'productAndServiceData' => $this->getServiceData($order, $shipping_profile)
    ];
    
    // Add ParcelShop specific data
    if ($this->isParcelShopOrder($order)) {
      $dpd_order['productAndServiceData']['parcelShop'] = [
        'parcelShopId' => $order->getData('dpd_parcelshop_data')['pudoId'],
        'parcelShopNotification' => [
          'channel' => 2, // 1=email, 2=SMS
          'value' => $shipping_profile->get('phone')->value
        ]
      ];
    }
    
    return $dpd_order;
  }
  
  /**
   * Determines DPD product code based on order type.
   */
  private function getProductCode($order): string {
    if ($this->isParcelShopOrder($order)) {
      return 'CLP'; // ParcelShop
    }
    return 'CL'; // Classic (home delivery)
  }
  
  /**
   * Checks if order uses ParcelShop.
   */
  private function isParcelShopOrder($order): bool {
    return !empty($order->getData('dpd_parcelshop_data'));
  }
  
  /**
   * Gets sender address (your store).
   */
  private function getSenderAddress(): array {
    $config = $this->configFactory->get('commerce_dpd.settings');
    
    return [
      'name1' => $config->get('sender.name'),
      'street' => $config->get('sender.street'),
      'houseNo' => $config->get('sender.house_number'),
      'country' => $config->get('sender.country') ?: 'DE',
      'zipCode' => $config->get('sender.zip_code'),
      'city' => $config->get('sender.city'),
      'phone' => $config->get('sender.phone'),
      'email' => $config->get('sender.email')
    ];
  }
  
  /**
   * Gets recipient address.
   */
  private function getRecipientAddress($shipping_profile, $order): array {
    // For ParcelShop, recipient is the shop itself
    if ($this->isParcelShopOrder($order)) {
      $parcelshop_data = $order->getData('dpd_parcelshop_data');
      return [
        'name1' => $parcelshop_data['name'],
        'street' => $this->extractStreet($parcelshop_data['address']),
        'houseNo' => $this->extractHouseNumber($parcelshop_data['address']),
        'country' => 'FR', // Adjust based on parcelshop country
        'zipCode' => $parcelshop_data['zip_code'],
        'city' => $parcelshop_data['city']
      ];
    }
    
    // For home delivery, recipient is the customer
    $address = $shipping_profile->get('address')->first();
    return [
      'name1' => $address->getGivenName() . ' ' . $address->getFamilyName(),
      'street' => $address->getAddressLine1(),
      'houseNo' => '', // Optional
      'country' => $address->getCountryCode(),
      'zipCode' => $address->getPostalCode(),
      'city' => $address->getLocality()
    ];
  }
  
  /**
   * Gets parcel data.
   */
  private function getParcelData(ShipmentInterface $shipment): array {
    $weight = $shipment->getWeight()->getNumber();
    $config = $this->configFactory->get('commerce_dpd.settings');
    
    return [
      [
        'weight' => max(0.1, $weight ?: $config->get('default_weight') ?: 1.0),
        'customerReferenceNumber1' => $shipment->getOrder()->getOrderNumber(),
        'customerReferenceNumber2' => 'Shipment-' . $shipment->id()
      ]
    ];
  }
  
  /**
   * Gets service data and options.
   */
  private function getServiceData($order, $shipping_profile): array {
    $services = [
      'orderType' => 'consignment'
    ];
    
    // Add notification for ParcelShop
    if ($this->isParcelShopOrder($order)) {
      $phone = $shipping_profile->get('phone')->value;
      if ($phone) {
        $services['notification'] = [
          'channel' => 2, // SMS
          'value' => $phone,
          'language' => 'fr'
        ];
      }
    }
    
    // Add insurance if order value is high
    $order_total = $order->getTotalPrice()->getNumber();
    $config = $this->configFactory->get('commerce_dpd.settings');
    $insurance_threshold = $config->get('insurance_threshold') ?: 500;
    
    if ($order_total > $insurance_threshold) {
      $services['insured'] = [
        'amount' => $order_total,
        'currency' => $order->getTotalPrice()->getCurrencyCode()
      ];
    }
    
    return $services;
  }
  
  /**
   * Helper to extract street from address string.
   */
  private function extractStreet(string $address): string {
    // Simple extraction - adjust based on your address format
    $parts = explode(',', $address);
    return trim($parts[0] ?? $address);
  }
  
  /**
   * Helper to extract house number from address string.
   */
  private function extractHouseNumber(string $address): string {
    // Implement based on your address format
    return '';
  }
}