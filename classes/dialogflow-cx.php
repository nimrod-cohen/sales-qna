<?php
use Google\Cloud\Dialogflow\Cx\V3\Client\FlowsClient;
use Google\Cloud\Dialogflow\Cx\V3\Client\IntentsClient;
use Google\Cloud\Dialogflow\Cx\V3\Client\PagesClient;
use Google\Cloud\Dialogflow\Cx\V3\Client\SessionsClient;
use Google\Cloud\Dialogflow\Cx\V3\CreateIntentRequest;
use Google\Cloud\Dialogflow\Cx\V3\CreatePageRequest;
use Google\Cloud\Dialogflow\Cx\V3\DeleteIntentRequest;
use Google\Cloud\Dialogflow\Cx\V3\DetectIntentRequest;
use Google\Cloud\Dialogflow\Cx\V3\EventInput;
use Google\Cloud\Dialogflow\Cx\V3\Intent;
use Google\Cloud\Dialogflow\Cx\V3\Intent\TrainingPhrase;
use Google\Cloud\Dialogflow\Cx\V3\Intent\TrainingPhrase\Part;
use Google\Cloud\Dialogflow\Cx\V3\ListFlowsRequest;
use Google\Cloud\Dialogflow\Cx\V3\ListIntentsRequest;
use Google\Cloud\Dialogflow\Cx\V3\ListPagesRequest;
use Google\Cloud\Dialogflow\Cx\V3\MatchIntentRequest;
use Google\Cloud\Dialogflow\Cx\V3\Page;
use Google\Cloud\Dialogflow\Cx\V3\PBMatch\MatchType;
use Google\Cloud\Dialogflow\Cx\V3\QueryInput;
use Google\Cloud\Dialogflow\Cx\V3\TextInput;
use Google\Cloud\Dialogflow\Cx\V3\TransitionRoute;
use Google\Cloud\Dialogflow\Cx\V3\UpdateFlowRequest;
use Google\Cloud\Dialogflow\Cx\V3\UpdatePageRequest;
use Google\Protobuf\FieldMask;

final class DialogFlowCX {
  private static $_instance = null;
  private $client = null;
  private $agentPath = null;
  private $projectId = null;
  private $location = null;
  private $agentId = null;
  private $language = null;
  private $keyFilePath = null;
  const DEFAULT_INTENT_NAME = 'DEF';

  private function __construct() {
    $this->keyFilePath = __DIR__ . '/../yinon-arieli-fbad3c8a5ab8.json';

    //TODO move to config
    $this->projectId = 'yinon-arieli';
    $this->location = 'us-central1'; // or e.g. 'us-central1'
    $this->agentId = 'b5143489-270b-41ee-93aa-a3fcf2aa8e12';
    $this->language = 'he-IL'; // or e.g. 'en-US'

    $this->client = new IntentsClient([
      'credentials' => $this->keyFilePath,
      'apiEndpoint' => $this->location . '-dialogflow.googleapis.com'
    ]);

    // Parent path for listing intents
    $this->agentPath = $this->client->agentName($this->projectId, $this->location, $this->agentId);
  }

  public static function get_instance() {
    if (is_null(self::$_instance)) {
      self::$_instance = new DialogFlowCX();
    }

    return self::$_instance;
  }

  public function update_intent(int $id, string $intent, string $answer): bool {
    return true;
  }

  public function getDefaultFlow(): string {
    $client = new FlowsClient([
      'credentials' => $this->keyFilePath,
      'apiEndpoint' => $this->location . '-dialogflow.googleapis.com'
    ]);

    $parent = $this->agentPath; // "projects/.../locations/.../agents/..."
    $defaultFlow = null;

    // Build the request object for listing flows
    $request = new ListFlowsRequest([
      'parent' => $parent
    ]);

    foreach ($client->listFlows($request) as $flow) {
      $defaultFlow = $flow->getName();
      break; // take the first (the “Default Start Flow”)
    }

    $client->close();

    if (!$defaultFlow) {
      throw new \RuntimeException("No flows found under agent {$parent}");
    }

    return $defaultFlow;
  }

