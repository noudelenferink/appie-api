<?php
require_once '../include/DbHandler.php';
require_once '../include/PassHash.php';
require '../vendor/autoload.php';
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();

$corsOptions = array(
  "exposeHeaders" => array(
    "Content-Type",
    "X-Requested-With",
    "X-authentication",
    "X-client"
  ) ,
  "allowMethods" => array(
    'GET',
    'POST',
    'PUT',
    'DELETE',
    'OPTIONS'
  )
);
$cors = new \CorsSlim\CorsSlim($corsOptions);
$app->add($cors);

$app->add(new \Slim\Middleware\JwtAuthentication([
  "secure" => false,"secret" => "supersecret",
  "rules" => [
  new \Slim\Middleware\JwtAuthentication\RequestPathRule([
    "path" => "/",
    "passthrough" => ["/login"]
    ]),
  new \Slim\Middleware\JwtAuthentication\RequestMethodRule([
    "passthrough" => ["OPTIONS"]
    ])
  ],
  "callback" => function ($options) use ($app) {
    $app->jwt = $options["decoded"];
}
]));

// User id from db - Global Variable
$user_id = NULL;

function createToken($user, $roles, $competitions) {
  $key = "supersecret";
  $date = new DateTime();
  $teams = [array('TeamID' => 1 , 'TeamName' => 'Bornerbroek 3' ), array('TeamID' => 27, 'TeamName' => 'Bornerbroek 4' )];
  $seasons = [array('SeasonID' => 3, 'Description' => '2015-2016')];
  $token = array(
    "iat" => $date->getTimestamp() ,
    "exp" => $date->getTimestamp() + 3600,
    "username" => $user["Name"],
    "roles" => $roles,
    "competitions" => $competitions,
    "seasons" => $seasons,
    "defaultSeasonID" => 3,
    "teams" => $teams
  );

  $jwt = JWT::encode($token, $key);
  return $jwt;
}

/**
 * Verifying required params posted or not
 */
function verifyRequiredParams($required_fields) {
  $error = false;
  $error_fields = "";
  $request_params = array();
  $request_params = $_REQUEST;

  // Handling PUT request params
  if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $app = \Slim\Slim::getInstance();
    parse_str($app->request()->getBody() , $request_params);
  }
  foreach ($required_fields as $field) {
    if (!isset($request_params[$field]) || strlen(trim($request_params[$field])) <= 0) {
      $error = true;
      $error_fields.= $field . ', ';
    }
  }

  if ($error) {

    // Required field(s) are missing or empty
    // echo error json and stop the app
    $response = array();
    $app = \Slim\Slim::getInstance();
    $response["error"] = true;
    $response["message"] = 'Required field(s) ' . substr($error_fields, 0, -2) . ' is missing or empty';
    echoRespnse(400, $response);
    $app->stop();
  }
}

/**
 * Echoing json response to client
 * @param String $status_code Http response code
 * @param Int $response Json response
 */
function echoRespnse($status_code, $response) {
  $app = \Slim\Slim::getInstance();

  // Http response code
  $app->status($status_code);

  // setting response content type to json
  $app->contentType('application/json');

  echo json_encode($response);
}

/**
 * Adding Middle Layer to authenticate every request
 * Checking if the request has valid api key in the 'Authorization' header
 */
function authenticate(\Slim\Route $route) {

  // Getting request headers
  $headers = apache_request_headers();
  $response = array();
  $app = \Slim\Slim::getInstance();

  // Verifying Authorization Header
  if (isset($headers['Authorization'])) {
    $db = new DbHandler();

    // get the api key
    $api_key = $headers['Authorization'];

    // validating api key
    if (!$db->isValidApiKey($api_key)) {

      // api key is not present in users table
      $response["error"] = true;
      $response["message"] = "Access Denied. Invalid Api key";
      echoRespnse(401, $response);
      $app->stop();
    }
    else {
      global $user_id;

      // get user primary key id
      $user = $db->getUserId($api_key);
      if ($user != NULL) $user_id = $user["UserID"];
    }
  }
  else {

    // api key is missing in header
    $response["error"] = true;
    $response["message"] = "Api key is misssing";
    echoRespnse(400, $response);
    $app->stop();
  }
}

$app->post('/login', function () use ($app) {
  $body = $app->request->getBody();
  $data = json_decode($body, true);
  $db = new DbHandler();

  $loginResult = $db->checkLogin($data["username"], $data["password"]);
  if ($loginResult) {
    $user = $db->getUserByUserID($loginResult);
    $userRoleResult = $db->getUserRolesByUserID($loginResult);
    $userRoles = array();
    while ($userRole = $userRoleResult->fetch_assoc()) {
      array_push($userRoles, $userRole["Name"]);
    }

    // $userCompetitionsResult = $db->getUserCompetitionsByUserID($loginResult);
    $userCompetitions = array();
    // while ($userCompetition = $userCompetitionsResult->fetch_assoc()) {
    //   array_push($userCompetitions, $userCompetition);
    // }

    $response = createToken($user, $userRoles, $userCompetitions);
    echo $response;
  } else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, 'Invalid username or password');
  }
});

