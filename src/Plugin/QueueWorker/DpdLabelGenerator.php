<?php

namespace Drupal\commerce_dpd\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_dpd\Service\DpdLabelManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Generates DPD labels asynchronously.
 *
 * @QueueWorker(
 *   id = "commerce_dpd_label_generation",
 *   title = @Translation("DPD Label Generator"),
 *   cron = {"time" = 90}
 * )
 */
class DpdLabelGenerator extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  protected DpdLabelManager $labelManager;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected EntityTypeManagerInterface $entityTypeManager;
  
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DpdLabelManager $label_manager, LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->labelManager = $label_manager;
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
  }
  
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('commerce_dpd.label_manager'), $container->get('logger.factory'), $container->get('entity_type.manager'));
  }
  
  /**
   * Processes a single queue item.
   *
   * @param mixed $data
   *        The data from the queue item.
   */
  public function processItem($data) {
    // 1. Récupérer l'ID de la commande
    $order_id = $data['order_id'] ?? null;
    
    if (!$order_id) {
      $this->loggerFactory->get('commerce_dpd')->error('Queue item missing order ID. Data: @data', [
        '@data' => print_r($data, TRUE)
      ]);
      return;
    }
    
    // 2. Charger la commande
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    
    if (!$order) {
      $this->loggerFactory->get('commerce_dpd')->error('Order @id not found for DPD label generation.', [
        '@id' => $order_id
      ]);
      return;
    }
    
    $logger = $this->loggerFactory->get('commerce_dpd');
    
    try {
      // 3. Vérifier si c'est une commande DPD
      if (!$this->isDpdOrder($order)) {
        $logger->info('Order @id is not a DPD order, skipping.', [
          '@id' => $order_id
        ]);
        return;
      }
      
      // 4. Vérifier si les étiquettes ont déjà été générées
      if ($this->hasLabelsAlready($order)) {
        $logger->info('Order @id already has DPD labels, skipping.', [
          '@id' => $order_id
        ]);
        return;
      }
      
      // 5. Générer les étiquettes
      $results = $this->labelManager->generateLabelsForOrder($order);
      
      // 6. Logger les résultats
      $success_count = 0;
      $failure_count = 0;
      
      foreach ($results as $result) {
        if ($result['success']) {
          $success_count++;
          $logger->info('DPD label generated for shipment @shipment. Tracking: @tracking', [
            '@shipment' => $result['shipment']->id(),
            '@tracking' => $result['label']->getTrackingNumber()
          ]);
        }
        else {
          $failure_count++;
          $logger->error('Failed to generate DPD label for shipment @shipment: @error', [
            '@shipment' => $result['shipment']->id(),
            '@error' => $result['error']
          ]);
        }
      }
      
      // 7. Notifier si nécessaire
      if ($success_count > 0) {
        $this->notifyAdmin($order, $success_count, $failure_count);
      }
    }
    catch (\Exception $e) {
      $logger->error('DPD label generation failed for order @id: @error', [
        '@id' => $order_id,
        '@error' => $e->getMessage()
      ]);
      
      // Optionnel: réessayer plus tard
      if (isset($data['retry_count'])) {
        $data['retry_count']++;
      }
      else {
        $data['retry_count'] = 1;
      }
      
      // Réessayer jusqu'à 3 fois max
      if ($data['retry_count'] <= 3) {
        // Re-mettre dans la queue pour réessai
        $queue = \Drupal::queue('commerce_dpd_label_generation');
        $queue->createItem($data);
        
        $logger->warning('Re-queuing order @id for retry (@retry/3)', [
          '@id' => $order_id,
          '@retry' => $data['retry_count']
        ]);
      }
    }
  }
  
  /**
   * Checks if order uses DPD shipping.
   */
  private function isDpdOrder($order): bool {
    $shipments = $order->get('shipments')->referencedEntities();
    
    foreach ($shipments as $shipment) {
      $shipping_method = $shipment->getShippingMethod();
      if ($shipping_method && strpos($shipping_method->getPlugin()->getPluginId(), 'dpd') !== false) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Checks if labels already exist for order.
   */
  private function hasLabelsAlready($order): bool {
    $shipments = $order->get('shipments')->referencedEntities();
    
    foreach ($shipments as $shipment) {
      if ($this->labelManager->hasLabel($shipment)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Notifies admin about label generation.
   */
  private function notifyAdmin($order, $success_count, $failure_count): void {
    $config = \Drupal::config('commerce_dpd.settings');
    if ($config->get('notification.enabled')) {
      $mail_manager = \Drupal::service('plugin.manager.mail');
      $params = [
        'order' => $order,
        'success_count' => $success_count,
        'failure_count' => $failure_count
      ];
      $mail_manager->mail('commerce_dpd', 'label_generated', $config->get('notification.email') ?: \Drupal::config('system.site')->get('mail'), 'fr', $params);
    }
  }
}