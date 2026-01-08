<?php

namespace Drupal\commerce_dpd\DpdData;

/**
 * Represents a DPD shipping label.
 */
class Label {
  protected string $trackingNumber;
  protected string $shipmentId;
  protected string $parcelLabelNumber;
  protected array $labels; // PDF data
  protected ?string $mpsId;
  protected array $response; // Raw API response
  protected \DateTimeInterface $createdAt;
  
  public function __construct(array $data) {
    $this->trackingNumber = $data['tracking_number'];
    $this->shipmentId = $data['shipment_id'];
    $this->parcelLabelNumber = $data['parcel_label_number'];
    $this->labels = $data['labels'] ?? [];
    $this->mpsId = $data['mps_id'] ?? null;
    $this->response = $data['response'] ?? [];
    $this->createdAt = new \DateTimeImmutable();
  }
  
  public function getTrackingNumber(): string {
    return $this->trackingNumber;
  }
  
  public function getShipmentId(): string {
    return $this->shipmentId;
  }
  
  public function getParcelLabelNumber(): string {
    return $this->parcelLabelNumber;
  }
  
  public function getLabels(): array {
    return $this->labels;
  }
  
  public function getFirstPdf(): ?string {
    foreach ($this->labels as $label) {
      if ($label['format'] === 'PDF' && !empty($label['label_pdf'])) {
        return $label['label_pdf'];
      }
    }
    return null;
  }
  
  public function getMpsId(): ?string {
    return $this->mpsId;
  }
  
  public function getResponse(): array {
    return $this->response;
  }
  
  public function getCreatedAt(): \DateTimeInterface {
    return $this->createdAt;
  }
  
  public function toArray(): array {
    return [
      'tracking_number' => $this->trackingNumber,
      'shipment_id' => $this->shipmentId,
      'parcel_label_number' => $this->parcelLabelNumber,
      'labels' => $this->labels,
      'mps_id' => $this->mpsId,
      'response' => $this->response,
      'created_at' => $this->createdAt->getTimestamp()
    ];
  }
  
  public static function createFromResponse(array $api_response): self {
    // Transforme la réponse API en objet Label
    $data = [
      'tracking_number' => self::extractTrackingNumber($api_response),
      'shipment_id' => $api_response['shipmentId'] ?? '',
      'parcel_label_number' => $api_response['parcelLabelNumber'] ?? '',
      'labels' => self::extractLabels($api_response),
      'mps_id' => $api_response['mpsId'] ?? null,
      'response' => $api_response
    ];
    
    return new self($data);
  }
  
  private static function extractTrackingNumber(array $response): string {
    // Logique d'extraction du numéro de suivi
    if (!empty($response['parcelLabelNumber'])) {
      return $response['parcelLabelNumber'];
    }
    if (!empty($response['trackingNumber'])) {
      return $response['trackingNumber'];
    }
    if (!empty($response['shipmentId'])) {
      return $response['shipmentId'];
    }
    throw new \RuntimeException('No tracking number found in DPD response.');
  }
  
  private static function extractLabels(array $response): array {
    $labels = [];
    
    if (!empty($response['parcelInformation'])) {
      foreach ($response['parcelInformation'] as $parcel) {
        if (!empty($parcel['label'])) {
          $labels[] = [
            'parcel_number' => $parcel['parcelNo'] ?? '1',
            'label_pdf' => $parcel['label'],
            'format' => 'PDF',
            'size' => $parcel['labelSize'] ?? 'A6'
          ];
        }
      }
    }
    
    if (empty($labels) && !empty($response['label'])) {
      $labels[] = [
        'parcel_number' => $response['parcelLabelNumber'] ?? '1',
        'label_pdf' => $response['label'],
        'format' => 'PDF',
        'size' => $response['labelSize'] ?? 'A6'
      ];
    }
    
    return $labels;
  }
}