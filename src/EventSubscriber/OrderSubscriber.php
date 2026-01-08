<?php

namespace Drupal\commerce_dpd\EventSubscriber;

use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Queue\QueueFactory;

class OrderSubscriber implements EventSubscriberInterface {
  protected QueueFactory $queueFactory;
  
  public function __construct(QueueFactory $queue_factory) {
    $this->queueFactory = $queue_factory;
  }
  
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => [
        'onOrderPlace',
        -100
      ]
    ];
  }
  
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    $order = $event->getEntity();
    
    // Vérifier si auto-génération est activée
    $config = \Drupal::config('commerce_dpd.settings');
    if (!$config->get('auto_generate_labels')) {
      return;
    }
    
    // Vérifier si c'est une commande DPD
    if (!$this->isDpdOrder($order)) {
      return;
    }
    
    // Ajouter à la queue
    $queue = $this->queueFactory->get('commerce_dpd_label_generation');
    $queue->createItem([
      'order_id' => $order->id(),
      'user_id' => $order->getCustomerId(),
      'timestamp' => time(),
      'source' => 'order_place'
    ]);
    
    \Drupal::logger('commerce_dpd')->info('Order @id queued for DPD label generation.', [
      '@id' => $order->id()
    ]);
  }
  
  private function isDpdOrder($order): bool {
    // Logique de vérification DPD
    foreach ($order->get('shipments')->referencedEntities() as $shipment) {
      $method = $shipment->getShippingMethod();
      if ($method && strpos($method->getPlugin()->getPluginId(), 'dpd') !== false) {
        return true;
      }
    }
    return false;
  }
}