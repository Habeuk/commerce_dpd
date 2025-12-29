<?php

namespace Drupal\commerce_dpd\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_dpd\ApiClient\DpdApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Render\RendererInterface;

/**
 * Provides admin controllers for Commerce DPD.
 * 1- Liste des envois DPD (shipmentsList())
 * 2- Téléchargement d'étiquette (downloadLabel())
 * 3- Test de connexion API (testConnection())
 * 4- Visualisation des logs (viewLogs())
 */
class DpdAdminController extends ControllerBase {
  
  /**
   * The DPD API client.
   *
   * @var \Drupal\commerce_dpd\ApiClient\DpdApiClientInterface
   */
  protected $dpdApiClient;
  
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;
  
  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;
  
  /**
   * Constructs a new DpdAdminController object.
   *
   * @param \Drupal\commerce_dpd\ApiClient\DpdApiClientInterface $dpd_api_client
   *        The DPD API client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *        The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *        The messenger.
   * @param \Drupal\Core\Database\Connection $database
   *        The database connection.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *        The renderer.
   */
  public function __construct(DpdApiClientInterface $dpd_api_client, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger, Connection $database, RendererInterface $renderer) {
    $this->dpdApiClient = $dpd_api_client;
    $this->logger = $logger_factory->get('commerce_dpd');
    $this->messenger = $messenger;
    $this->database = $database;
    $this->renderer = $renderer;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('commerce_dpd.api_client'), $container->get('logger.factory'), $container->get('messenger'), $container->get('database'), $container->get('renderer'));
  }
  
  /**
   * Displays a list of DPD shipments.
   *
   * @return array A render array.
   */
  public function shipmentsListOLD() {
    $build = [];
    
    // Get shipments with DPD labels from the database
    $query = $this->database->select('commerce_dpd_shipments', 'cds')->fields('cds')->orderBy('created', 'DESC')->extend('\Drupal\Core\Database\Query\PagerSelectExtender')->limit(50);
    
    $results = $query->execute()->fetchAll();
    
    if (empty($results)) {
      $build['empty'] = [
        '#markup' => $this->t('No DPD shipments found.')
      ];
      return $build;
    }
    
    $rows = [];
    foreach ($results as $shipment) {
      $order = $this->entityTypeManager()->getStorage('commerce_order')->load($shipment->order_id);
      
      $order_link = $order ? Link::fromTextAndUrl($order->getOrderNumber(), $order->toUrl()) : $this->t('Order @id', [
        '@id' => $shipment->order_id
      ]);
      
      $rows[] = [
        'order' => $order_link,
        'tracking_number' => $shipment->tracking_number,
        'status' => $this->getStatusLabel($shipment->status),
        'created' => $this->dateFormatter->format($shipment->created, 'short'),
        'operations' => Link::fromTextAndUrl($this->t('Download'), Url::fromRoute('commerce_dpd.download_label', [
          'commerce_order' => $shipment->order_id,
          'shipment_id' => $shipment->id
        ]))
      ];
    }
    
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Order'),
        $this->t('Tracking Number'),
        $this->t('Status'),
        $this->t('Created'),
        $this->t('Operations')
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No shipments found.')
    ];
    
    $build['pager'] = [
      '#type' => 'pager'
    ];
    
    return $build;
  }
  
  /**
   * Downloads a DPD label.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *        The order.
   * @param int $shipment_id
   *        The shipment ID.
   *        
   * @return \Symfony\Component\HttpFoundation\Response The response.
   */
  public function downloadLabel(OrderInterface $commerce_order, $shipment_id) {
    // Get shipment data from database
    $query = $this->database->select('commerce_dpd_shipments', 'cds')->fields('cds', [
      'label_pdf'
    ])->condition('id', $shipment_id)->condition('order_id', $commerce_order->id())->execute();
    
    $result = $query->fetchAssoc();
    
    if (!$result || empty($result['label_pdf'])) {
      $this->messenger->addError($this->t('Label not found.'));
      return $this->redirect('commerce_dpd.shipments');
    }
    
    // Decode base64 PDF data
    $pdf_data = base64_decode($result['label_pdf']);
    
    // Create temporary file
    $temp_file = tempnam(sys_get_temp_dir(), 'dpd_label_');
    file_put_contents($temp_file, $pdf_data);
    
    // Create response
    $response = new BinaryFileResponse($temp_file);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'dpd-label-' . $shipment_id . '.pdf');
    
    // Clean up temp file after sending
    $response->deleteFileAfterSend(true);
    
    return $response;
  }
  
  /**
   * Tests the DPD API connection.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse A redirect
   *         response.
   */
  public function testConnection() {
    try {
      if ($this->dpdApiClient->authenticate()) {
        $this->messenger->addStatus($this->t('Successfully connected to DPD API.'));
        
        // Try to get service data as additional test
        $last_request = $this->dpdApiClient->getLastRequest();
        $last_response = $this->dpdApiClient->getLastResponse();
        
        $this->logger->info('DPD connection test successful. Last request: @request, Last response: @response', [
          '@request' => $last_request,
          '@response' => $last_response
        ]);
      }
      else {
        $this->messenger->addError($this->t('Failed to connect to DPD API. Check your credentials.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error testing DPD connection: @error', [
        '@error' => $e->getMessage()
      ]));
      $this->logger->error('DPD connection test failed: @error', [
        '@error' => $e->getMessage()
      ]);
    }
    
    return $this->redirect('commerce_dpd.settings_form');
  }
  
  /**
   * Displays DPD logs.
   *
   * @param string $type
   *        The log type (optional).
   *        
   * @return array A render array.
   */
  public function viewLogs($type = NULL) {
    $build = [];
    
    // Query the watchdog table for DPD logs
    $query = $this->database->select('watchdog', 'w')->fields('w')->condition('w.type', 'commerce_dpd')->orderBy('w.wid', 'DESC')->extend('\Drupal\Core\Database\Query\PagerSelectExtender')->limit(100);
    
    if ($type) {
      $query->condition('w.severity', $this->getSeverityLevel($type));
    }
    
    $results = $query->execute()->fetchAll();
    
    if (empty($results)) {
      $build['empty'] = [
        '#markup' => $this->t('No DPD logs found.')
      ];
      return $build;
    }
    
    // Build filter links
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'dpd-logs-filters'
        ]
      ]
    ];
    
    $filters = [
      'all' => $this->t('All'),
      'error' => $this->t('Errors'),
      'warning' => $this->t('Warnings'),
      'info' => $this->t('Info')
    ];
    
    foreach ($filters as $filter_type => $label) {
      $build['filters'][$filter_type] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('commerce_dpd.logs', [
          'type' => $filter_type !== 'all' ? $filter_type : NULL
        ]),
        '#attributes' => [
          'class' => $type === $filter_type || ($filter_type === 'all' && !$type) ? [
            'active'
          ] : []
        ]
      ];
    }
    
    // Build log table
    $rows = [];
    foreach ($results as $log) {
      $variables = $log->variables ? unserialize($log->variables, [
        'allowed_classes' => FALSE
      ]) : [];
      
      $rows[] = [
        'severity' => $this->getSeverityLabel($log->severity),
        'message' => [
          'data' => [
            '#markup' => $this->t($log->message, $variables)
          ]
        ],
        'timestamp' => $this->dateFormatter->format($log->timestamp, 'short'),
        'operations' => $log->link ? [
          'data' => [
            '#markup' => $log->link
          ]
        ] : ''
      ];
    }
    
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Severity'),
        $this->t('Message'),
        $this->t('Timestamp'),
        $this->t('Operations')
      ],
      '#rows' => $rows
    ];
    
    $build['pager'] = [
      '#type' => 'pager'
    ];
    
    return $build;
  }
  
  /**
   * Converts status code to label.
   *
   * @param string $status
   *        The status code.
   *        
   * @return string The status label.
   */
  protected function getStatusLabel($status) {
    $statuses = [
      'created' => $this->t('Created'),
      'printed' => $this->t('Printed'),
      'in_transit' => $this->t('In Transit'),
      'delivered' => $this->t('Delivered'),
      'error' => $this->t('Error')
    ];
    
    return $statuses[$status] ?? $this->t('Unknown');
  }
  
  /**
   * Converts severity level to label.
   *
   * @param int $severity
   *        The severity level.
   *        
   * @return string The severity label.
   */
  protected function getSeverityLabel($severity) {
    $severity_levels = [
      0 => $this->t('Emergency'),
      1 => $this->t('Alert'),
      2 => $this->t('Critical'),
      3 => $this->t('Error'),
      4 => $this->t('Warning'),
      5 => $this->t('Notice'),
      6 => $this->t('Info'),
      7 => $this->t('Debug')
    ];
    
    return $severity_levels[$severity] ?? $this->t('Unknown');
  }
  
  /**
   * Converts severity type to level.
   *
   * @param string $type
   *        The severity type.
   *        
   * @return int The severity level.
   */
  protected function getSeverityLevel($type) {
    $levels = [
      'error' => 3,
      'warning' => 4,
      'info' => 6
    ];
    
    return $levels[$type] ?? NULL;
  }
  
  /**
   * Enhanced shipments list with search capabilities.
   *
   * @return array A render array.
   */
  public function shipmentsList() {
    $request = $this->getRequest();
    $search_params = $request->query->all();
    
    $build = [];
    
    // Add search form
    $build['search_form'] = \Drupal::formBuilder()->getForm('\Drupal\commerce_dpd\Form\DpdShipmentSearchForm');
    
    // Check if we have search parameters
    $has_search = !empty(array_filter($search_params, function ($value) {
      return !empty($value);
    }));
    
    // Build the query
    $query = $this->database->select('commerce_dpd_shipments', 'cds')->fields('cds');
    
    // Apply search filters
    if ($has_search) {
      $this->applySearchFilters($query, $search_params);
      
      // Add search summary
      $build['search_summary'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'search-summary'
          ]
        ]
      ];
      
      $build['search_summary']['text'] = [
        '#markup' => $this->t('Showing results for: @criteria', [
          '@criteria' => $this->buildSearchCriteriaText($search_params)
        ])
      ];
      
      $build['search_summary']['clear'] = [
        '#type' => 'link',
        '#title' => $this->t('Clear all filters'),
        '#url' => Url::fromRoute('commerce_dpd.shipments'),
        '#attributes' => [
          'class' => [
            'button',
            'button--small'
          ]
        ]
      ];
    }
    
    $query->orderBy('cds.created', 'DESC')->extend('\Drupal\Core\Database\Query\PagerSelectExtender')->limit(50);
    
    $results = $query->execute()->fetchAll();
    
    if (empty($results)) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => $has_search ? $this->t('No shipments found matching your search criteria.') : $this->t('No DPD shipments found.')
      ];
      return $build;
    }
    
    $rows = [];
    foreach ($results as $shipment) {
      $order = $this->entityTypeManager()->getStorage('commerce_order')->load($shipment->order_id);
      
      $order_link = $order ? Link::fromTextAndUrl($order->getOrderNumber(), $order->toUrl()) : $this->t('Order @id', [
        '@id' => $shipment->order_id
      ]);
      
      // Build operations dropdown
      $operations = [
        'download' => [
          'title' => $this->t('Download Label'),
          'url' => Url::fromRoute('commerce_dpd.download_label', [
            'commerce_order' => $shipment->order_id,
            'shipment_id' => $shipment->id
          ])
        ],
        'track' => [
          'title' => $this->t('Track on DPD'),
          'url' => Url::fromUri('https://www.dpd.com/tracking?search=' . $shipment->tracking_number, [
            'attributes' => [
              'target' => '_blank'
            ]
          ])
        ],
        'resend' => [
          'title' => $this->t('Resend Notification'),
          'url' => Url::fromRoute('commerce_dpd.resend_notification', [
            'commerce_order' => $shipment->order_id,
            'shipment_id' => $shipment->id
          ])
        ]
      ];
      
      $operations_dropdown = [
        '#type' => 'operations',
        '#links' => $operations,
        '#dropbutton' => TRUE
      ];
      
      $rows[] = [
        'order' => $order_link,
        'tracking_number' => [
          'data' => [
            '#type' => 'link',
            '#title' => $shipment->tracking_number,
            '#url' => Url::fromUri('https://www.dpd.com/tracking?search=' . $shipment->tracking_number, [
              'attributes' => [
                'target' => '_blank',
                'title' => $this->t('Track on DPD website')
              ]
            ])
          ]
        ],
        'status' => [
          'data' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => [
                'shipment-status',
                'status-' . $shipment->status
              ]
            ],
            'label' => [
              '#markup' => $this->getStatusLabel($shipment->status)
            ],
            'icon' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => '',
              '#attributes' => [
                'class' => [
                  'status-icon'
                ]
              ]
            ]
          ]
        ],
        'parcelshop' => $shipment->parcelshop_id ?: $this->t('N/A'),
        'created' => $this->dateFormatter->format($shipment->created, 'short'),
        'updated' => $this->dateFormatter->format($shipment->updated, 'short'),
        'operations' => [
          'data' => $operations_dropdown
        ]
      ];
    }
    
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Order'),
        $this->t('Tracking Number'),
        $this->t('Status'),
        $this->t('Parcelshop'),
        $this->t('Created'),
        $this->t('Updated'),
        $this->t('Operations')
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No shipments found.'),
      '#attributes' => [
        'class' => [
          'dpd-shipments-table'
        ]
      ]
    ];
    
    $build['pager'] = [
      '#type' => 'pager'
    ];
    
    // Export buttons
    $build['export'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'export-actions'
        ]
      ]
    ];
    
    $build['export']['csv'] = [
      '#type' => 'link',
      '#title' => $this->t('Export to CSV'),
      '#url' => Url::fromRoute('commerce_dpd.export_shipments', [], [
        'query' => $search_params
      ]),
      '#attributes' => [
        'class' => [
          'button'
        ]
      ]
    ];
    
    return $build;
  }
  
  /**
   * Applies search filters to the query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *        The database query.
   * @param array $parameters
   *        The search parameters.
   */
  protected function applySearchFilters($query, array $parameters) {
    // Tracking number
    if (!empty($parameters['tracking_number'])) {
      $query->condition('cds.tracking_number', '%' . $this->database->escapeLike($parameters['tracking_number']) . '%', 'LIKE');
    }
    
    // Order ID
    if (!empty($parameters['order_id'])) {
      $query->condition('cds.order_id', $parameters['order_id']);
    }
    
    // Status
    if (!empty($parameters['status'])) {
      $query->condition('cds.status', $parameters['status']);
    }
    
    // Parcelshop ID
    if (!empty($parameters['parcelshop_id'])) {
      $query->condition('cds.parcelshop_id', $parameters['parcelshop_id']);
    }
    
    // Date range
    if (!empty($parameters['date_from'])) {
      $date_from = strtotime($parameters['date_from'] . ' 00:00:00');
      $query->condition('cds.created', $date_from, '>=');
    }
    
    if (!empty($parameters['date_to'])) {
      $date_to = strtotime($parameters['date_to'] . ' 23:59:59');
      $query->condition('cds.created', $date_to, '<=');
    }
    
    // Order number (requires join with commerce_order)
    if (!empty($parameters['order_number'])) {
      $query->leftJoin('commerce_order', 'co', 'cds.order_id = co.order_id');
      $query->condition('co.order_number', '%' . $this->database->escapeLike($parameters['order_number']) . '%', 'LIKE');
    }
  }
  
  /**
   * Builds a human-readable search criteria text.
   *
   * @param array $parameters
   *        The search parameters.
   *        
   * @return string The criteria text.
   */
  protected function buildSearchCriteriaText(array $parameters) {
    $criteria = [];
    
    if (!empty($parameters['tracking_number'])) {
      $criteria[] = $this->t('Tracking: @value', [
        '@value' => $parameters['tracking_number']
      ]);
    }
    
    if (!empty($parameters['order_id'])) {
      $criteria[] = $this->t('Order ID: @value', [
        '@value' => $parameters['order_id']
      ]);
    }
    
    if (!empty($parameters['order_number'])) {
      $criteria[] = $this->t('Order #: @value', [
        '@value' => $parameters['order_number']
      ]);
    }
    
    if (!empty($parameters['status'])) {
      $statuses = [
        'created' => $this->t('Created'),
        'printed' => $this->t('Printed'),
        'in_transit' => $this->t('In Transit'),
        'delivered' => $this->t('Delivered'),
        'error' => $this->t('Error')
      ];
      $criteria[] = $this->t('Status: @value', [
        '@value' => $statuses[$parameters['status']] ?? $parameters['status']
      ]);
    }
    
    if (!empty($parameters['parcelshop_id'])) {
      $criteria[] = $this->t('Parcelshop: @value', [
        '@value' => $parameters['parcelshop_id']
      ]);
    }
    
    if (!empty($parameters['date_from']) || !empty($parameters['date_to'])) {
      $date_text = '';
      if (!empty($parameters['date_from'])) {
        $date_text .= $this->t('From: @date', [
          '@date' => $parameters['date_from']
        ]);
      }
      if (!empty($parameters['date_to'])) {
        if ($date_text)
          $date_text .= ' ';
        $date_text .= $this->t('To: @date', [
          '@date' => $parameters['date_to']
        ]);
      }
      $criteria[] = $date_text;
    }
    
    return implode('; ', $criteria);
  }
  
  /**
   * Exports shipments to CSV.
   *
   * @return \Symfony\Component\HttpFoundation\Response The CSV response.
   */
  public function exportShipments() {
    $request = $this->getRequest();
    $search_params = $request->query->all();
    
    // Build query with same filters as list
    $query = $this->database->select('commerce_dpd_shipments', 'cds')->fields('cds');
    
    if (!empty($search_params)) {
      $this->applySearchFilters($query, $search_params);
    }
    
    $query->orderBy('cds.created', 'DESC');
    $results = $query->execute()->fetchAll();
    
    // Prepare CSV data
    $csv_data = [];
    $csv_data[] = [
      'Order ID',
      'Order Number',
      'Tracking Number',
      'Status',
      'Parcelshop ID',
      'Created',
      'Updated'
    ];
    
    foreach ($results as $shipment) {
      $order = $this->entityTypeManager()->getStorage('commerce_order')->load($shipment->order_id);
      
      $csv_data[] = [
        $shipment->order_id,
        $order ? $order->getOrderNumber() : '',
        $shipment->tracking_number,
        $this->getStatusLabel($shipment->status),
        $shipment->parcelshop_id ?: '',
        date('Y-m-d H:i:s', $shipment->created),
        date('Y-m-d H:i:s', $shipment->updated)
      ];
    }
    
    // Convert to CSV string
    $csv_string = '';
    foreach ($csv_data as $row) {
      $csv_string .= implode(',', array_map(function ($value) {
        return '"' . str_replace('"', '""', $value) . '"';
      }, $row)) . "\n";
    }
    
    // Create response
    $response = new Response($csv_string);
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="dpd-shipments-' . date('Y-m-d') . '.csv"');
    
    return $response;
  }
  
  /**
   * Resends shipment notification.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *        The order.
   * @param int $shipment_id
   *        The shipment ID.
   *        
   * @return \Symfony\Component\HttpFoundation\RedirectResponse A redirect
   *         response.
   */
  public function resendNotification(OrderInterface $commerce_order, $shipment_id) {
    // Get shipment data
    $query = $this->database->select('commerce_dpd_shipments', 'cds')->fields('cds')->condition('id', $shipment_id)->condition('order_id', $commerce_order->id())->execute();
    
    $shipment = $query->fetchAssoc();
    
    if ($shipment) {
      // TODO: Implement email sending with tracking information
      $this->messenger->addStatus($this->t('Notification resent for tracking number: @tracking', [
        '@tracking' => $shipment['tracking_number']
      ]));
    }
    else {
      $this->messenger->addError($this->t('Shipment not found.'));
    }
    
    return $this->redirect('commerce_dpd.shipments');
  }
}