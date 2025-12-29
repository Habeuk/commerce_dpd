<?php

namespace Drupal\commerce_dpd\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for searching DPD shipments.
 */
class DpdShipmentSearchForm extends FormBase {
  
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;
  
  /**
   * Constructs a new DpdShipmentSearchForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *        The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_dpd_shipment_search';
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $request = $this->getRequest();
    $search_params = $request->query->all();
    
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('commerce_dpd.shipments')->toString();
    
    $form['search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Search Filters'),
      '#attributes' => [
        'class' => [
          'search-filters'
        ]
      ]
    ];
    
    // Tracking number
    $form['search']['tracking_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tracking Number'),
      '#default_value' => $search_params['tracking_number'] ?? '',
      '#size' => 20,
      '#maxlength' => 64
    ];
    
    // Order ID
    $form['search']['order_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Order ID'),
      '#default_value' => $search_params['order_id'] ?? '',
      '#min' => 1,
      '#size' => 10
    ];
    
    // Order number (order number from commerce_order)
    $form['search']['order_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order Number'),
      '#default_value' => $search_params['order_number'] ?? '',
      '#size' => 20
    ];
    
    // Status filter
    $form['search']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        '' => $this->t('- Any -'),
        'created' => $this->t('Created'),
        'printed' => $this->t('Printed'),
        'in_transit' => $this->t('In Transit'),
        'delivered' => $this->t('Delivered'),
        'error' => $this->t('Error')
      ],
      '#default_value' => $search_params['status'] ?? ''
    ];
    
    // Date range
    $form['search']['date'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Date Range'),
      '#attributes' => [
        'class' => [
          'container-inline'
        ]
      ]
    ];
    
    $form['search']['date']['date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('From'),
      '#default_value' => $search_params['date_from'] ?? ''
    ];
    
    $form['search']['date']['date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('To'),
      '#default_value' => $search_params['date_to'] ?? ''
    ];
    
    // Parcelshop ID
    $form['search']['parcelshop_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parcelshop ID'),
      '#default_value' => $search_params['parcelshop_id'] ?? '',
      '#size' => 20
    ];
    
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => [
          'container-inline'
        ]
      ]
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary'
    ];
    
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('commerce_dpd.shipments'),
      '#attributes' => [
        'class' => [
          'button'
        ]
      ]
    ];
    
    return $form;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This form uses GET method, so submission is handled by the URL parameters
    // We don't need to do anything here as the form redirects with GET
    // parameters
  }
  
  /**
   * Builds the search query based on parameters.
   *
   * @param array $parameters
   *        The search parameters.
   *        
   * @return \Drupal\Core\Database\Query\SelectInterface The query object.
   */
  public function buildSearchQuery(array $parameters) {
    $query = $this->database->select('commerce_dpd_shipments', 'cds')->fields('cds');
    
    // Filter by tracking number
    if (!empty($parameters['tracking_number'])) {
      $query->condition('cds.tracking_number', '%' . $this->database->escapeLike($parameters['tracking_number']) . '%', 'LIKE');
    }
    
    // Filter by order ID
    if (!empty($parameters['order_id'])) {
      $query->condition('cds.order_id', $parameters['order_id']);
    }
    
    // Filter by status
    if (!empty($parameters['status'])) {
      $query->condition('cds.status', $parameters['status']);
    }
    
    // Filter by parcelshop ID
    if (!empty($parameters['parcelshop_id'])) {
      $query->condition('cds.parcelshop_id', $parameters['parcelshop_id']);
    }
    
    // Filter by date range
    if (!empty($parameters['date_from'])) {
      $date_from = strtotime($parameters['date_from'] . ' 00:00:00');
      $query->condition('cds.created', $date_from, '>=');
    }
    
    if (!empty($parameters['date_to'])) {
      $date_to = strtotime($parameters['date_to'] . ' 23:59:59');
      $query->condition('cds.created', $date_to, '<=');
    }
    
    // Filter by order number (requires join with commerce_order)
    if (!empty($parameters['order_number'])) {
      $query->leftJoin('commerce_order', 'co', 'cds.order_id = co.order_id');
      $query->condition('co.order_number', '%' . $this->database->escapeLike($parameters['order_number']) . '%', 'LIKE');
    }
    
    $query->orderBy('cds.created', 'DESC');
    
    return $query;
  }
}