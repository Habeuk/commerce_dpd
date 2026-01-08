<?php

namespace Drupal\commerce_dpd\Service;

use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_dpd\DpdData\Label;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Manages DPD labels for shipments and orders.
 */
class DpdLabelManager {
  private DpdLabelService $labelService;
  private LoggerChannelFactoryInterface $logger;
  
  public function __construct(DpdLabelService $label_service, LoggerChannelFactoryInterface $logger_factory) {
    $this->labelService = $label_service;
    $this->logger = $logger_factory->get('commerce_dpd');
  }
  
  /**
   * Generates labels for all shipments in an order.
   */
  public function generateLabelsForOrder(OrderInterface $order): array {
    $results = [];
    $shipments = $order->get('shipments')->referencedEntities();
    
    foreach ($shipments as $shipment) {
      try {
        $label = $this->labelService->generateLabel($shipment);
        $this->attachLabelToShipment($shipment, $label);
        
        $results[$shipment->id()] = [
          'success' => true,
          'label' => $label,
          'shipment' => $shipment
        ];
      }
      catch (\Exception $e) {
        $results[$shipment->id()] = [
          'success' => false,
          'error' => $e->getMessage(),
          'shipment' => $shipment
        ];
        $this->logger->error('Failed to generate DPD label for shipment @id: @error', [
          '@id' => $shipment->id(),
          '@error' => $e->getMessage()
        ]);
      }
    }
    
    return $results;
  }
  
  /**
   * Attaches a label to a shipment entity.
   */
  public function attachLabelToShipment(ShipmentInterface $shipment, Label $label): void {
    // Store label data
    $shipment->set('dpd_label_data', serialize($label->toArray()));
    
    // Set tracking code
    $shipment->setTrackingCode($label->getTrackingNumber());
    
    // Add label generation timestamp
    $shipment->setData('dpd_label_generated', time());
    
    $shipment->save();
  }
  
  /**
   * Gets label from shipment.
   */
  public function getLabelFromShipment(ShipmentInterface $shipment): ?Label {
    $label_data = $shipment->get('dpd_label_data')->value;
    
    if (!$label_data) {
      return null;
    }
    
    $data = unserialize($label_data);
    
    if (empty($data['tracking_number'])) {
      return null;
    }
    
    return new Label($data);
  }
  
  /**
   * Gets PDF content from label.
   */
  public function getLabelPdf(ShipmentInterface $shipment): ?string {
    $label = $this->getLabelFromShipment($shipment);
    
    if (!$label) {
      return null;
    }
    
    $pdf_data = $label->getFirstPdf();
    
    if (!$pdf_data) {
      return null;
    }
    
    // PDF might be base64 encoded
    if (base64_encode(base64_decode($pdf_data, true)) === $pdf_data) {
      return base64_decode($pdf_data);
    }
    
    return $pdf_data;
  }
  
  /**
   * Checks if shipment has a DPD label.
   */
  public function hasLabel(ShipmentInterface $shipment): bool {
    return $this->getLabelFromShipment($shipment) !== null;
  }
  
  /**
   * Gets label information for display.
   */
  public function getLabelInfo(ShipmentInterface $shipment): ?array {
    $label = $this->getLabelFromShipment($shipment);
    
    if (!$label) {
      return null;
    }
    
    return [
      'tracking_number' => $label->getTrackingNumber(),
      'shipment_id' => $label->getShipmentId(),
      'created' => $label->getCreatedAt()->format('d/m/Y H:i'),
      'labels_count' => count($label->getLabels()),
      'has_pdf' => $label->getFirstPdf() !== null
    ];
  }
}