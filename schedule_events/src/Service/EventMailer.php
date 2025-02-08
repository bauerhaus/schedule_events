<?php

namespace Drupal\schedule_events\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Handles sending emails for event invitations.
 */
class EventMailer {

  protected $mailManager;
  protected $languageManager;
  protected $logger;
  protected $requestStack;

  /**
   * Constructs the EventMailer service.
   */
  public function __construct(
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack
  ) {
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->logger = $logger_factory->get('schedule_events');
    $this->requestStack = $request_stack;
  }

  /**
   * Sends an invitation email to a guest.
   */
  public function sendInvitation($email, $event_id, $token, $event_title) {
    // Get the base URL from the request stack, fallback to Drupal's request method
    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() : \Drupal::request()->getSchemeAndHttpHost();
  
    // Generate the voting link with the full base URL
    $voting_link = $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost()
    . Url::fromRoute('schedule_events.view', ['event_id' => $event_id], [
      'query' => ['token' => $token],
    ])->toString();

    $database = \Drupal::database();
    $event = $database->select('schedule_events_event', 'e')
        ->fields('e', ['title', 'description', 'location'])
        ->condition('id', $event_id)
        ->execute()
        ->fetchAssoc();
    
    $event_title = $event['title'];
    $event_description = $event['description'];
    $event_location = $event['location'];
    
    $message = "Hello,\n\nYou've been invited to vote on a time for the event: $event_title.\n\n";
    $message .= "Event Details:\n$event_description\n\n";
    $message .= "Event Location:\n$event_location\n\n";
    $message .= "Click the link to vote:\n$voting_link\n\nThank you!";
  
    $params = [
      'subject' => "You're invited to vote on a time for the event: $event_title",
      'body' => [$message], // ✅ Convert message to array
  ];
  
  
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $send = $this->mailManager->mail('schedule_events', 'event_invite', $email, $langcode, $params);
  
    if ($send['result']) {
      $this->logger->info("Email sent successfully to: $email with link: $voting_link");
      return TRUE;
    } else {
      $this->logger->error("Failed to send email to: $email");
      return FALSE;
    }
  }

  /**
   * Static create() method for dependency injection.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('logger.factory'),
      $container->get('request_stack')
    );
  }
}
