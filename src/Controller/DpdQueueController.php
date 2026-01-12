<?php

namespace Drupal\commerce_dpd\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_dpd\Service\DpdLabelManager;

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
  public function __construct(QueueFactory $queue_factory, QueueWorkerManagerInterface $queue_worker_manager, private DpdLabelManager $label_manager) {
    $this->queueFactory = $queue_factory;
    $this->queueWorkerManager = $queue_worker_manager;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('queue'), $container->get('plugin.manager.queue_worker'), $container->get('commerce_dpd.label_manager'));
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
   * Queue DPD label generation (respects API rate limits).
   */
  public function generateLabelNow(OrderInterface $commerce_order) {
    // 1. Vérifier si étiquettes existent déjà
    if ($this->hasDpdLabels($commerce_order)) {
      $this->messenger()->addWarning($this->t('DPD labels already exist for this order.'));
      return $this->redirectToOrder($commerce_order);
    }
    // 2. Ajouter à la queue
    $queue = $this->queueFactory->get('commerce_dpd_label_generation');
    $queue->createItem([
      'order_id' => $commerce_order->id(),
      'manual' => true,
      'timestamp' => time(),
      'user_id' => $this->currentUser()->id()
    ]);
    $this->messenger()->addStatus($this->t('DPD label generation has been queued and will be processed shortly.'));
    return $this->redirectToOrder($commerce_order);
  }
  
  /**
   * Check if order already has DPD labels.
   */
  protected function hasDpdLabels(OrderInterface $order): bool {
    if (!$order->hasField('shipments') || $order->get('shipments')->isEmpty()) {
      return FALSE;
    }
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      /**
       *
       * @var \Drupal\commerce_shipping\Entity\Shipment $shipment
       */
      if ($this->label_manager->hasLabel($shipment)) {
        return TRUE;
      }
    }
    return FALSE;
  }
  
  /**
   * Helper to redirect to order page.
   */
  protected function redirectToOrder(OrderInterface $order): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('entity.commerce_order.canonical', [
      'commerce_order' => $order->id()
    ])->toString());
  }
}