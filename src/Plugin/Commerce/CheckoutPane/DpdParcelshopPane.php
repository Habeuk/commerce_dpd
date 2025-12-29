<?php

namespace Drupal\commerce_dpd\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;

/**
 * Provides the DPD Parcelshop selection pane.
 *
 * @CommerceCheckoutPane(
 *   id = "dpd_parcelshop",
 *   label = @Translation("DPD Parcelshop"),
 *   default_step = "order_information",
 *   wrapper_element = "fieldset",
 * )
 */
class DpdParcelshopPane extends CheckoutPaneBase {
  
  /**
   *
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'required' => TRUE
    ] + parent::defaultConfiguration();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildConfigurationSummary() {
    $summary = parent::buildConfigurationSummary();
    if ($this->configuration['required']) {
      $summary .= $this->t('<br>Required: Yes');
    }
    else {
      $summary .= $this->t('<br>Required: No');
    }
    return $summary;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    
    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required'),
      '#default_value' => $this->configuration['required']
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
    $this->configuration['required'] = $values['required'];
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    // Only show if DPD Parcelshop shipping method is selected
    $shipments = $this->order->get('shipments')->referencedEntities();
    
    foreach ($shipments as $shipment) {
      if ($shipment->getShippingMethod()->getPluginId() === 'dpd_parcelshop') {
        $pane_form['parcelshop_id'] = [
          '#type' => 'hidden',
          '#default_value' => '',
          '#attributes' => [
            'id' => 'dpd-parcelshop-id'
          ]
        ];
        
        $pane_form['parcelshop_info'] = [
          '#type' => 'markup',
          '#markup' => '<div id="dpd-parcelshop-widget"></div>'
        ];
        
        $pane_form['#attached']['library'][] = 'commerce_dpd/dpd_widget';
        break;
      }
    }
    
    return $pane_form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);
    
    if ($this->configuration['required'] && empty($values['parcelshop_id'])) {
      $form_state->setError($pane_form, $this->t('Please select a DPD parcelshop.'));
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);
    
    // Store parcelshop ID in the order data
    if (!empty($values['parcelshop_id'])) {
      $this->order->setData('dpd_parcelshop_id', $values['parcelshop_id']);
    }
  }
}