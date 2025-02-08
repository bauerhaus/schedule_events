<?php

namespace Drupal\schedule_events\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Database;

/**
 * Handles event scheduling routes.
 */
class ScheduleEventsController extends ControllerBase {

  /**
   * Create an event form page.
   */
  public function createEvent() {
    return [
      '#markup' => '<p>Event creation form will go here.</p>',
    ];
  }


  /**
   * Displays a list of all events.
  **/      

 public function manageEvents($event_id) {
  $form = \Drupal::formBuilder()->getForm('Drupal\schedule_events\Form\FinalizeTimeForm', $event_id);
  
  $database = \Drupal::database();
  $query = $database->query("
    SELECT ev.time_slot, 
           GROUP_CONCAT(DISTINCT CONCAT(ei.guest_name, ' (', ei.priority, ')') ORDER BY ei.priority DESC SEPARATOR ', ') AS voters
    FROM {event_votes} ev
    INNER JOIN {event_invites} ei ON ev.guest_token = ei.token
    WHERE ev.event_id = :event_id
    GROUP BY ev.time_slot", 
    [':event_id' => $event_id]
  );

  $results = $query->fetchAll();

  $rows = [];
  foreach ($results as $row) {
    $rows[] = [
        'data' => [
            date('D, F j, g:i A', strtotime($row->time_slot)),  // 📅 Format timestamp
            $row->voters,
        ],
    ];
  }


  return [
      '#type' => 'container',
      'results' => [
          '#type' => 'table',
          '#header' => ['Time Slot', 'Voters'],
          '#rows' => $rows,
          '#empty' => $this->t('No votes found.'),
      ],
      'finalize_form' => $form,
  ];
}

  /**
   * View event results.
   */
    public function viewResults(Request $request, $event_id) {
        $database = \Drupal::database();
        $token = $request->query->get('token');  // Get token from URL

        $voters_query = $database->query("
        SELECT ev.time_slot, 
            GROUP_CONCAT(DISTINCT ei.guest_name ORDER BY ei.guest_name ASC SEPARATOR ', ') AS voters
        FROM {event_votes} ev
        INNER JOIN {event_invites} ei ON ev.guest_token = ei.token
        WHERE ev.event_id = :event_id
        GROUP BY ev.time_slot", 
        [':event_id' => $event_id]
        );
        $voted_results = $voters_query->fetchAll();

        // Fetch invited guests who have NOT voted
        $non_voters_query = $database->query("
        SELECT ei.guest_name 
        FROM {event_invites} ei
        LEFT JOIN {event_votes} ev ON ei.token = ev.guest_token
        WHERE ei.event_id = :event_id AND ev.guest_token IS NULL", 
        [':event_id' => $event_id]
        );
        $non_voters = $non_voters_query->fetchCol();

        $rows = [];
        foreach ($voted_results as $row) {
        $rows[] = [
            'data' => [
                date('D, F j, g:i A', strtotime($row->time_slot)),  
                $row->voters,
            ],
        ];
        }

        $elements = [
        'results_table' => [
            '#type' => 'table',
            '#header' => ['Time Slot', 'Voters'],
            '#rows' => $rows,
            '#empty' => $this->t('No votes found.'),
        ],
        '#cache' => ['max-age' => 0],
        ];

        // Show non-voters if any exist
        if (!empty($non_voters)) {
        $elements['non_voters'] = [
            '#markup' => '<p><strong>People who have not voted yet:</strong> ' . implode(', ', $non_voters) . '</p>',
        ];
        }

        // If user has a valid token, show the "Update Your Vote" link
        if (!empty($token)) {
            $elements['vote_form'] = \Drupal::formBuilder()->getForm('Drupal\schedule_events\Form\VoteForm', $event_id, $token);
        }

    return $elements;
    }


}