  private function _create_intent(string $displayName): string {
    // 1) Create the new Intent
    $intent = new Intent([
      'display_name' => $displayName,
      'training_phrases' => [
        new TrainingPhrase([
          'parts' => [new Part(['text' => $displayName])],
          'repeat_count' => 1
        ])
      ]
    ]);

    // Create the intent
    $createReq = new CreateIntentRequest([
      'parent' => $this->agentPath,
      'intent' => $intent,
      'language_code' => $this->language
    ]);
    $response = $this->client->createIntent($createReq);
    $intentName = $response->getName();
    return $intentName;
  }

  private function _get_default_page($pagesClient): Page {
    $defaultFlowName = $this->getDefaultFlow();

    $listReq = new ListPagesRequest([
      'parent' => $defaultFlowName
    ]);

    $pagesResponse = $pagesClient->listPages($listReq);
    $pagesArray = iterator_to_array($pagesResponse);
    $page = $pagesArray[0] ?? null;

    if (!$page) {
      //create a new page
      $page = new Page([
        'display_name' => 'Default Page',
        'transition_routes' => []
      ]);
      $createPageReq = new CreatePageRequest([
        'parent' => $defaultFlowName,
        'page' => $page
      ]);
      $page = $pagesClient->createPage($createPageReq);
    }
    $pagesClient->close();
    return $page;
  }

  public function add_intent(string $displayName): string {
    // 2) Locate the "Start" page by listing all pages in the flow
    $pagesClient = new PagesClient([
      'credentials' => $this->keyFilePath,
      'apiEndpoint' => $this->location . '-dialogflow.googleapis.com'
    ]);

    $page = $this->_get_default_page($pagesClient);

    if (!$page) {
      throw new \RuntimeException("Could not get the default page");
    }

    $startPageName = $page->getName();

    // 3) Create the intent via the external helper and get its full resource name
    $intentFullName = $this->_create_intent($displayName);

    // 4) Append the new transition route, and update it
    $routes = iterator_to_array($page->getTransitionRoutes());
    $routes[] = new TransitionRoute([
      'intent' => $intentFullName,
      'target_page' => $startPageName
    ]);
    $page->setTransitionRoutes($routes);

    $updateReq = new UpdatePageRequest();
    $updateReq->setPage($page);
    $updateReq->setUpdateMask(new FieldMask(['paths' => ['transition_routes']]));
    $pagesClient->updatePage($updateReq);
    $pagesClient->close();

    // 5) Return the new intent's ID
    return basename($intentFullName);
  }

  private function _remove_intent_references(string $intentFullName): void {
    // 1) Remove any flow‑level route groups referencing this intent
    $flows = new FlowsClient([
      'credentials' => $this->keyFilePath,
      'apiEndpoint' => "{$this->location}-dialogflow.googleapis.com"
    ]);
    $listFlowsReq = (new ListFlowsRequest())->setParent($this->agentPath);
    foreach ($flows->listFlows($listFlowsReq) as $flow) {
      $flowName = $flow->getName();
      $groups = iterator_to_array($flow->getTransitionRouteGroups());
      $filtered = array_filter($groups, fn($g) => $g->getIntent() !== $intentFullName);
      if (count($filtered) !== count($groups)) {
        // update only if we removed something
        $flow->setTransitionRouteGroups($filtered);
        $flows->updateFlow(
          (new UpdateFlowRequest())
            ->setFlow($flow)
            ->setUpdateMask(new FieldMask(['paths' => ['transition_route_groups']]))
        );
      }
    }
    $flows->close();

    // 2) Remove any page‑level routes referencing this intent
    $pagesClient = new PagesClient([
      'credentials' => $this->keyFilePath,
      'apiEndpoint' => "{$this->location}-dialogflow.googleapis.com"
    ]);
    $listPagesReq = (new ListPagesRequest())
      ->setParent($this->getDefaultFlow()); // same flow parent you use elsewhere
    foreach ($pagesClient->listPages($listPagesReq) as $page) {
      $pageName = $page->getName();
      $routes = iterator_to_array($page->getTransitionRoutes());
      $filtered = array_filter($routes, fn($r) => $r->getIntent() !== $intentFullName);
      if (count($filtered) !== count($routes)) {
        $page->setTransitionRoutes($filtered);
        $pagesClient->updatePage(
          (new UpdatePageRequest())
            ->setPage($page)
            ->setUpdateMask(new FieldMask(['paths' => ['transition_routes']]))
        );
      }
    }
    $pagesClient->close();
  }

