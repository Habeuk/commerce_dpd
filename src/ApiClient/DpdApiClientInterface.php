<?php

namespace Drupal\commerce_dpd\ApiClient;

use Drupal\commerce_dpd\DpdData\Label;

/**
 * Interface for DPD SOAP API client.
 *
 * This interface defines the low-level technical operations used to communicate
 * with DPD Web Connect services (SOAP).
 *
 * Responsibilities:
 * - Call DPD SOAP endpoints (ShipmentService, ParcelShopFinder).
 * - Transmit raw data structures expected by DPD.
 * - Return raw API responses without business logic.
 *
 * This interface MUST NOT:
 * - Contain Drupal Commerce logic.
 * - Create or modify entities.
 * - Handle checkout workflows.
 * - Cache authentication tokens (handled by DpdAuthTokenManager).
 */
interface DpdApiClientInterface {
  
  /**
   * Return token
   *
   * @return string
   */
  public function getToken(): string;
  
  /**
   * Sends shipment orders to DPD and generates shipping labels.
   *
   * This method calls the DPD ShipmentService (storeOrders).
   * The orders MUST be provided in the exact structure expected by DPD.
   *
   * Important:
   * - Calls MUST be sequential (one request at a time).
   * - The authentication token is automatically retrieved and refreshed.
   * - Address data must already be normalized (ISO-8859-1).
   *
   * @param array $orders
   *        List of shipment orders formatted for the DPD ShipmentService.
   *        Each order represents one shipment.
   *        
   * @return array Raw response returned by the DPD ShipmentService.
   *        
   * @throws \SoapFault Thrown when a SOAP communication error occurs.
   * @throws \Throwable Thrown when authentication or unexpected errors occur.
   */
  public function storeOrders(array $orders): Label;
  
  /**
   * Retrieves a list of available DPD Pickup ParcelShops or Pickup Stations.
   *
   * This method calls the DPD ParcelShopFinder service and must be used
   * during the checkout process when a ParcelShop delivery is selected.
   *
   * Important:
   * - This method MUST NOT cache ParcelShop results long-term.
   * - The returned ParcelShop ID (PUDO ID) must be stored with the order.
   *
   * @param array $criteria
   *        Search criteria such as address, ZIP code, country, availability
   *        date,
   *        and service codes.
   *        
   * @return array Raw list of ParcelShops returned by the DPD API.
   *        
   * @throws \SoapFault Thrown when a SOAP communication error occurs.
   */
  public function findParcelShops(array $criteria): array;
  
  /**
   * Returns the last SOAP request XML sent to the DPD API.
   *
   * This method is intended for debugging and validation purposes only.
   * It MUST NOT be exposed to end users or logged permanently without masking
   * sensitive information (authToken, personal data).
   *
   * @return string Raw SOAP XML request.
   */
  public function getLastRequestShipmentClient(): string|null;
  
  /**
   * Returns the last SOAP response XML received from the DPD API.
   *
   * This method is intended for debugging and validation purposes only.
   * It MUST NOT be exposed to end users or logged permanently without masking
   * sensitive information.
   *
   * @return string Raw SOAP XML response.
   */
  public function getLastResponseShipmentClient(): string|null;
}
