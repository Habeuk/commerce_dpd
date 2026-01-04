<?php

namespace Drupal\commerce_dpd\Plugin\Commerce\CheckoutPane;

use Drupal\commerce\AjaxFormTrait;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_dpd\ApiClient\DpdApiClientInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Allows selecting a DPD ParcelShop during checkout.
 *
 * @CommerceCheckoutPane(
 *   id = "dpd_parcelshop",
 *   label = @Translation("DPD ParcelShop"),
 *   default_step = "order_information",
 *   wrapper_element = "fieldset",
 * )
 */
final class DpdParcelShopPane extends CheckoutPaneBase implements ContainerFactoryPluginInterface {
  
  use AjaxFormTrait;
  /**
   *
   * @var \Drupal\commerce_dpd\ApiClient\DpdApiClient
   */
  protected $dpdApiClient;
  
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->dpdApiClient = $container->get('commerce_dpd.api_client');
    return $instance;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function isVisible() {
    return $this->isDpdParcelShopSelected();
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    // Wrapper AJAX comme ShippingInformation.
    $pane_form['#wrapper_id'] = 'dpd-parcelshop-wrapper';
    $pane_form['#prefix'] = '<div id="' . $pane_form['#wrapper_id'] . '">';
    $pane_form['#suffix'] = '</div>';
    
    // Rafraîchir si on change de méthode de livraison ou si on recalcule.
    $pane_form['#after_build'][] = [
      static::class,
      'attachAjaxRefresh'
    ];
    $shops = [];
    $profile = $this->getShippingProfile($form_state);
    if (!$profile || !$this->hasValidAddress($profile)) {
      $pane_form['message'] = [
        '#markup' => $this->t('Enter your shipping address to see DPD pickup points.')
      ];
      return $pane_form;
    }
    $address = $profile->get('address')->first();
    if ($address) {
      try {
        $criteria = [
          'country' => $address->getCountryCode(),
          'zipCode' => $address->getPostalCode(),
          'city' => $address->getLocality(),
          // 'street' => $address->getAddressLine1(),
          'limit' => 10,
          'hideClosed' => TRUE
        ];
        $shops = $this->dpdApiClient->findParcelShops($criteria);
      }
      catch (\Throwable $e) {
        // Pas d’erreur bloquante : on laisse l’utilisateur continuer,
        // mais on lui affiche un message utile.
        \Drupal::logger('commerce_dpd')->error('DPD Commerce PANE ParcelShopFinder ERROR (@code): @message', [
          '@code' => $e->getCode() ?? 'UNKNOWN',
          '@message' => $e->getMessage()
        ]);
        $this->messenger()->addError($this->t('Unable to load DPD ParcelShops. Please try again. : ' . $e->getMessage()));
      }
    }
    
    $selected_id = (string) $this->order->getData('dpd_parcelshop_id');
    
    $pane_form['selected_parcelshop'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose a pickup point'),
      '#options' => $this->buildOptions($shops),
      '#default_value' => $selected_id ?: NULL,
      '#required' => TRUE,
      '#description' => $address ? $this->t('Pickup points are shown based on your shipping address.') : $this->t('Enter a shipping address to see nearby pickup points.'),
      // Si l’adresse n’est pas encore valide, ne bloque pas tout de suite.
      '#validated' => (bool) $address
    ];
    
    // On garde aussi les données complètes (optionnel).
    $pane_form['parcelshop_data'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $this->order->getData('dpd_parcelshop_data')
    ];
    return $pane_form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    if (!$this->isDpdParcelShopSelected()) {
      return;
    }
    
    $selected = $form_state->getValue([
      'selected_parcelshop'
    ]);
    if (empty($selected) && $pane_form['selected_parcelshop']) {
      $form_state->setError($pane_form['selected_parcelshop'], $this->t('Please select a DPD ParcelShop.'));
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    if (!$this->isDpdParcelShopSelected()) {
      // Nettoyage si l’utilisateur change de méthode.
      $this->order->setData('dpd_parcelshop_id', NULL);
      $this->order->setData('dpd_parcelshop_data', NULL);
      return;
    }
    
    $selected = (string) $form_state->getValue([
      'selected_parcelshop'
    ]);
    $this->order->setData('dpd_parcelshop_id', $selected);
    
    // Optionnel : stocker les infos en JSON (si tu as la data).
    // Ici on ne l’a pas reconstruite côté radios pour rester simple.
  }
  
  /**
   * After_build: s'assure que certains changements refresh ce pane via AJAX.
   */
  public static function attachAjaxRefresh(array $element, FormStateInterface $form_state) {
    // Si le checkout "Recalculate shipping" existe, on s'aligne dessus.
    // Sinon on ne fait rien.
    // On reste volontairement simple ici : le pane sera rebuild à chaque
    // rebuild du checkout.
    return $element;
  }
  
  private function isDpdParcelShopSelected(): bool {
    return TRUE;
  }
  
  private function buildOptions(array $shops): array {
    $options = [];
    
    foreach ($shops as $shop) {
      /**
       *
       * @var \Drupal\commerce_dpd\DpdData\ParcelShop $shop
       */
      $id = $shop->getId();
      if ($id === '') {
        continue;
      }
      $company = (string) $shop->getCompany() ?? '';
      $street = (string) $shop->getAddress()?->getStreet() ?? '';
      $zip = (string) $shop->zipCode ?? '';
      $city = (string) $shop->city ?? '';
      $options[$id] = trim($company . ' — ' . $street . ', ' . $zip . ' ' . $city);
    }
    return $options;
  }
  
  protected function hasValidAddress(ProfileInterface $profile): bool {
    if (!$profile->hasField('address') || $profile->get('address')->isEmpty()) {
      return FALSE;
    }
    return count($profile->get('address')->validate()) === 0;
  }
  
  private function getShippingProfile(FormStateInterface $form_state): ?ProfileInterface {
    if ($form_state->has('shipping_profile')) {
      return $form_state->get('shipping_profile');
    }
    
    if ($this->order->hasField('shipping_profile') && !$this->order->get('shipping_profile')->isEmpty()) {
      return $this->order->get('shipping_profile')->entity;
    }
    
    return NULL;
  }
}
