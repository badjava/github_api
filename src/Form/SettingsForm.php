<?php

/**
 * @file
 * Contains Drupal\github_api\Form\SettingsForm
 */

namespace Drupal\github_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class SettingsForm
 * @package Drupal\github_api\Form
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [
      'github_api.settings'
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'github_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('github_api.settings');

    $form = parent::buildForm($form, $form_state);
    $form['github_api_username'] = array(
      '#type' => 'textfield',
      '#title' => t('GitHub username'),
      '#required' => TRUE,
      '#default_value' => $config->get('github_api_username'),
    );

    $form['github_api_password'] = array(
      '#type' => 'password',
      '#title' => t('Password'),
      '#required' => TRUE,
      '#description' => t('This password is not stored it only used for generating the authentication token.'),
    );

    $form['actions']['submit']['#value'] = t('Generate and store token');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $username = $form_state->getValue('github_api_username');
    $password = $form_state->getValue('github_api_password');
    try {
      $githubManager = \Drupal::service('github_api.manager');
      $config = $this->config('github_api.settings');
      $token = $githubManager->getToken($username, $password);
      $config->set('github_api_token', $token);
      $config->set('github_api_username', $username);
      $config->set('github_api_password', $password);
      $config->save();
      drupal_set_message(t('Generated and stored github authentication token'));
    }
    catch (\Exception $e) {
      drupal_set_message(t('Unable to generate token. Error: @error', array('@error' => $e->getMessage())), 'error');
    }
  }
}