$app->post('/login/test', function () use ($app) {
  $body = $app->request->getBody();
  $data = json_decode($body, true);
  $db = new DbHandler();
  echo $db->testPassword($data["password"]);
});

//  ================================
//  =========== TRAINING ===========
//  ================================

/**
 * Lists all trainings of a particular season
 * method GET
 * url /seasons/:seasonID/trainings
 */
$app->get('/trainings/seasons/:seasonID/', function ($seasonID) use ($app) {
  global $user_id;
  $response = array();
  //if (in_array("admin", $app->jwt->roles)) {
  if(true) {
    $db = new DbHandler();

    // fetching all trainings
    $trainingResult = $db->getTrainingsBySeason($seasonID);

    $response["Error"] = false;
    $response["Trainings"] = array();

    // looping through result and preparing trainings array
    while ($training = $trainingResult->fetch_assoc()) {
      array_push($response["Trainings"], $training);
    }

    echoRespnse(200, $response);
  } else {
    echoRespnse(401, $response);
  }
});

/**
 * Lists all trainings of a particular season for a particular team
 * method GET
 * url /seasons/:seasonID/trainings
 */
$app->get('/trainings/seasons/:seasonID/teams/:teamID', function ($seasonID, $teamID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  // fetching all trainings
  $trainingResult = $db->getTrainingsBySeasonAndTeam($seasonID, $teamID);

  $response["Error"] = false;
  $response["Trainings"] = array();

  // looping through result and preparing trainings array
  while ($training = $trainingResult->fetch_assoc()) {
    array_push($response["Trainings"], $training);
  }

  echoRespnse(200, $response);
});

/**
 * Gets the details of a particular training
 * method GET
 * url /trainings
 */
$app->get('/trainings/:trainingID/teams/:teamID', function ($trainingID, $teamID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  // fetch training details
  $trainingResult = $db->getTraining($user_id, $trainingID);

  // fetch all training attendees
  $attendeesResult = $db->getTrainingAttendeesForTeam($trainingID, $teamID);

  $response["Error"] = false;
  $response["Training"] = array();

  $training = $trainingResult->fetch_assoc();

  $attendees = array();

  while ($attendee = $attendeesResult->fetch_assoc()) {
    $attendee["HasAttended"] = (bool)$attendee["HasAttended"];
    array_push($attendees, $attendee);
  }
  $response["Training"]["TrainingID"] = $training["TrainingID"];
  $response["Training"]["TrainingDate"] = $training["TrainingDate"];
  $response["Training"]["Attendees"] = $attendees;

  echoRespnse(200, $response);
});

/**
 * Gets the details of a particular training
 * method GET
 * url /trainings
 */
$app->get('/trainings/:trainingID', function ($trainingID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  // fetch training details
  $trainingResult = $db->getTraining($user_id, $trainingID);

  // fetch all training attendees
  $attendeesResult = $db->getTrainingAttendees($trainingID);

  $response["Error"] = false;
  $response["Training"] = array();

  $training = $trainingResult->fetch_assoc();

  $attendees = array();

  while ($attendee = $attendeesResult->fetch_assoc()) {
    $attendee["HasAttended"] = (bool)$attendee["HasAttended"];
    array_push($attendees, $attendee);
  }
  $response["Training"]["TrainingID"] = $training["TrainingID"];
  $response["Training"]["TrainingDate"] = $training["TrainingDate"];
  $response["Training"]["Attendees"] = $attendees;

  echoRespnse(200, $response);
});

/**
 * Creates a new training based on the season in the URL with the given training date
 * method POST
 * url /seasons/:seasonID/trainings
 */
