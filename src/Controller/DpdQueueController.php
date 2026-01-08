<?php

namespace Drupal\commerce_dpd\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for DPD queue operations.
 */
class DpdQueueController extends ControllerBase {
  
  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;
  
  /**
   * The queue worker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorkerManager;
  
  /**
   * Constructs a new DpdQueueController.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *        The queue factory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_worker_manager
   *        The queue worker manager.
   */
  public function __construct(QueueFactory $queue_factory, QueueWorkerManagerInterface $queue_worker_manager) {
    $this->queueFactory = $queue_factory;
    $this->queueWorkerManager = $queue_worker_manager;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('queue'), $container->get('plugin.manager.queue_worker'));
  }
  
  /**
   * Page with generate label button.
   */
  public function generateLabelButton(OrderInterface $commerce_order) {
    $build = [];
    
    // Check if order already has DPD labels
    $has_labels = $this->hasDpdLabels($commerce_order);
    
    if (!$has_labels) {
      $build['generate'] = [
        '#type' => 'link',
        '#title' => $this->t('Generate DPD Label'),
        '#url' => Url::fromRoute('commerce_dpd.generate_label_now', [
          'commerce_order' => $commerce_order->id()
        ]),
        '#attributes' => [
          'class' => [
            'button',
            'button--primary'
          ]
        ]
      ];
    }
    else {
      $build['message'] = [
        '#markup' => $this->t('DPD labels already generated for this order.'),
        '#prefix' => '<div class="messages messages--status">',
        '#suffix' => '</div>'
      ];
    }
    
    return $build;
  }
  
  /**
   * Generate DPD label immediately.
   */
  public function generateLabelNow(OrderInterface $commerce_order) {
    $queue = $this->queueFactory->get('commerce_dpd_label_generation');
    
    // Add to queue
    $queue_item_id = $queue->createItem([
      'order_id' => $commerce_order->id(),
      'manual' => TRUE,
      'timestamp' => time(),
      'user_id' => $this->currentUser()->id()
    ]);
    
    // Process immediately
    $this->processQueueItem($commerce_order->id());
    
    $this->messenger()->addStatus($this->t('DPD label generation initiated for order @order.', [
      '@order' => $commerce_order->getOrderNumber()
    ]));
    
    return new RedirectResponse(Url::fromRoute('entity.commerce_order.canonical', [
      'commerce_order' => $commerce_order->id()
    ])->toString());
  }
  
  /**
   * Process a specific queue item immediately.
   */
  protected function processQueueItem($order_id) {
    $queue = $this->queueFactory->get('commerce_dpd_label_generation');
    
    try {
      $worker = $this->queueWorkerManager->createInstance('commerce_dpd_label_generation');
      
      // Find and process the specific item
      $items = $queue->claimItems(10); // Get up to 10 items
      foreach ($items as $item) {
        if ($item->data['order_id'] == $order_id) {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          break;
        }
        $queue->releaseItem($item); // Release other items
      }
    }
    catch (\Exception $e) {
      $this->logger('commerce_dpd')->error('Error processing DPD label queue: @error', [
        '@error' => $e->getMessage()
      ]);
    }
  }
  
  /**
   * Check if order already has DPD labels.
   */
  protected function hasDpdLabels(OrderInterface $order): bool {
    if (!$order->hasField('shipments') || $order->get('shipments')->isEmpty()) {
      return FALSE;
    }
    
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      if ($shipment->get('dpd_label_data')->value) {
        return TRUE;
      }
    }
    
    return FALSE;
  }
}