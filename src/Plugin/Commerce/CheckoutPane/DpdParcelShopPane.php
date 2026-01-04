<?php

namespace Drupal\commerce_dpd\Plugin\Commerce\CheckoutPane;

use Drupal\commerce\AjaxFormTrait;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_dpd\ApiClient\DpdApiClientInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\Core\Render\Markup;

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
    // Wrapper AJAX
    $pane_form['#wrapper_id'] = 'dpd-parcelshop-wrapper';
    $pane_form['#prefix'] = '<div id="' . $pane_form['#wrapper_id'] . '">';
    $pane_form['#suffix'] = '</div>';
    
    // Rafraîchir si on change de méthode de livraison
    $pane_form['#after_build'][] = [
      static::class,
      'attachAjaxRefresh'
    ];
    
    $shops = [];
    $map_data = [];
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
          'limit' => 10,
          'hideClosed' => TRUE
        ];
        $shops = $this->dpdApiClient->findParcelShops($criteria);
        // Prépare les données pour la carte
        foreach ($shops as $shop) {
          if ($shop->getCoordinates()) {
            $map_data[] = [
              'id' => $shop->getId(),
              'lat' => $shop->getCoordinates()->getLatitude(),
              'lon' => $shop->getCoordinates()->getLongitude(),
              'name' => $shop->getCompany(),
              'address' => $shop->getAddress() ? $shop->getAddress()?->getFormatted() : '',
              'distance' => round($shop->getDistance(), 2),
              'opening_hours' => $this->formatOpeningHours($shop->getOpeningHours())
            ];
          }
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('commerce_dpd')->error('DPD Commerce PANE ParcelShopFinder ERROR (@code): @message', [
          '@code' => $e->getCode() ?? 'UNKNOWN',
          '@message' => $e->getMessage()
        ]);
        $this->messenger()->addError($this->t('Unable to load DPD ParcelShops. Please try again.'));
      }
    }
    
    $selected_id = (string) $this->order->getData('dpd_parcelshop_id');
    
    // === CONTAINER PRINCIPAL ===
    $pane_form['container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'dpd-parcelshop-container'
        ]
      ]
    ];
    
    // === COLONNE GAUCHE : LISTE ===
    $pane_form['container']['list_column'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'dpd-list-column'
        ]
      ]
    ];
    
    $pane_form['container']['list_column']['selected_parcelshop'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose a pickup point'),
      '#options' => $this->buildOptions($shops),
      '#default_value' => $selected_id ?: NULL,
      '#required' => TRUE,
      '#description' => $this->t('Select a pickup point from the list or click on the map.'),
      '#attributes' => [
        'class' => [
          'dpd-parcelshop-radios'
        ],
        'data-map-control' => 'radios'
      ]
    ];
    
    // === COLONNE DROITE : CARTE ===
    $pane_form['container']['map_column'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'dpd-map-column'
        ]
      ]
    ];
    
    // Conteneur pour la carte
    $pane_form['container']['map_column']['map_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'dpd-map-container',
        'class' => [
          'dpd-map-wrapper'
        ]
      ]
    ];
    // Charger les bibliothèques
    $pane_form['#attached'] = [
      'library' => [
        'commerce_dpd/openstreetmap'
      ],
      'drupalSettings' => [
        'commerce_dpd' => [
          'map_points' => $map_data,
          'selected_id' => $selected_id,
          'text' => [
            'select_point' => $this->t('Select this point'),
            'distance' => $this->t('Distance'),
            'no_points' => $this->t('No pickup points found for this address.')
          ]
        ]
      ]
    ];
    
    // Données brutes pour JS
    $pane_form['parcelshop_data'] = [
      '#type' => 'hidden',
      '#default_value' => json_encode($map_data),
      '#attributes' => [
        'id' => 'dpd-parcelshop-data',
        'data-map-control' => 'data-store'
      ]
    ];
    
    return $pane_form;
  }
  
  /**
   * Formate les horaires d'ouverture
   */
  private function formatOpeningHours(array $openingHours): array {
    $formatted = [];
    $days = [
      1 => $this->t('Monday'),
      2 => $this->t('Tuesday'),
      3 => $this->t('Wednesday'),
      4 => $this->t('Thursday'),
      5 => $this->t('Friday'),
      6 => $this->t('Saturday'),
      7 => $this->t('Sunday')
    ];
    
    foreach ($openingHours as $hours) {
      $dayNum = $hours->getWeekdayNum();
      if ($dayNum >= 1 && $dayNum <= 7) {
        $formatted[$days[$dayNum]] = $hours->isDayOff() ? $this->t('Closed') : sprintf('%s - %s', $hours->getOpenMorning(), $hours->getCloseEvening());
      }
    }
    
    return $formatted;
  }
  
  /**
   * Build options for radio buttons
   */
  private function buildOptions(array $shops): array {
    $options = [];
    
    foreach ($shops as $shop) {
      $id = $shop->getId();
      if ($id === '') {
        continue;
      }
      
      $company = $shop->getCompany() ?? '';
      $street = $shop->getAddress()?->getStreet() ?? '';
      $zip = $shop->zipCode ?? '';
      $city = $shop->city ?? '';
      $distance = round($shop->getDistance(), 2);
      
      $options[$id] = Markup::create(
        '<div class="parcelshop-option" data-id="' . $id . '">' . '<strong>' . $company . '</strong><br>' . $street . ', ' . $zip . ' ' . $city . '<br>' . '<small>' . $this->t(
          'Distance: @distance km', [
            '@distance' => $distance
          ]) . '</small>' . '</div>');
    }
    
    return $options;
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
    if (empty($selected) && $pane_form['container']['list_column']['selected_parcelshop']) {
      $form_state->setError($pane_form['container']['list_column']['selected_parcelshop'], $this->t('Please select a DPD ParcelShop.'));
    }
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    if (!$this->isDpdParcelShopSelected()) {
      $this->order->setData('dpd_parcelshop_id', NULL);
      $this->order->setData('dpd_parcelshop_data', NULL);
      return;
    }
    
    $selected = (string) $form_state->getValue([
      'selected_parcelshop'
    ]);
    $this->order->setData('dpd_parcelshop_id', $selected);
    
    // Stocker les données complètes
    $all_data = json_decode($pane_form['parcelshop_data']['#default_value'], true);
    foreach ($all_data as $shop_data) {
      if ($shop_data['id'] == $selected) {
        $this->order->setData('dpd_parcelshop_data', $shop_data);
        break;
      }
    }
  }
  
  /**
   * Attach AJAX refresh
   */
  public static function attachAjaxRefresh(array $element, FormStateInterface $form_state) {
    return $element;
  }
  
  private function isDpdParcelShopSelected(): bool {
    return TRUE;
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