$app->post('/trainings', function () use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $trainingDate = json_decode($app->request()->getBody())->{'TrainingDate'};
    $seasonID = json_decode($app->request()->getBody())->{'SeasonID'};
    $db = new DbHandler();

    // creating new match
    $trainingID = $db->createTraining($seasonID, $trainingDate);

    if ($trainingID != NULL) {
      $response["error"] = false;
      $response["message"] = "Training created successfully";
      $response["trainingID"] = $trainingID;
    }
    else {
      $response["error"] = true;
      $response["message"] = "Failed to create training. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

/**
 * Deletes the given training
 * method DELETE
 * url /seasons/:seasonID/trainings
 */
$app->delete('/trainings/:trainingID', function ($trainingID) use ($app) {
  $response = array();
  //if (in_array("admin", $app->jwt->roles)) {
  if(true) {
    $db = new DbHandler();
    $db->deleteTraining($trainingID);

    echoRespnse(201, $response);
  }
  else {
    echoRespnse(401, $response);
  }
});

/**
 * Lists the training overview of a particular season
 * method GET
 * url /seasons/:seasonID/trainings
 */
$app->get('/trainings/training-overview/seasons/:seasonID/teams/:teamID', function ($seasonID, $teamID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  $response["TrainingOverview"] = array();

  $overviewResult = $db->getTrainingOverviewBySeasonAndTeam($seasonID, $teamID);
  $response["Error"] = false;

  // looping through result and preparing trainings array
  while ($result = $overviewResult->fetch_assoc()) {
    $result["AttendedPercentage"] = (double)$result["AttendedPercentage"];
    array_push($response["TrainingOverview"], $result);
  }

  echoRespnse(200, $response);
});

/**
 * Processes changes for a particular training
 * method POST
 * url /trainings
 */
$app->post('/trainings/:trainingID', function ($trainingID) use ($app) {
  $attendees = json_decode($app->request()->getBody())->{'Attendees'};

  //$trainingDate = $app->request()->post('trainingDate');
  $response = array();

  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $db = new DbHandler();
    foreach ($attendees as $attendee) {
      $playerID = $attendee->{'PlayerID'};
      if (is_int($playerID)) {

        $hasAttended = $attendee->{'HasAttended'};
        if($hasAttended) {
          $db->createTrainingStat($trainingID, $playerID);
        } else {
          $db->deleteTrainingStat($trainingID, $playerID);
        }
      }
    }
    echoRespnse(201, $response);
  } else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }

});

//  ================================
//  ============ PLAYER ============
//  ================================

/**
 * Lists all players
 * method GET
 * url /teams
 */
$app->get('/players', function() {
    $response = array();
    $db = new DbHandler();

    $playersResult = $db->getPlayers();

    $response["Error"] = false;
    $response["Players"] = array();

    while ($player = $playersResult->fetch_assoc()) {
        array_push($response["Players"], $player);
    }

    echoRespnse(200, $response);
});

/**
 * Creates a new player
 * method POST
 * url /players
 */
