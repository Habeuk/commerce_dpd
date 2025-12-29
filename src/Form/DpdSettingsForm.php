<?php

namespace Drupal\commerce_dpd\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Commerce DPD settings.
 */
class DpdSettingsForm extends ConfigFormBase {
  
  /**
   *
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_dpd.settings'
    ];
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_dpd_settings';
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_dpd.settings');
    
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode'),
      '#options' => [
        'sandbox' => $this->t('Sandbox (Testing)'),
        'production' => $this->t('Production')
      ],
      '#default_value' => $config->get('mode') ?? 'sandbox',
      '#required' => TRUE
    ];
    
    $form['sandbox'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sandbox Credentials'),
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => [
            'value' => 'sandbox'
          ]
        ]
      ]
    ];
    
    $form['sandbox']['sandbox_delis_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delis ID'),
      '#default_value' => $config->get('sandbox_delis_id'),
      '#required' => FALSE
    ];
    
    $form['sandbox']['sandbox_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Leave empty to keep current password.')
    ];
    
    $form['production'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Production Credentials'),
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => [
            'value' => 'production'
          ]
        ]
      ]
    ];
    
    $form['production']['production_delis_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delis ID'),
      '#default_value' => $config->get('production_delis_id'),
      '#required' => FALSE
    ];
    
    $form['production']['production_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Leave empty to keep current password.')
    ];
    
    $form['default_package'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default Package Settings')
    ];
    
    $form['default_package']['default_weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Default weight (kg)'),
      '#min' => 0.1,
      '#step' => 0.1,
      '#default_value' => $config->get('default_weight') ?? 2.0,
      '#required' => TRUE
    ];
    
    $form['default_package']['default_package_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Height (cm)'),
      '#min' => 1,
      '#default_value' => $config->get('default_package_height') ?? 20,
      '#required' => TRUE
    ];
    
    $form['default_package']['default_package_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Width (cm)'),
      '#min' => 1,
      '#default_value' => $config->get('default_package_width') ?? 30,
      '#required' => TRUE
    ];
    
    $form['default_package']['default_package_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Depth (cm)'),
      '#min' => 1,
      '#default_value' => $config->get('default_package_depth') ?? 10,
      '#required' => TRUE
    ];
    
    $form['sender'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sender Information')
    ];
    
    $form['sender']['sender_company'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company'),
      '#default_value' => $config->get('sender_company'),
      '#required' => TRUE
    ];
    
    $form['sender']['sender_street'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Street'),
      '#default_value' => $config->get('sender_street'),
      '#required' => TRUE
    ];
    
    $form['sender']['sender_city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => $config->get('sender_city'),
      '#required' => TRUE
    ];
    
    $form['sender']['sender_postal_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal Code'),
      '#default_value' => $config->get('sender_postal_code'),
      '#required' => TRUE
    ];
    
    $form['sender']['sender_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country Code'),
      '#default_value' => $config->get('sender_country') ?? 'DE',
      '#size' => 2,
      '#maxlength' => 2,
      '#required' => TRUE
    ];
    
    return parent::buildForm($form, $form_state);
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('commerce_dpd.settings');
    
    $values = $form_state->getValues();
    
    // Handle passwords - only update if provided
    if (!empty($values['sandbox_password'])) {
      $config->set('sandbox_password', $values['sandbox_password']);
    }
    
    if (!empty($values['production_password'])) {
      $config->set('production_password', $values['production_password']);
    }
    
    // Set all other values
    $config->set('mode', $values['mode'])->set('sandbox_delis_id', $values['sandbox_delis_id'])->set('production_delis_id', $values['production_delis_id'])->set('default_weight',
      $values['default_weight'])->set('default_package_height', $values['default_package_height'])->set('default_package_width', $values['default_package_width'])->set('default_package_depth',
      $values['default_package_depth'])->set('sender_company', $values['sender_company'])->set('sender_street', $values['sender_street'])->set('sender_city', $values['sender_city'])->set(
      'sender_postal_code', $values['sender_postal_code'])->set('sender_country', $values['sender_country'])->save();
    
    parent::submitForm($form, $form_state);
  }
}