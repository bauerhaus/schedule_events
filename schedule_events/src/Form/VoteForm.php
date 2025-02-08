<?php

namespace Drupal\schedule_events\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Provides a form for guests to vote on event time slots.
 */
class VoteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'schedule_events_vote_form';
  }

  /**
   * Build the voting form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $request = \Drupal::request();
    $route_match = \Drupal::routeMatch();
    
    $event_id = $route_match->getParameter('event_id') ?? $request->query->get('event_id');
    $token = $request->query->get('token');
    
    if (empty($event_id) || empty($token)) {
        \Drupal::logger('schedule_events')->error("Missing parameters: event_id: " . print_r($event_id, TRUE) . ", token: " . print_r($token, TRUE));
        return ['#markup' => $this->t('Invalid voting link. Missing event ID or token.')];
    }
    
    
if (empty($event_id) || empty($token)) {
    \Drupal::logger('schedule_events')->error("Missing parameters: event_id: " . print_r($event_id, TRUE) . ", token: " . print_r($token, TRUE));
    return ['#markup' => $this->t('Invalid voting link. Missing event ID or token.')];
}

    
    // Retrieve the invited guest by token
    $database = \Drupal::database();
    $guest = $database->select('schedule_events_event_invites', 'ei')
        ->fields('ei', ['guest_name'])
        ->condition('event_id', $event_id)
        ->condition('token', $token)
        ->execute()
        ->fetchObject();
    
    if (!$guest) {
        return ['#markup' => $this->t('Invalid or expired token.')];
    }
    

    $form['guest_name'] = [
        '#markup' => "<h3>Welcome, {$guest->guest_name}!</h3>",
    ];
// Retrieve available time slots from the event
$database = \Drupal::database();
$event = $database->select('schedule_events_event', 'e')
    ->fields('e', ['start_times'])
    ->condition('id', $event_id)
    ->execute()
    ->fetchObject();

$time_slots = json_decode($event->start_times, TRUE);

if (!$time_slots) {
    return ['#markup' => $this->t('No available time slots for this event.')];
}

// Get previous votes from the database
$existing_votes = $database->select('schedule_events_event_votes', 'ev')
    ->fields('ev', ['time_slot'])
    ->condition('event_id', $event_id)
    ->condition('guest_token', $token)
    ->execute()
    ->fetchCol();

$form['event_id'] = [
    '#type' => 'hidden',
    '#value' => $event_id,
];

$form['token'] = [
    '#type' => 'hidden',
    '#value' => $token,
];

$formatted_slots = [];

foreach ($time_slots as $slot) {
    $timestamp = strtotime($slot);
    $formatted_slots[$slot] = date('D, F j, g:i A', $timestamp);  // 📅 Format to "January 10, 7:30 AM"
}

$form['votes'] = [
    '#type' => 'checkboxes',
    '#title' => $this->t('Select your preferred time slots'),
    '#options' => $formatted_slots,  // ✅ Now shows user-friendly format
    '#default_value' => $existing_votes,
    '#required' => TRUE,
];

$form['submit'] = [
    '#type' => 'submit',
    '#value' => $this->t('Submit Vote'),
];

return $form;
}


  /**
   * Submit handler for the vote form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $event_id = $values['event_id'];
    $token = $values['token'];
    
    if (empty($event_id) || empty($token)) {
        \Drupal::logger('schedule_events')->error("Vote failed: Missing event_id ({$event_id}) or guest_token ({$token})");
        $this->messenger()->addError($this->t('Invalid vote submission.'));
        return;
    }

    if (empty($event_id)) {
        \Drupal::logger('schedule_events')->error("Missing event_id in VoteForm submission.");
        $this->messenger()->addError($this->t('Invalid event. Please try again.'));
        return;
    }

    $selected_slots = array_filter($values['votes']);

    if (empty($selected_slots)) {
        $this->messenger()->addError($this->t('You must select at least one time slot.'));
        return;
    }

    $database = \Drupal::database();
    $values = $form_state->getValues();
    $event_id = $values['event_id'];
    $token = $values['token'];
    $selected_slots = array_filter($values['votes']); // Remove unselected checkboxes
    
    // Delete previous votes for this user
    $database->delete('schedule_events_event_votes')
        ->condition('event_id', $event_id)
        ->condition('guest_token', $token)
        ->execute();
    
    // Insert new votes
    foreach ($selected_slots as $slot) {
        $database->insert('schedule_events_event_votes')
            ->fields([
                'event_id' => $event_id,
                'guest_token' => $token,
                'time_slot' => $slot,
                'voted_at' => time(),
            ])
            ->execute();
    }
    
    $this->messenger()->addStatus($this->t('Your vote has been recorded.'));
    \Drupal::cache('data')->invalidateAll();
    $form_state->setRedirect('schedule_events.view', ['event_id' => $event_id], ['query' => ['token' => $token]]);

  }

}