$app->post('/players', function () use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $body = json_decode($app->request()->getBody());
    $firstName = $body->{'FirstName'};
    $surName = $body->{'SurName'};
    $surNamePrefix = $body->{'SurNamePrefix'};
    $dateOfBirth = $body->{'DateOfBirth'};
    $relationCode = $body->{'RelationCode'};
    $emailAddress = $body->{'EmailAddress'};

    $db = new DbHandler();

    $playerID = $db->createPlayer($firstName, $surName, $surNamePrefix, $dateOfBirth, $relationCode, $emailAddress);

    if ($playerID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Player created successfully";
      $response["PlayerID"] = $playerID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create player. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

/**
 * Listing all players of a particular team in a particuar season
 * method GET
 * url /teams/:teamID/seasons/:seasonID/players
 */
$app->get('/teams/:teamID/seasons/:seasonID/players', function($teamID, $seasonID) {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all players for the given team and season
            $result = $db->getPlayersByTeamAndSeason($teamID, $seasonID);

            $response["Error"] = false;
            $response["Players"] = array();

            // looping through result and preparing player array
            while ($player = $result->fetch_assoc()) {
                array_push($response["Players"], $player);
            }

            echoRespnse(200, $response);
        });

/**
 * Lists the details of a particular player for a particular season
 * method GET
 * url /players/:playerID/seasons/:seasonID
 */
$app->get('/players/:playerID/seasons/:seasonID', function ($playerID, $seasonID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  $response["Player"] = array();

  $playerResult = $db->getPlayerDetails($playerID);
  $player = $playerResult->fetch_assoc();

  $trainingsResult = $db->getPlayerTrainings($playerID, $seasonID);
  $soccermatchResults = $db->getPlayerSoccerMatchStats($playerID, $seasonID);

  $response["error"] = false;
  $trainings = array();
  while ($result = $trainingsResult->fetch_assoc()) {
    $result["HasAttended"] = (bool)$result["HasAttended"];
    array_push($trainings, $result);
  }

  $soccermatchStats = array();
  while ($result = $soccermatchResults->fetch_assoc()) {
    $result["IsHomeMatch"] = (bool)$result["IsHomeMatch"];
    $result["YellowCard"] = (bool)$result["YellowCard"];
    $result["RedCard"] = (bool)$result["RedCard"];
    $result["DoubleYellowCard"] = (bool)$result["DoubleYellowCard"];
    array_push($soccermatchStats, $result);
  }

  $player["SoccerMatchStats"] = $soccermatchStats;
  $player["TrainingStats"] = $trainings;
  $response["Player"] = $player;

  echoRespnse(200, $response);
});

//  ==============================
//  ======== SOCCERMATCH =========
//  ==============================

/**
 * Listing all soccer matches of a particular competition
 * method GET
 * url /matches
 */
$app->get('/competitions/:id/soccer-matches', function ($competition_id) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  // fetching all soccer matches for the given competition
  $result = $db->getSoccerMatchesByCompetition($user_id, $competition_id);

  $response["Error"] = false;
  $response["SoccerMatches"] = array();

  // looping through result and preparing soccer matches array
  while ($soccerMatch = $result->fetch_assoc()) {
    array_push($response["SoccerMatches"], $soccerMatch);
  }

  echoRespnse(200, $response);
});

$app->get('/soccer-matches/:id/', function ($soccerMatchID) {
  global $user_id;
  $response = array();
  $response["Error"] = false;
  $response["SoccerMatch"] = array();
  $db = new DbHandler();

  $matchResult = $db->getSoccerMatchByID($user_id, $soccerMatchID);
  $lineupResult = $db->getSoccerMatchLineup($user_id, $soccerMatchID);
  $eventsResult = $db->getSoccerMatchEvents($user_id, $soccerMatchID);

  $response["Error"] = false;
  $soccerMatch = $matchResult->fetch_assoc();
  $lineup = array();

  while ($x = $lineupResult->fetch_assoc()) {
    array_push($lineup, $x);
  }

  $events = array();

  while ($x = $eventsResult->fetch_assoc()) {
    $x["IsPrimaryEvent"] = (bool)$x["IsPrimaryEvent"];
    array_push($events, $x);
  }

  $soccerMatch["Lineup"] = $lineup;
  $soccerMatch["Events"] = $events;
  $response["SoccerMatch"] = $soccerMatch;
  echoRespnse(200, $response);
});

$app->post('/soccer-matches', function() use ($app) {
  if (in_array("admin", $app->jwt->roles)) {
    $homeTeamID = json_decode($app->request()->getBody())->{'HomeTeamID'};
    $awayTeamID = json_decode($app->request()->getBody())->{'AwayTeamID'};
    $competitionRoundID = json_decode($app->request()->getBody())->{'CompetitionRoundID'};

    $response = array();

    if($homeTeamID == $awayTeamID) {
      $response["Error"] = true;
      $response["Message"] = "Home team can not be the same as away team";
      echoRespnse(400, $response);
    }

    $db = new DbHandler();
    // creating new match
    $matchID = $db->createSoccerMatch($competitionRoundID, $homeTeamID, $awayTeamID);

    if ($matchID != NULL) {
        $response["error"] = false;
        $response["message"] = "Match created successfully";
        $response["matchID"] = $matchID;
    } else {
        $response["error"] = true;
        $response["message"] = "Failed to create match. Please try again";
    }
    echoRespnse(201, $response);
  }
else {
    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

$app->delete('/soccer-matches/:id', function($soccerMatchID) use ($app) {
        $response = array();
        $db = new DbHandler();
        $db->deleteSoccerMatch($soccerMatchID);

        echoRespnse(201, $response);
});

$app->put('/soccer-matches/:id', function($soccerMatchID) use ($app) {
        $soccerMatchData = json_decode($app->request()->getBody());
        $response = array();
        $db = new DbHandler();

        $homeGoals = $soccerMatchData->{'HomeGoals'};
        $awayGoals = $soccerMatchData->{'AwayGoals'};
        if(is_int($homeGoals) && is_int($awayGoals)) {
            $db->updateSoccerMatch($soccerMatchID, $homeGoals, $awayGoals);
        }

        echoRespnse(201, $response);
});

$app->post('/soccer-matches/:id/events', function ($soccerMatchID) use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $body = json_decode($app->request()->getBody());
    $eventID = $body->{'EventID'};
    $playerID = $body->{'PlayerID'};
    $minute = $body->{'Minute'};
    $referenceSoccerMatchEventID = array_key_exists('ReferenceSoccerMatchEventID', $body) ? $body->{'ReferenceSoccerMatchEventID'} : NULL;
    $db = new DbHandler();

    // creating new soccer match event
    $newSoccerMatchEventID = $db->createSoccerMatchEvent($soccerMatchID, $eventID, $playerID, $minute, $referenceSoccerMatchEventID);

    if ($newSoccerMatchEventID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Soccer match event created successfully";
      $response["SoccerMatchEventID"] = $newSoccerMatchEventID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create soccer match event. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

$app->delete('/soccer-matches/:soccerMatchID/events/:soccerMatchEventID', function($soccerMatchID, $soccerMatchEventID) use ($app) {
        $response = array();
        $db = new DbHandler();
        $db->deleteSoccerMatchEvent($soccerMatchEventID);

        echoRespnse(201, $response);
});

//  ==============================
//  ======== COMPETITION =========
//  ==============================

/**
 * Listing all competitions of a particular season
 * method GET
 * url /seasons/:id/competitions
 */
$app->get('/seasons/:id/competitions', function($seasonID) {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all competitions for the given season
            $result = $db->getCompetitionsBySeason($seasonID);

            $response["Error"] = false;
            $response["Competitions"] = array();

            // looping through result and preparing competitions array
            while ($competition = $result->fetch_assoc()) {
                array_push($response["Competitions"], $competition);
            }

            echoRespnse(200, $response);
        });

/**
 * Gets a particular competition
 * method GET
 * url /competition/:id
 */
$app->get('/competitions/:id', function($competitionID) {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetch the competition
            $competitionResult = $db->getCompetition($competitionID);
            $competition = $competitionResult->fetch_assoc();

            // fetching all competition rounds for the given competition
            $roundsResult = $db->getCompetitionRoundsByCompetition($competitionID);

            // looping through result and preparing rounds array
            $rounds = array();
            while ($round = $roundsResult->fetch_assoc()) {
                array_push($rounds, $round);
            }

            $competition["Rounds"] = $rounds;

            // fetching all competition rounds for the given competition
            $teamResult = $db->getTeamsByCompetition($competitionID);

            // looping through result and preparing teams array
            $teams = array();
            while ($team = $teamResult->fetch_assoc()) {
                array_push($teams, $team);
            }

            $competition["Teams"] = $teams;

            $response["Error"] = false;
            $response["Competition"] = $competition;

            echoRespnse(200, $response);
        });

/**
 * Listing all soccer matches of a particular competition
 * method GET
 * url /matches
 */
$app->get('/competitions/:competitionID/team-stats/:teamID', function ($competitionID, $teamID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  // fetching all stats for the given competition
  $statsResult = $db->getTeamCompetitionStats($user_id, $competitionID, $teamID);

  $response["Error"] = false;
  $response["CompetitionStats"] = array();

  // looping through result and preparing soccer matches array
  while ($stat = $statsResult->fetch_assoc()) {
    array_push($response["CompetitionStats"], $stat);
  }

  echoRespnse(200, $response);
});

/**
 * Creates a new competition
 * method POST
 * url /competition
 */
$app->post('/competitions', function () use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $seasonID = json_decode($app->request()->getBody())->{'SeasonID'};
    $name = json_decode($app->request()->getBody())->{'Name'};

    $db = new DbHandler();

    // creating new competetition
    $competitionID = $db->createCompetition($seasonID, $name);

    if ($competitionID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Competition round created successfully";
      $response["CompetitionID"] = $competitionID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create competition. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

/**
 * Creates a new competition round
 * method POST
 * url /competition/:id/competition-rounds
 */
$app->post('/competitions/:id/competition-rounds', function ($competitionID) use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $matchdayID = json_decode($app->request()->getBody())->{'MatchdayID'};
    $roundNumber = json_decode($app->request()->getBody())->{'RoundNumber'};
    $description = json_decode($app->request()->getBody())->{'Description'};

    $db = new DbHandler();

    // creating new matchday
    $competitionRoundID = $db->createCompetitionRound($competitionID, $matchdayID, $roundNumber, $description);

    if ($competitionRoundID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Competition round created successfully";
      $response["CompetitionRoundID"] = $competitionRoundID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create competition round. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

/**
 * Gets a specific competition round
 * method GET
 * url /competition-rounds/:competitionRoundID
 */
$app->get('/competition-rounds/:competitionRoundID', function ($competitionRoundID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  $competitionRoundResult = $db->getCompetitionRound($competitionRoundID);
  // fetching all matches for the given competition round
  $matchesResult = $db->getSoccerMatchesByCompetitionRound($competitionRoundID);

  $response["Error"] = false;
  $soccerMatches = array();

  // looping through result and preparing soccer matches array
  while ($match = $matchesResult->fetch_assoc()) {
    array_push($soccerMatches, $match);
  }

  $competitionRound = $competitionRoundResult->fetch_assoc();
  $competitionRound["SoccerMatches"] = $soccerMatches;
  $response["CompetitionRound"] = $competitionRound;

  echoRespnse(200, $response);
});

$app->get('/competitions/:id/teams', function($competitionID) {
  global $user_id;
  $response = array();
  $db = new DbHandler();

  // fetching all teams for the given competition
  $teamResult = $db->getTeamsByCompetition($competitionID);

  $response["Error"] = false;
  $response["Teams"] = array();

  // looping through result and preparing teams array
  while ($team = $teamResult->fetch_assoc()) {
      array_push($response["Teams"], $team);
  }

  echoRespnse(200, $response);
});

/**
 * Creates a new competition team
 * method POST
 * url /competition/:id/competition-rounds
 */
$app->post('/competitions/:id/teams', function ($competitionID) use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $teamID = json_decode($app->request()->getBody())->{'TeamID'};
    $defaultStartTime = json_decode($app->request()->getBody())->{'DefaultStartTime'};

    $db = new DbHandler();

    // creating new competition team
    $competitionTeamID = $db->createCompetitionTeam($competitionID, $teamID, $defaultStartTime);

    if ($competitionTeamID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Competition team created successfully";
      $response["CompetitionTeamID"] = $competitionTeamID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create competition team. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

//  =========================
//  ======== SEASON =========
//  =========================
$app->get('/seasons', function() {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all seasons
            $result = $db->getSeasons();

            $response["Error"] = false;
            $response["Seasons"] = array();

            // looping through result and preparing seasons array
            while ($season = $result->fetch_assoc()) {
                array_push($response["Seasons"], $season);
            }

            echoRespnse(200, $response);
        });

$app->get('/seasons/:id/', function($seasonID) {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetch the competition
            $seasonResult = $db->getSeason($seasonID);
            $season = $seasonResult->fetch_assoc();

            // fetching all matchdays for the given season
            $matchdaysResult = $db->getMatchdaysBySeason($seasonID);
            $matchdays = array();

            // looping through result and preparing matchdays array
            while ($matchday = $matchdaysResult->fetch_assoc()) {
                array_push($matchdays, $matchday);
            }

            $season["Matchdays"] = $matchdays;

            // fetching all competitions for the given season
            $competitionResult = $db->getCompetitionsBySeason($seasonID);
            $competitions = array();

            // looping through result and preparing competitions array
            while ($competition = $competitionResult->fetch_assoc()) {
                array_push($competitions, $competition);
            }

            $season["Competitions"] = $competitions;

            $response["Error"] = false;
            $response["Season"] = $season;

            echoRespnse(200, $response);
        });

/**
 * Creates a new matchday
 * method POST
 * url /matchdays
 */
$app->post('/matchdays', function () use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $matchdayDate = json_decode($app->request()->getBody())->{'MatchdayDate'};
    $seasonID = json_decode($app->request()->getBody())->{'SeasonID'};
    $db = new DbHandler();

    // creating new matchday
    $matchdayID = $db->createMatchday($seasonID, $matchdayDate);

    if ($matchdayID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Matchday created successfully";
      $response["MatchdayID"] = $matchdayID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create matchday. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

//  =======================
//  ======== TEAM =========
//  =======================
/**
 * Lists all teams
 * method GET
 * url /teams
 */
$app->get('/teams', function() {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all teams
            $teamResult = $db->getTeams();

            $response["Error"] = false;
            $response["Teams"] = array();

            // looping through result and preparing teams array
            while ($team = $teamResult->fetch_assoc()) {
                array_push($response["Teams"], $team);
            }

            echoRespnse(200, $response);
        });

/**
 * Gets a particular team
 * method GET
 * url /teams
 */
$app->get('/teams/:teamID', function($teamID) use ($app) {
            $req = $app->request();
            $seasonID = $req->get('seasonID');

            $response = array();
            $db = new DbHandler();

            // fetching all teams
            $teamResult = $db->getTeam($teamID);
            $team = $teamResult->fetch_assoc();

            $playersResult = $db->getSeasonTeamPlayers($teamID, $seasonID);
            $players = array();

            while ($player = $playersResult->fetch_assoc()) {
                array_push($players, $player);
            }

            $team["Players"] = $players;

            $response["Error"] = false;
            $response["Team"] = $team;

            echoRespnse(200, $response);
        });

/**
 * Creates a new team player
 * method POST
 * url /teams/:teamID/players
 */
$app->post('/teams/:teamID/players', function ($teamID) use ($app) {
  $response = array();
  if (in_array("admin", $app->jwt->roles)) {
  // if(true) {
    $body = json_decode($app->request()->getBody());
    $playerID = $body->{'PlayerID'};
    $seasonID  = $body->{'SeasonID'};
    $effectiveDate = $body->{'EffectiveDate'};
    $jerseyNumber = $body->{'JerseyNumber'};

    $db = new DbHandler();

    $playerTeamID = $db->createTeamPlayer($teamID, $playerID, $seasonID, $effectiveDate, $jerseyNumber);

    if ($playerTeamID != NULL) {
      $response["Error"] = false;
      $response["Message"] = "Team player created successfully";
      $response["PlayerTeamID"] = $playerTeamID;
    }
    else {
      $response["Error"] = true;
      $response["Message"] = "Failed to create team player. Please try again";
    }
    echoRespnse(201, $response);
  }
  else {

    /* No scope so respond with 401 Unauthorized */
    echoRespnse(401, $response);
  }
});

//  ==============================
//  ======== MASTER DATA =========
//  ==============================

/**
 * Listing all possible events in a soccer match
 * method GET
 * url /seasons/:id/competitions
 */
$app->get('/events', function() {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all possible events in a soccer match
            $result = $db->getEvents();

            $response["Error"] = false;
            $response["Events"] = array();

            // looping through result and preparing events array
            while ($event = $result->fetch_assoc()) {
              $event["IsPrimaryEvent"] = (bool)$event["IsPrimaryEvent"];
              array_push($response["Events"], $event);
            }

            echoRespnse(200, $response);
        });

/**
 * Listing all possible formations
 * method GET
 * url /formations
 */
$app->get('/formations', function() {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all possible formations
            $result = $db->getFormations();

            $response["Error"] = false;
            $response["Formations"] = array();

            // looping through result and preparing events array
            while ($formation = $result->fetch_assoc()) {
              array_push($response["Formations"], $formation);
            }

            echoRespnse(200, $response);
        });


/**
 * Listing a particular formation
 * method GET
 * url /formations
 */
$app->get('/formations/:id', function($formationID) {
            global $user_id;
            $response = array();
            $db = new DbHandler();

            // fetching all possible formation positions
            $positionResult = $db->getFormationPositions($formationID);

            $response["Error"] = false;
            $formation = array();

            $positions  = array();
            // looping through result and preparing events array
            while ($position = $positionResult->fetch_assoc()) {
              array_push($positions, $position);
            }

            $formation["Positions"] = $positions;
            $response["Formation"] = $formation;

            echoRespnse(200, $response);
        });

//  ================================
//  ======== UNCATEGORISED =========
//  ================================

// $app->get('/matches/:id/', 'authenticate', function($matchID) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             $matchResult = $db->getSoccerMatchByID($user_id, $matchID);
//             $statsResult = $db->getPlayerStatsByMatch($user_id, $matchID);
//             $response["error"] = false;
//             $match = $matchResult->fetch_assoc();
//             $playerStats = array();

//             while ($playerStat = $statsResult->fetch_assoc()) {
//                 array_push($playerStats, $playerStat);
//             }

//             $response["match"] = $match;
//             $response["player-stats"] = $playerStats;
//             echoRespnse(200, $response);
//         });

// /**
//  * Lists the ranking of a particular competition
//  * method GET
//  * url /competitions/:id/ranking
//  */
// $app->get('/competitions/:id/ranking', 'authenticate', function($competition_id) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             // fetching all matches for the given competition
//             $result = $db->getRankingByCompetition($user_id, $competition_id);

//             $response["error"] = false;
//             $response["ranking"] = array();

//             // looping through result and preparing matches array
//             while ($rankedTeam = $result->fetch_assoc()) {
//                 $tmp = array();
//                 $tmp["TeamID"] = $rankedTeam["TeamID"];
//                 $tmp["TeamName"] = $rankedTeam["TeamName"];
//                 $tmp["Played"] = $rankedTeam["Played"];
//                 $tmp["Wins"] = $rankedTeam["Wins"];
//                 $tmp["Draws"] = $rankedTeam["Draws"];
//                 $tmp["Lost"] = $rankedTeam["Lost"];
//                 $tmp["GoalsScored"] = $rankedTeam["GoalsScored"];
//                 $tmp["GoalsConceded"] = $rankedTeam["GoalsConceded"];
//                 $tmp["GoalsDiff"] = $rankedTeam["GoalsDiff"];
//                 $tmp["Points"] = $rankedTeam["Points"];
//                 array_push($response["ranking"], $tmp);
//             }

//             echoRespnse(200, $response);
//         });

// $app->get('/competition-matchdays/:competitionMatchdayID/matches', 'authenticate', function($competition_matchday_id) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             // fetching all matchdays for the given competition
//             $result = $db->getMatchesByCompetitionMatchday($user_id, $competition_matchday_id);

//             $response["error"] = false;
//             $response["matches"] = array();

//             // looping through result and preparing matchdays array
//             while ($match = $result->fetch_assoc()) {
//                 $tmp = array();
//                 $tmp["CompetitionMatchdayID"] = $match["CompetitionMatchdayID"];
//                 $tmp["MatchID"] = $match["MatchID"];
//                 $tmp["HomeTeamName"] = $match["HomeTeamName"];
//                 $tmp["HomeGoals"] = $match["HomeGoals"];
//                 $tmp["AwayTeamName"] = $match["AwayTeamName"];
//                 $tmp["AwayGoals"] = $match["AwayGoals"];

//                 array_push($response["matches"], $tmp);
//             }

//             echoRespnse(200, $response);
//         });

// /**
//  * Lists all trainings of a particular season
//  * method GET
//  * url /seasons/:seasonID/trainings
//  */
// $app->get('/seasons/:seasonID/trainings', 'authenticate', function($seasonID) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             // fetching all trainings
//             $result = $db->getTrainingsBySeason($user_id, $seasonID);

//             $response["error"] = false;
//             $response["trainings"] = array();

//             // looping through result and preparing trainings array
//             while ($match = $result->fetch_assoc()) {
//                 $tmp = array();
//                 $tmp["TrainingID"] = $match["TrainingID"];
//                 $tmp["TrainingDate"] = $match["TrainingDate"];
//                 $tmp["TotalAttended"] = $match["TotalAttended"];
//                 $tmp["TotalPlayers"] = $match["TotalPlayers"];
//                 array_push($response["trainings"], $tmp);
//             }

//             echoRespnse(200, $response);
//         });

// /**
//  * Gets the stats of a particular training
//  * method GET
//  * url /trainings
//  */
// $app->get('/trainings/:trainingID/stats', 'authenticate', function($trainingID) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             // fetching all trainings
//             $result = $db->getTrainingStatsByTraining($user_id, $trainingID);

//             $response["error"] = false;
//             $response["trainingStats"] = array();

//             // looping through result and preparing trainings array
//             while ($trainingstat = $result->fetch_assoc()) {
//                 $tmp = array();
//                 $tmp["PlayerTrainingID"] = $trainingstat["PlayerTrainingID"];
//                 $tmp["PlayerName"] = $trainingstat["PlayerName"];
//                 $tmp["HasAttended"] = $trainingstat["HasAttended"];
//                 array_push($response["trainingStats"], $tmp);
//             }

//             echoRespnse(200, $response);
//         });

// /**
//  * Gets the stats of a particular training
//  * method GET
//  * url /trainings
//  */
// $app->post('/trainings/:trainingID/stats', function($trainingID) use ($app) {
//             $stats = json_decode($app->request()->getBody())->{'stats'};

//             //$trainingDate = $app->request()->post('trainingDate');
//             $response = array();

//             $db = new DbHandler();
//             foreach ($stats as $stat) {
//                 $playerTrainingID = $stat->{'PlayerTrainingID'};
//                 $hasAttended = $stat->{'HasAttended'} == true ? 1 : 0;
//                 if(is_int($playerTrainingID)){
//                     $db->updateTrainingStats($playerTrainingID, $hasAttended);
//                 }
//             }

//             echoRespnse(201, $response);

//         });

// /**
//  * Lists the training overview of a particular season
//  * method GET
//  * url /seasons/:seasonID/trainings
//  */
// $app->get('/seasons/:seasonID/training-overview', 'authenticate', function($seasonID) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             // fetching all trainings
//             $lastTrainingsResult = $db->getLast5TrainingsBySeason($user_id, $seasonID);

//             $response["trainingOverview"] = array();
//             //$response["lastTrainings"] = $lastTrainingsResult->fetch_all();
//             $lastTrainingIDs = array();
//             $lastTrainings = array();
//             while($row = $lastTrainingsResult->fetch_assoc()) {
//                 $lastTrainingIDs[] = $row["TrainingID"];
//                 array_push($lastTrainings, $row);
//             }
//             $response["lastTrainings"] = $lastTrainings;
//             $overviewResult = $db->getTrainingOverview($user_id, $seasonID, $lastTrainingIDs);
//             $response["error"] = false;
//             // looping through result and preparing trainings array
//             while ($result = $overviewResult->fetch_assoc()) {
//                 $tmp = array();
//                 $tmp["PlayerID"] = $result["PlayerID"];
//                 $tmp["PlayerName"] = $result["PlayerName"];
//                 $tmp["Training1"] = $result["Training1"];
//                 $tmp["Training2"] = $result["Training2"];
//                 $tmp["Training3"] = $result["Training3"];
//                 $tmp["Training4"] = $result["Training4"];
//                 $tmp["Training5"] = $result["Training5"];
//                 $tmp["Attendance"] = $result["Attendance"];
//                 //$tmp["Attendance"] = $result["Attendance"];
//                 array_push($response["trainingOverview"], $tmp);
//             }

//             echoRespnse(200, $response);
//         });

// /**
//  * Listing all player stats of a particular match
//  * method GET
//  * url /matches/:id/player-stats
//  */
// $app->get('/matches/:id/player-stats', 'authenticate', function($matchID) {
//             global $user_id;
//             $response = array();
//             $db = new DbHandler();

//             // fetching all matches for the given competition
//             $result = $db->getPlayerStatsByMatch($user_id, $matchID);

//             $response["error"] = false;
//             $response["playerStats"] = array();

//             // looping through result and preparing matches array
//             while ($playerStat = $result->fetch_assoc()) {
//                 $tmp = array();
//                 $tmp["PlayerMatchID"] = $playerStat["PlayerMatchID"];
//                 $tmp["PlayerID"] = $playerStat["PlayerID"];
//                 $tmp["PlayerName"] = $playerStat["PlayerName"];
//                 $tmp["GoalCount"] = $playerStat["GoalCount"];
//                 $tmp["YellowCard"] = $playerStat["YellowCard"];
//                 $tmp["RedCard"] = $playerStat["RedCard"];
//                 array_push($response["playerStats"], $tmp);
//             }

//             echoRespnse(200, $response);
//         });

// /**
//  * User Login
//  * url - /login
//  * method - POST
//  * params - email, password
//  */
// $app->post('/matches', function() use ($app) {
//             // check for required params
//             verifyRequiredParams(array('matchdayID', 'homeTeamID', 'awayTeamID'));

//             // reading post params
//             $matchdayID = $app->request()->post('email');
//             $homeTeamID = $app->request()->post('homeTeamID');
//             $awayTeamID = $app->request()->post('awayTeamID');
//             $response = array();

//             $db = new DbHandler();
//             // creating new match
//             $match_id = $db->createMatch($matchdayID, $homeTeamID, $awayTeamID);

//             if ($match_id != NULL) {
//                 $response["error"] = false;
//                 $response["message"] = "Match created successfully";
//                 $response["task_id"] = $match_id;
//             } else {
//                 $response["error"] = true;
//                 $response["message"] = "Failed to create match. Please try again";
//             }
//             echoRespnse(201, $response);

//         });

$app->run();
?>
