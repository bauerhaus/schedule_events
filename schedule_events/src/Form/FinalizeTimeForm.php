<?php
namespace Drupal\schedule_events\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Form for finalizing an event's time slot.
 */
class FinalizeTimeForm extends FormBase {

  protected $mailManager;
  protected $logger;

  public function __construct(MailManagerInterface $mail_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->mailManager = $mail_manager;
    $this->logger = $logger_factory->get('schedule_events');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('logger.factory')
    );
  }

  public function getFormId() {
    return 'schedule_events_finalize_time_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $event_id = NULL) {
    if (!$event_id) {
      return ['#markup' => $this->t('Invalid event ID.')];
    }

    $database = Database::getConnection();
    $event = $database->select('schedule_events_event', 'e')
      ->fields('e', ['final_time_slot'])
      ->condition('id', $event_id)
      ->execute()
      ->fetchObject();

    $time_slots = $database->select('schedule_events_event_votes', 'ev')
      ->fields('ev', ['time_slot'])
      ->condition('event_id', $event_id)
      ->distinct()
      ->execute()
      ->fetchCol();

    if (empty($time_slots)) {
      return ['#markup' => $this->t('No available time slots to finalize.')];
    }

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => $event_id,
    ];
    foreach ($time_slots as $slot) {
      $timestamp = strtotime($slot);
      $formatted_slots[$slot] = date('D, F j, g:i A', $timestamp);  // 📅 Format to "January 10, 7:30 AM"
  }
    $form['final_time_slot'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Final Time Slot'),
      '#options' => array_combine($time_slots, $formatted_slots),
      '#default_value' => $event->final_time_slot ?? '',
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Finalize Time'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $event_id = $values['event_id'];
    $final_time_slot = $values['final_time_slot'];

    $database = Database::getConnection();
    $database->update('schedule_events_event')
      ->fields(['final_time_slot' => $final_time_slot])
      ->condition('id', $event_id)
      ->execute();

    $this->sendFinalizationEmail($event_id, $final_time_slot);
    $this->messenger()->addStatus($this->t('The final time slot has been set.'));
  }

  protected function sendFinalizationEmail($event_id, $final_time_slot) {
    $database = Database::getConnection();
    $guests = $database->select('schedule_events_event_invites', 'ei')
      ->fields('ei', ['guest_email', 'guest_name'])
      ->condition('event_id', $event_id)
      ->execute()
      ->fetchCol();

    $event = $database->select('schedule_events_event', 'e')
      ->fields('e', ['title', 'description', 'location'])
      ->condition('id', $event_id)
      ->execute()
      ->fetchAssoc();

    $event_title = $event['title'];
    $event_description = $event['description'];
    $event_location = $event['location'];

    $message = "The final time for the: $event_title has been selected as: $final_time_slot..\n\n";
    $message .= "Event Details:\n$event_description\n\n";
    $message .= "Event Location:\n$event_location\n\n";
    $message .= "See you There!";

    foreach ($guests as $email) {

      $params = [
        'subject' => "Finalized Event Time for:  $event_title",
        'body' => ["Hello,\n\n$message"]
    ];
    
      $send = $this->mailManager->mail('schedule_events', 'event_finalized', $email, 'en', $params);

      if (!$send['result']) {
          \Drupal::logger('schedule_events')->error("Failed to send finalization email to: $email");
      } else {
          \Drupal::logger('schedule_events')->info("Finalized time slot email sent to: $email");
      }

    }

    $this->logger->info("Finalized time slot email sent for event ID $event_id.");
  }
}