  public function delete_intent(string $intentId): bool {
    $name = IntentsClient::intentName(
      $this->projectId,
      $this->location,
      $this->agentId,
      $intentId
    );

    $this->_remove_intent_references($name);

    $request = new DeleteIntentRequest([
      'name' => $name
    ]);

    $this->client->deleteIntent($request, [
      'force' => true
    ]);
    return true;
  }

  public function find_intent(string $text): ?array {
    // 1) Create the SessionsClient
    $sessions = new SessionsClient([
      'credentials' => $this->keyFilePath,
      'apiEndpoint' => "{$this->location}-dialogflow.googleapis.com"
    ]);

    // 2) Session name (starts on your default Start page)
    $sessionId = uniqid('', true);
    $sessionName = $sessions->sessionName(
      $this->projectId,
      $this->location,
      $this->agentId,
      $sessionId
    );

    // 3) Fire the page‑enter event
    $eventInput = (new EventInput())->setEvent('ENTER_QNA_PAGE');
    $eventQuery = (new QueryInput())
      ->setEvent($eventInput)
      ->setLanguageCode($this->language);
    $sessions->detectIntent(
      (new DetectIntentRequest())
        ->setSession($sessionName)
        ->setQueryInput($eventQuery)
    );

    // 4) Build QueryInput
    $textInput = (new TextInput())->setText($text);
    $textQuery = (new QueryInput())
      ->setText($textInput)
      ->setLanguageCode($this->language);
    $matchReq = (new MatchIntentRequest())
      ->setSession($sessionName)
      ->setQueryInput($textQuery);
    $matches = $sessions->matchIntent($matchReq)->getMatches();

    $sessions->close();
    $matches = iterator_to_array($matches);

    if (empty($matches)) {
      // No possible intent matches on the Start page
      return [];
    }

    // 6) Pick the best match (highest confidence)
    usort($matches, fn($a, $b) => $b->getConfidence() <=> $a->getConfidence());
    $best = $matches[0];

    if ($best->getMatchType() !== MatchType::INTENT ||
      $best->getConfidence() < 0.75) {
      // It was a fallback or something else
      return [];
    }

    $intent = $best->getIntent();
    $confidence = $best->getConfidence();
    $display = $intent->getDisplayName();
    if ($display === self::DEFAULT_INTENT_NAME) {
      // skip welcome/fallback built‑ins
      return [];
    }

    return [[
      'intent_id' => basename($intent->getName()),
      'question' => $display,
      'confidence' => $confidence,
      'is_fallback' => $intent->getIsFallback()
    ]];
  }

  public function get_intents(): array {

    $request = (new ListIntentsRequest())->setParent($this->agentPath);
    $intents = [];
    foreach ($this->client->listIntents($request) as $intent) {
      $displayName = $intent->getDisplayName();
      if ($displayName === self::DEFAULT_INTENT_NAME) { // couldn't find a way to delete the welcome intent
        continue;
      }
      $intentId = basename($intent->getName());
      $isFallback = $intent->getIsFallback();

      $intents[] = [
        'intent_id' => $intentId,
        'question' => $displayName,
        'is_fallback' => $isFallback
      ];
    }

    return $intents;
  }
}