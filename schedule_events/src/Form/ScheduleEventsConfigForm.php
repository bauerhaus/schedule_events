<?php

namespace Drupal\schedule_events\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Defines the configuration form for creating events.
 */
class ScheduleEventsConfigForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'schedule_events_event_form';
  }

  /**
   * Build the event form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event Title'),
      '#required' => TRUE,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => FALSE,
    ];

    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location (Optional)'),
      '#required' => FALSE,
    ];

    $form['duration'] = [
      '#type' => 'select',
      '#title' => $this->t('Duration (minutes)'),
      '#options' => [
        30 => '30 minutes',
        60 => '1 hour',
        90 => '1 hour 30 minutes',
        120 => '2 hours',
      ],
      '#required' => TRUE,
    ];

    $form['start_times'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Available Start Times'),
      '#description' => $this->t('Enter one start time per line (YYYY-MM-DD HH:MM format).'),
      '#required' => TRUE,
    ];

    $form['guests'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Invited Guests (one per line: Name - Email - Priority)'),
      '#description' => $this->t('Priority can be "Must Attend" or "May Attend". Example: John Doe - john@example.com - Must Attend'),
      '#required' => TRUE,
  ];
  
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Event'),
    ];

    return $form;
  }

  /**
   * Validate form input.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $start_times = explode("\n", trim($form_state->getValue('start_times')));
    foreach ($start_times as $time) {
      $time = trim($time);
      if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $time)) {
        $form_state->setErrorByName('start_times', $this->t('Invalid date format. Use YYYY-MM-DD HH:MM.'));
      }
    }

    $guests = explode("\n", trim($form_state->getValue('guests')));
    foreach ($guests as $guest) {
      if (!empty($guest) && !preg_match('/^.+\s-\s[^@]+@[^@]+\.[^@]+$/', trim($guest))) {
        $form_state->setErrorByName('guests', $this->t('Invalid guest format. Use "Name - email@example.com".'));
      }
    }
  }

  /**
   * Submit handler.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Insert the new event into the database and get the event ID.
    $event_id = \Drupal::database()->insert('schedule_events_event')
  ->fields([
      'uuid' => \Drupal::service('uuid')->generate(),
      'title' => $values['title'],
      'description' => $values['description'],
      'location' => $values['location'],
      'duration' => $values['duration'],
      'start_times' => json_encode(array_map('trim', explode("\n", $values['start_times']))),
      'created_by' => \Drupal::currentUser()->id(),
      'created' => time(),
  ])
  ->execute();


    if (!$event_id) {
        \Drupal::logger('schedule_events')->error("Failed to create event.");
        $this->messenger()->addError($this->t('Event creation failed. Please try again.'));
        return;
    }

    // Send invitations
    $database = \Drupal::database(); 
    $mailer = \Drupal::service('schedule_events.mailer');
    $guests = explode("\n", trim($values['guests']));
    foreach ($guests as $guest) {
      list($name, $email, $priority) = explode(' - ', $guest) + [NULL, NULL, 'May Attend']; // Default priority

      if ($name && $email) {
          $token = \Drupal::service('uuid')->generate();
            $database->insert('schedule_events_event_invites')
              ->fields([
                  'event_id' => $event_id,
                  'guest_name' => $name,
                  'guest_email' => $email,
                  'priority' => trim($priority),
                  'token' => $token,
              ])
              ->execute();
      }

        $mailer->sendInvitation($email, $event_id, $token, $values['title']);
      }

    

    $this->messenger()->addStatus($this->t('Event created successfully and invitations sent.'));
    $form_state->setRedirect('schedule_events.manage', ['event_id' => $event_id]);

  }

}
