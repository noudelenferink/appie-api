<?php

/**
 * Class to handle all db operations
 * This class will have CRUD methods for database tables
 *
 * @author Ravi Tamada
 */
class DbHandler {

    private $conn;

    function __construct() {
        require_once dirname(__FILE__) . '/DbConnect.php';
        // opening db connection
        $db = new DbConnect();
        $this->conn = $db->connect();
    }

    /* ------------- `User` table method ------------------ */
    public function testPassword($password) {
      require_once 'PassHash.php';
      echo PassHash::hash($password);
    }

    /**
     * Creating new user
     * @param String $name User full name
     * @param String $email User login email id
     * @param String $password User login password
     */
    public function createUser($name, $email, $password) {
        require_once 'PassHash.php';
        $response = array();

        // First check if user already existed in db
        if (!$this->isUserExists($email)) {
            // Generating password hash
            $password_hash = PassHash::hash($password);

            // Generating API key
            $apiKey = $this->generateApiKey();

            // insert query
            $stmt = $this->conn->prepare("INSERT INTO User(Name, Email, PasswordHash, ApiKey, Status) values(?, ?, ?, ?, 1)");
            $stmt->bind_param("ssss", $name, $email, $password_hash, $apiKey);

            $result = $stmt->execute();
            $stmt->close();
            // Check for successful insertion
            if ($result) {
                // User successfully inserted
                return USER_CREATED_SUCCESSFULLY;
            } else {
                // Failed to create user
                return USER_CREATE_FAILED;
            }
        } else {
            // User with same email already existed in the db
            return USER_ALREADY_EXISTED;
        }

        return $response;
    }

    /**
     * Checking user login
     * @param String $email User login email id
     * @param String $password User login password
     * @return boolean User login status success/fail
     */
    public function checkLogin($email, $password) {
        // fetching user by email
        $stmt = $this->conn->prepare("SELECT PasswordHash, UserID FROM User WHERE Email = ?");

        $stmt->bind_param("s", $email);
        if ($stmt->execute()) {
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($result) {
            // Found user with the email
            // Now verify the password

            if (PassHash::check_password($result["PasswordHash"], $password)) {
                // User password is correct
                return $result["UserID"];
            } else {
                // user password is incorrect
                return null;
            }
        } else {

            // user not existed with the email
            return null;
        }
    }

    /**
     * Checking for duplicate user by email address
     * @param String $email email to check in db
     * @return boolean
     */
    private function isUserExists($email) {
        $stmt = $this->conn->prepare("SELECT UserID from User WHERE Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }

    /**
     * Fetching user by userID
     * @param String $userID User user id
     */
    public function getUserByUserID($userID) {
        $stmt = $this->conn->prepare
        ("SELECT Name
          , Email
          , ApiKey
          , Status
          , CreatedAt
          , DefaultTeamID
          FROM User
          WHERE UserID = ?");
        $stmt->bind_param("s", $userID);
        if ($stmt->execute()) {
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $user;
        } else {
            return NULL;
        }
    }

    /**
     * Fetching competitions that the given user has access to.
     * @param String $userID User user id
     */
    public function getUserCompetitionsByUserID($userID) {
      $stmt = $this->conn->prepare
      ("SELECT uc.CompetitionID
        FROM UserCompetitions uc
        JOIN Competition c ON uc.CompetitionID = c.CompetitionID
        WHERE UserID = ?");
      $stmt->bind_param("s", $userID);
      $stmt->execute();
      $roles = $stmt->get_result();
      $stmt->close();
      return $roles;
    }
        /**
     * Fetching roles that are assigned to the given user.
     * @param String $userID User user id
     */
    public function getUserRolesByUserID($userID) {
        $stmt = $this->conn->prepare("SELECT r.Name FROM UserRole ur JOIN Role r on ur.RoleID = r.RoleID WHERE UserID = ?");
        $stmt->bind_param("s", $userID);
        $stmt->execute();
        $roles = $stmt->get_result();
        $stmt->close();
        return $roles;
    }



    /**
     * Fetching user api key
     * @param String $user_id user id primary key in user table
     */
    public function getApiKeyById($user_id) {
        $stmt = $this->conn->prepare("SELECT ApiKey FROM User WHERE UserID = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $ApiKey = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $ApiKey;
        } else {
            return NULL;
        }
    }

    /**
     * Fetching user UserID by api key
     * @param String $ApiKey user api key
     */
    public function getUserId($ApiKey) {
        $stmt = $this->conn->prepare("SELECT UserID FROM User WHERE ApiKey = ?");
        $stmt->bind_param("s", $ApiKey);
        if ($stmt->execute()) {
            $user_id = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $user_id;
        } else {
            return NULL;
        }
    }

    /**
     * Validating user api key
     * If the api key is there in db, it is a valid key
     * @param String $ApiKey user api key
     * @return boolean
     */
    public function isValidApiKey($ApiKey) {
        $stmt = $this->conn->prepare("SELECT UserID from User WHERE ApiKey = ?");
        $stmt->bind_param("s", $ApiKey);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }

    /**
     * Generating random Unique MD5 String for user Api key
     */
    private function generateApiKey() {
        return md5(uniqid(rand(), true));
    }

    /**
     * Fetches all soccer matches for the given competition id
     * @param String $user_id id of the user
     * @param String $competition_id id of the competition
     */
    public function getSoccerMatchesByCompetition($user_id, $competitionID) {
        $stmt = $this->conn->prepare
        ("SELECT sm.*
          , th.Name as HomeTeamName
          , ta.Name as AwayTeamName
          , th.TeamLogoFile as HomeTeamLogo
          , ta.TeamLogoFile as AwayTeamLogo
          , md.Date as MatchDate
          , ct.DefaultStartTime
          , cr.RoundNumber
          FROM SoccerMatch sm
          JOIN CompetitionRound cr on sm.CompetitionRoundID = cr.CompetitionRoundID AND cr.CompetitionID = ?
          JOIN CompetitionTeam ct on sm.HomeTeamID = ct.TeamID and ct.CompetitionID = ?
          JOIN Matchday md on cr.MatchdayID = md.MatchdayID
          JOIN Team th on sm.HomeTeamID = th.TeamID
          JOIN Team ta on sm.AwayTeamID = ta.TeamID
        ");
        $stmt->bind_param("ii", $competitionID, $competitionID);
        $stmt->execute();
        $tasks = $stmt->get_result();
        $stmt->close();
        return $tasks;
    }

    /**
     * Fetches all soccer matches for the given season and team id
     * @param String $user_id id of the user
     * @param String $seasonID id of the season
     * @param String $teamID id of the team
     */
    public function getSoccerMatchesBySeasonAndTeam($user_id, $seasonID, $teamID) {
        $stmt = $this->conn->prepare
        ("SELECT
            sm.SoccerMatchID
          , CASE WHEN sm.HomeTeamID = ? THEN 1 ELSE 0 END AS IsHomeMatch
          , t.TeamID AS OpponentTeamID
          , t.Name AS OpponentTeam
          , t.TeamLogoFile AS OpponentLogoUrl
          , CASE WHEN sm.HomeTeamID = ? THEN sm.HomeGoals ELSE sm.AwayGoals END AS TeamGoals
          , CASE WHEN sm.AwayTeamID = ? THEN sm.HomeGoals ELSE sm.AwayGoals END AS OpponentGoals
          , sm.IsPracticeMatch
          , sm.FallbackDateTime
          , md.Date as MatchDate
          , ct.DefaultStartTime
          , cr.RoundNumber
          , c.Name AS CompetitionName
          FROM SoccerMatch sm
          LEFT JOIN CompetitionRound cr on sm.CompetitionRoundID = cr.CompetitionRoundID
          LEFT JOIN CompetitionTeam ct on sm.HomeTeamID = ct.TeamID and ct.CompetitionID = cr.CompetitionID
          LEFT JOIN Competition c on cr.CompetitionID = c.CompetitionID
          LEFT JOIN Matchday md on cr.MatchdayID = md.MatchdayID
          LEFT JOIN Season s on s.SeasonID = ?
          JOIN Team t on t.TeamID = CASE WHEN sm.HomeTeamID = ? THEN sm.AwayTeamID ELSE sm.HomeTeamID END
          WHERE (sm.HomeTeamID = ? OR sm.AwayTeamID = ?)
            AND (c.SeasonID = ? OR (sm.IsPracticeMatch AND sm.FallbackDateTime BETWEEN s.StartDate AND s.EndDate))
        ");
        $stmt->bind_param("iiiiiiii", $teamID, $teamID, $teamID, $seasonID, $teamID, $teamID, $teamID, $seasonID);
        $stmt->execute();
        $tasks = $stmt->get_result();
          $stmt->close();
        return $tasks;
    }

    public function getMatchesByCompetitionMatchday($user_id, $competition_matchday_id) {
        $stmt = $this->conn->prepare
        ("SELECT m.CompetitionMatchdayID
          , m.SoccerMatchID
          , th.Name HomeTeamName
          , m.HomeGoals
          , ta.Name AwayTeamName
          , m.AwayGoals
          FROM SoccerMatch m
          JOIN Team th on m.HomeTeamID = th.TeamID
          JOIN Team ta on m.AwayTeamID = ta.TeamID
          WHERE m.CompetitionMatchdayID = ?
        ");
        $stmt->bind_param("i", $competition_matchday_id);
        $stmt->execute();
        $tasks = $stmt->get_result();
        $stmt->close();
        return $tasks;
    }

    /**
     * Fetches all seasons
     * @param String $user_id id of the user
     */
    public function getSeasons(){
      $stmt = $this->conn->prepare
        ("SELECT *
          FROM Season s
        ");
        $stmt->execute();
        $seasons = $stmt->get_result();
        $stmt->close();
        return $seasons;
    }

    /**
     * Fetches a particular season
     * @param String $seasonID: The ID of the season
     */
    public function getSeason($seasonID){
      $stmt = $this->conn->prepare
        ("SELECT *
          FROM Season s
          WHERE s.SeasonID = ?
        ");
        $stmt->bind_param("i", $seasonID);
        $stmt->execute();
        $season = $stmt->get_result();
        $stmt->close();
        return $season;
    }

    /**
     * Fetches all matchdays for the given season id
     * @param String $user_id id of the user
     * @param String $seasonID of the season
     */
    public function getMatchdaysBySeason($seasonID){
      $stmt = $this->conn->prepare
        ("SELECT *
          FROM Matchday m
          WHERE m.SeasonID = ?
        ");
        $stmt->bind_param("i", $seasonID);
        $stmt->execute();
        $matchdays = $stmt->get_result();
        $stmt->close();
        return $matchdays;
    }

    /**
     * Creates a matchday for the given season id
     * @param String $seasonID of the season
     * @param String $matchdayDate of the matchday date
     */
    public function createMatchday($seasonID, $matchdayDate) {
      $stmt = $this->conn->prepare("INSERT INTO Matchday(SeasonID, Date) VALUES(?,?)");
        $stmt->bind_param("ss", $seasonID, $matchdayDate);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            // matchday created
            $newMatchdayID = $this->conn->insert_id;
            return $newMatchdayID;
        } else {
            // failed to create matchday
            return NULL;
        }
    }

    /**
     * Fetches all competitions for the given season id
     * @param String $user_id id of the user
     * @param String $seasonID of the season
     */
    public function getCompetitionsBySeason($seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT *
          FROM Competition c
          WHERE c.SeasonID = ?
        ");
        $stmt->bind_param("i", $seasonID);
        $stmt->execute();
        $tasks = $stmt->get_result();
        $stmt->close();
        return $tasks;
    }

        /**
     * Fetches all team competitions for the given season id
     * @param String $user_id id of the user
     * @param String $seasonID of the season
     */
    public function getTeamCompetitionsBySeason($teamID, $seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT c.CompetitionID
          , c.Name AS CompetitionName
          FROM Competition c
          JOIN CompetitionTeam ct ON c.CompetitionID = ct.CompetitionID
          WHERE ct.TeamID = ? AND c.SeasonID = ?
        ");
        $stmt->bind_param("ii", $teamID, $seasonID);
        $stmt->execute();
        $competitions = $stmt->get_result();
        $stmt->close();
        return $competitions;
    }

    public function getTeamsByCompetition($competitionID) {
        $stmt = $this->conn->prepare
        ("SELECT tc.TeamID
          , t.Name as TeamName
          , tc.DefaultStartTime
          , t.TeamLogoFile
          , CAST(IFNULL(tc.PointsDeducted, 0) AS UNSIGNED) AS PointsDeducted
          FROM CompetitionTeam tc
          JOIN Team t on tc.TeamID = t.TeamID
          WHERE tc.CompetitionID = ?
        ");
        $stmt->bind_param("i", $competitionID);
        $stmt->execute();
        $teams = $stmt->get_result();
        $stmt->close();
        return $teams;
    }


    public function getTeamPracticeMatchesBySeason($teamID, $seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT
            sm.SoccerMatchID
          , CASE WHEN sm.HomeTeamID = ? THEN 1 ELSE 0 END AS IsHomeMatch
          , t.TeamID AS OpponentTeamID
          , t.Name AS OpponentTeam
          , t.TeamLogoFile AS OpponentLogoUrl
          , CASE WHEN sm.HomeTeamID = ? THEN sm.HomeGoals ELSE sm.AwayGoals END AS TeamGoals
          , CASE WHEN sm.AwayTeamID = ? THEN sm.HomeGoals ELSE sm.AwayGoals END AS OpponentGoals
          , sm.FallbackDateTime
          FROM SoccerMatch sm
          JOIN Season s on s.SeasonID = ?
          JOIN Team t on t.TeamID = CASE WHEN sm.HomeTeamID = ? THEN sm.AwayTeamID ELSE sm.HomeTeamID END
          WHERE (sm.HomeTeamID = ? OR sm.AwayTeamID = ?)
            AND sm.IsPracticeMatch = TRUE
            AND sm.FallbackDateTime BETWEEN s.StartDate AND s.EndDate
        ");
        $stmt->bind_param("iiiiiii", $teamID, $teamID, $teamID, $seasonID, $teamID, $teamID, $teamID);
        $stmt->execute();
        $teams = $stmt->get_result();
        $stmt->close();
        return $teams;
    }

    public function createCompetitionTeam($competitionID, $teamID, $defaultStartTime) {
        $stmt = $this->conn->prepare
        ("INSERT INTO CompetitionTeam (CompetitionID, TeamID, DefaultStartTime)
          VALUES (?, ?, ?)
        ");
        $stmt->bind_param("sss", $competitionID, $teamID, $defaultStartTime);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            // row created
            $newCompetitionTeamID = $this->conn->insert_id;
            return $newCompetitionTeamID;
        } else {
            // failed to create
            return NULL;
        }
    }

    /**
     * Fetches the ranking for the given competition id
     * @param String $user_id id of the user
     * @param String $competition_id id of the competition
     */
    public function getRankingByCompetition($user_id, $competition_id) {
        $stmt = $this->conn->prepare("call getRankingforCompetitionID(?)");
        $stmt->bind_param("i", $competition_id);
        $stmt->execute();
        $ranking = $stmt->get_result();
        $stmt->close();
        return $ranking;
    }

    /**
     * Fetches all teams
     */
    public function getTeams() {
        $stmt = $this->conn->prepare
        ("SELECT *
          FROM Team
        ");
        $stmt->execute();
        $teams = $stmt->get_result();
        $stmt->close();
        return $teams;
    }

        /**
     * Fetch a particular team
     */
    public function getTeam($teamID) {
        $stmt = $this->conn->prepare
        ("SELECT *
          FROM Team
          WHERE TeamID = ?
        ");
        $stmt->bind_param("i", $teamID);
        $stmt->execute();
        $team = $stmt->get_result();
        $stmt->close();
        return $team;
    }

    /**
     * Fetches all trainings for a particular season
     * @param String $user_id id of the user
     */
    public function getTrainingsBySeason($seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT t.TrainingID
                ,t.TrainingDate
                ,CAST(IFNULL(SUM(ptr.HasAttended), 0) as signed) as TotalAttended
                ,COUNT(ptr.PlayerTrainingID) as TotalPlayers
          FROM Training t
          LEFT JOIN PlayerTraining ptr ON t.TrainingID = ptr.TrainingID
          WHERE t.SeasonID = ?
          GROUP BY t.TrainingID, t.TrainingDate
        ");
        $stmt->bind_param("i", $seasonID);
        $stmt->execute();
        $trainings = $stmt->get_result();
        $stmt->close();
        return $trainings;
    }

    /**
     * Fetches all trainings for a particular season and team
     * @param String $user_id id of the user
     */
    public function getTrainingsBySeasonAndTeam($seasonID, $teamID) {
        $stmt = $this->conn->prepare
        ("SELECT t.TrainingID
                ,t.TrainingDate
                ,t.IsBonus
                ,CAST(SUM(ptr.HasAttended) as signed) as TotalAttended
                ,COUNT(ptr.PlayerTrainingID) as TotalPlayers
          FROM Training t
          LEFT JOIN PlayerTraining ptr ON t.TrainingID = ptr.TrainingID
          LEFT JOIN PlayerTeam pte ON ptr.PlayerID = pte.PlayerID and pte.SeasonID = ? AND pte.TeamID = ?
          WHERE t.SeasonID = ? AND t.TeamID = ?
          GROUP BY t.TrainingID, t.TrainingDate
        ");
        $stmt->bind_param("iiii", $seasonID, $teamID, $seasonID, $teamID);
        $stmt->execute();
        $trainings = $stmt->get_result();
        $stmt->close();
        return $trainings;
    }

    /**
     * Fetches a particular training
     * @param String $user_id id of the user
     * @param String $trainingID id of the training
     */
    public function getTraining($user_id, $trainingID) {
        $stmt = $this->conn->prepare
        ("SELECT t.TrainingID
                ,t.TrainingDate
                ,t.TeamID
                ,t.IsBonus
          FROM Training t
          WHERE TrainingID = ?
        ");
        $stmt->bind_param("i", $trainingID);
        $stmt->execute();
        $training = $stmt->get_result();
        $stmt->close();
        return $training;
    }

    /**
     * Fetches the attendees of a particular training
     * @param String $user_id id of the user
     */
    public function getTrainingAttendees($trainingID) {
        $stmt = $this->conn->prepare
        ("SELECT pte.PlayerID
          , t.TrainingID
          , ptr.HasAttended
          , p.FirstName
          , p.SurName
          , p.SurNamePrefix
          FROM PlayerTeam pte
          JOIN Training t ON t.TrainingID = ?
          LEFT JOIN PlayerTraining ptr ON ptr.TrainingID = t.TrainingID and ptr.PlayerID = pte.PlayerID
          JOIN Player p on pte.PlayerID = p.PlayerID
          WHERE pte.SeasonID = t.SeasonID
          and pte.TeamID in (1, 27)");
        $stmt->bind_param("i", $trainingID);
        $stmt->execute();
        $training = $stmt->get_result();
        $stmt->close();
        return $training;
    }

    /**
     * Fetches the attendees of a particular training for a specific team
     * @param String $user_id id of the user
     */
    public function getTrainingAttendeesForTeam($trainingID, $teamID) {
        $stmt = $this->conn->prepare
        ("SELECT p.FirstName,
                 p.SurName,
                 p.SurNamePrefix,
                 IFNULL(ptr.HasAttended, 0) AS HasAttended
                 FROM PlayerTeam pte
                 JOIN Player p ON pte.PlayerID = p.PlayerID AND pte.SeasonID = (SELECT SeasonID FROM Training WHERE TrainingID = ?)
                 LEFT JOIN PlayerTraining ptr ON ptr.PlayerID = p.PlayerID AND ptr.TrainingID = ?
                 WHERE pte.TeamID = ?");
        $stmt->bind_param("iii", $trainingID, $trainingID, $teamID);
        $stmt->execute();
        $attendees = $stmt->get_result();
        $stmt->close();
        return $attendees;
    }

    /**
     * Fetches the training overview for a particular season
     * @param String $seasonID id of the season
     */
    public function getTrainingOverviewBySeasonAndTeam($seasonID, $teamID) {
        $stmt = $this->conn->prepare("call getTrainingOverviewBySeasonAndTeam(?, ?)");
        $stmt->bind_param("ii", $seasonID, $teamID);
        $stmt->execute();
        $trainingOverview = $stmt->get_result();
        $stmt->close();
        return $trainingOverview;
    }

    public function getLast5TrainingsBySeason($user_id, $seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT TrainingID as TrainingID
          ,TrainingDate as TrainingDate
          FROM Training
          WHERE SeasonID = ?
          ORDER BY TrainingDate DESC
          LIMIT 5 ");
        $stmt->bind_param("i", $seasonID);
        $stmt->execute();
        $trainings = $stmt->get_result();
        $stmt->close();
        return $trainings;
    }

    /**
     * Adds the given match
     * @param String $user_id id of the user
     * @param String $matchdayID the id of the matchday
     * @param String $homeTeamID the id of the home team
     * @param String $awayTeamID the id of the away team
     */
    public function createTraining($seasonID, $trainingDate) {
        $stmt = $this->conn->prepare("INSERT INTO Training(SeasonID, TrainingDate) VALUES(?,?)");
        $stmt->bind_param("ss", $seasonID, $trainingDate);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            // match row created
            $newTrainingID = $this->conn->insert_id;
            return $newTrainingID;
        } else {
            // match failed to create
            return NULL;
        }
    }

    /**
     * Deletes the given training
     * @param String $trainingID the id of the training
     */
    public function deleteTraining($trainingID) {
      $stmt = $this->conn->prepare
      ("DELETE FROM Training
        WHERE TrainingID = ?
      ");

      $stmt->bind_param("i", $trainingID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    public function createPlayerTrainings($seasonID, $trainingID) {
        $stmt = $this->conn->prepare
        ("INSERT INTO PlayerTraining(TrainingID, PlayerID)
          SELECT ?, PlayerID FROM PlayerTeam WHERE SeasonID = ?
        ");
        $stmt->bind_param("ss", $trainingID, $seasonID);
        $result = $stmt->execute();
         $stmt->close();
         return $result;
    }

    public function createTrainingStat($trainingID, $playerID) {
      $stmt = $this->conn->prepare
      ("INSERT INTO PlayerTraining(TrainingID, PlayerID, HasAttended)
        VALUES (?, ?, true)
      ");

      $stmt->bind_param("ii", $trainingID, $playerID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    public function deleteTrainingStat($trainingID, $playerID) {
      $stmt = $this->conn->prepare
      ("DELETE FROM PlayerTraining
        WHERE TrainingID = ? AND PlayerID = ?
      ");

      $stmt->bind_param("ii", $trainingID, $playerID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    public function getPlayerTrainings($playerID, $seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT t.TrainingID
          , t.TrainingDate
          , t.IsBonus
          , IFNULL(cast(pt.HasAttended as signed), 0) AS HasAttended
          FROM Training t
          JOIN PlayerTeam pte on t.TeamID = pte.TeamID and t.SeasonID = pte.SeasonID
          LEFT JOIN PlayerTraining pt on pt.TrainingID = t.TrainingID and pt.PlayerID = pte.PlayerID
          WHERE pte.PlayerID = ? AND t.SeasonID = ?
        ");
        $stmt->bind_param("ii", $playerID, $seasonID);
        $stmt->execute();
        $trainings = $stmt->get_result();
        $stmt->close();
        return $trainings;
    }

    public function getPlayers() {
      $stmt = $this->conn->prepare
        ("SELECT *, GetPlayerName(p.PlayerID) AS PlayerName
          FROM Player p
        ");
        $stmt->execute();
        $players = $stmt->get_result();
        $stmt->close();
        return $players;
    }

    public function getPlayerSoccerMatchStats($playerID, $seasonID) {
      $stmt = $this->conn->prepare
        ("SELECT sm.SoccerMatchID
          , s.SeasonID
          , fp.Code AS PositionCode
          , fp.Name AS Position
          , md.Date AS MatchDate
          , sm.FallbackDateTime
          , CASE WHEN pt.TeamID = sm.HomeTeamID THEN 1 ELSE 0 END AS IsHomeMatch
          , t.Name AS OpponentTeam
          , t.TeamLogoFile AS OpponentLogoUrl
          , CASE WHEN pt.TeamID = sm.HomeTeamID  THEN sm.HomeGoals ELSE sm.AwayGoals END AS TeamGoals
          , CASE WHEN pt.TeamID = sm.AwayTeamID THEN sm.HomeGoals ELSE sm.AwayGoals END AS OpponentGoals
          , CAST(IFNULL(events.Goals, 0) AS UNSIGNED) AS Goals
          , CAST(IFNULL(events.Assists, 0) AS UNSIGNED) AS Assists
          , CAST(IFNULL(events.YellowCard, 0) AS UNSIGNED) AS YellowCard
          , CAST(IFNULL(events.RedCard, 0) AS UNSIGNED) AS RedCard
          , CAST(IFNULL(events.DoubleYellowCard, 0) AS UNSIGNED) AS DoubleYellowCard
          , c.CompetitionTypeID
          , c.Name AS CompetitionName
          , pt.TeamID
          FROM PlayerSoccerMatch pm
          JOIN SoccerMatch sm ON pm.SoccerMatchID = sm.SoccerMatchID
          JOIN FormationPosition fp ON fp.PositionID = pm.PositionID AND (fp.FormationID = sm.FormationID OR fp.FormationID IS NULL)
          JOIN CompetitionRound cr ON cr.CompetitionRoundID = sm.CompetitionRoundID
          JOIN Matchday md ON md.MatchdayID = cr.MatchdayID
          JOIN Competition c ON c.CompetitionID = cr.CompetitionID
          JOIN Season s ON s.SeasonID = c.SeasonID
          JOIN PlayerTeam pt on pm.PlayerID = pt.PlayerID and pt.SeasonID = s.SeasonID
          JOIN Team t on t.TeamID = CASE WHEN pt.TeamID = sm.HomeTeamID THEN sm.AwayTeamID ELSE sm.HomeTeamID END
          LEFT JOIN (SELECT sme.PlayerID
                    , sme.SoccerMatchID
                    , SUM(CASE WHEN sme.EventID = 1 THEN 1 ELSE 0 END) AS Goals
                    , SUM(CASE WHEN sme.EventID = 5 THEN 1 ELSE 0 END) AS Penalties
                    , SUM(CASE WHEN sme.EventID = 7 THEN 1 ELSE 0 END) AS Assists
                    , CASE WHEN sme.EventID = 2 THEN 1 ELSE 0 END AS YellowCard
                    , CASE WHEN (sme.EventID = 3) THEN 1 ELSE 0 END AS RedCard
                    , CASE WHEN (sme.EventID = 3 AND sme.ReferenceSoccerMatchEventID IS NOT NULL) THEN 1 ELSE 0 END AS DoubleYellowCard
                    FROM SoccerMatchEvent sme
                    WHERE sme.PlayerID = ?
                    GROUP BY sme.PlayerID, sme.SoccerMatchID, YellowCard, RedCard, DoubleYellowCard) events on pm.PlayerID = events.PlayerID and pm.SoccerMatchID = events.SoccerMatchID
          WHERE pm.PlayerID = ? AND s.SeasonID = ?
        ");
        $stmt->bind_param("iii", $playerID, $playerID, $seasonID);
        $stmt->execute();
        $stats = $stmt->get_result();
        $stmt->close();
        return $stats;
    }

    public function getPlayerDetails($playerID) {
        $stmt = $this->conn->prepare
        ("SELECT p.PlayerID
          ,GetPlayerName(p.PlayerID) as PlayerName
          ,p.DateOfBirth
          FROM Player p
          WHERE p.PlayerID = ?
        ");
        $stmt->bind_param("i", $playerID);
        $stmt->execute();
        $player = $stmt->get_result();
        $stmt->close();
        return $player;
    }


    /**
     * Fetches all competition rounds for the given competition id
     * @param String $user_id id of the user
     * @param String $competition_id id of the competition
     */
    public function getCompetitionRoundsByCompetition($competitionID) {
        $stmt = $this->conn->prepare
        ("SELECT cr.CompetitionRoundID
          , m.Date
          , cr.CompetitionID
          , cr.MatchdayID
          , cr.RoundNumber
          , cr.Description
          , CAST(IFNULL(sm.NumMatches, 0) AS UNSIGNED) AS TotalMatches
          , CAST(IFNULL(sm.NumPlayedMatches, 0) AS UNSIGNED) AS PlayedMatches
          FROM CompetitionRound cr
          JOIN Matchday m ON cr.MatchdayID = m.MatchdayID
          LEFT JOIN (SELECT CompetitionRoundID
                , COUNT(1) AS NumMatches
                , SUM(case when SoccerMatchStatusID = 'PLD' then 1 else 0 END) AS NumPlayedMatches
                FROM SoccerMatch
                GROUP BY CompetitionRoundID) sm ON cr.CompetitionRoundID = sm.CompetitionRoundID
          WHERE CompetitionID = ?
        ");
        $stmt->bind_param("i", $competitionID);
        $stmt->execute();
        $competitionRounds = $stmt->get_result();
        $stmt->close();
        return $competitionRounds;
    }

    public function createCompetition($seasonID, $name) {
      $stmt = $this->conn->prepare
      ("INSERT INTO Competition(SeasonID, Name)
        VALUES(?, ?)
      ");
      $stmt->bind_param("ss", $seasonID, $name);
      $result = $stmt->execute();
      $stmt->close();
      if ($result) {
          // competition created
          $newCompetitionID = $this->conn->insert_id;
          return $newCompetitionID;
      } else {
          // failed to create competition
          return NULL;
      }
    }

    public function createCompetitionRound($competitionID, $matchdayID, $roundNumber, $description) {
      $stmt = $this->conn->prepare
      ("INSERT INTO CompetitionRound(CompetitionID, MatchdayID, RoundNumber, Description)
        VALUES(?, ?, ?, ?)
      ");
      $stmt->bind_param("ssss", $competitionID, $matchdayID, $roundNumber, $description);
      $result = $stmt->execute();
      $stmt->close();
      if ($result) {
          // competition round created
          $newCompetitionRoundID = $this->conn->insert_id;
          return $newCompetitionRoundID;
      } else {
          // failed to create competition round
          return NULL;
      }
    }

    public function getCompetition($competitionID) {
      $stmt = $this->conn->prepare
        ("SELECT c.CompetitionID
          , c.Name
          FROM Competition c
          WHERE c.CompetitionID = ?
        ");
        $stmt->bind_param("i", $competitionID);
        $stmt->execute();
        $competition = $stmt->get_result();
        $stmt->close();
        return $competition;
    }

    public function getCompetitionRound($competitionRoundID) {
      $stmt = $this->conn->prepare
        ("SELECT cr.CompetitionRoundID
          , cr.MatchdayID
          , m.Date
          , cr.CompetitionID
          , cr.RoundNumber
          , cr.Description
          FROM CompetitionRound cr
          JOIN Matchday m on m.MatchdayID = cr.MatchdayID
          WHERE cr.CompetitionRoundID = ?
        ");
        $stmt->bind_param("i", $competitionRoundID);
        $stmt->execute();
        $competitionRound = $stmt->get_result();
        $stmt->close();
        return $competitionRound;
    }

    public function getSoccerMatchesByCompetitionRound($competitionRoundID) {
      $stmt = $this->conn->prepare
        ("SELECT sm.CompetitionRoundID
          , sm.SoccerMatchID
          , sm.HomeTeamID
          , th.Name HomeTeam
          , sm.HomeGoals
          , sm.AwayTeamID
          , ta.Name AwayTeam
          , sm.AwayGoals
          FROM SoccerMatch sm
          JOIN CompetitionRound cr on sm.CompetitionRoundID = cr.CompetitionRoundID
          JOIN Team th on sm.HomeTeamID = th.TeamID
          JOIN Team ta on sm.AwayTeamID = ta.TeamID
          WHERE sm.CompetitionRoundID = ?
        ");
        $stmt->bind_param("i", $competitionRoundID);
        $stmt->execute();
        $soccerMatches = $stmt->get_result();
        $stmt->close();
        return $soccerMatches;
    }

    public function createSoccerMatch($competitionRoundID, $homeTeamID, $awayTeamID) {
        $stmt = $this->conn->prepare("INSERT INTO SoccerMatch(CompetitionRoundID, HomeTeamID, AwayTeamID) VALUES(?,?,?)");
        $stmt->bind_param("iii", $competitionRoundID, $homeTeamID, $awayTeamID);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            // match row created
            $newMatchID = $this->conn->insert_id;

            return $newMatchID;
        } else {
            // match failed to create
            return NULL;
        }
    }

    public function getSoccerMatchByID($user_id, $matchID) {
        $stmt = $this->conn->prepare
        ("SELECT sm.SoccerMatchID
          ,sm.HomeTeamID
          ,th.Name as HomeTeam
          ,sm.HomeGoals
          ,sm.AwayTeamID
          ,ta.Name as AwayTeam
          ,sm.AwayGoals
          , th.TeamLogoFile as HomeLogo
          , ta.TeamLogoFile as AwayLogo
          , md.Date as MatchDate
          , md.SeasonID
          , ct.DefaultStartTime
          , f.Description as Formation
          , f.FormationID
          FROM SoccerMatch sm
          JOIN Team th on sm.HomeTeamID = th.TeamID
          JOIN Team ta on sm.AwayTeamID = ta.TeamID
          LEFT JOIN CompetitionRound cr on sm.CompetitionRoundID = cr.CompetitionRoundID
          JOIN CompetitionTeam ct on sm.HomeTeamID = ct.TeamID and ct.CompetitionID = cr.CompetitionID
          LEFT JOIN Matchday md on cr.MatchdayID = md.MatchdayID
          LEFT JOIN Formation f on sm.FormationID = f.FormationID
          WHERE sm.SoccerMatchID = ?
        ");
        $stmt->bind_param("i", $matchID);
        $stmt->execute();
        $match = $stmt->get_result();
        $stmt->close();
        return $match;
    }

    public function updateSoccerMatch($soccerMatchID, $homeGoals, $awayGoals) {
      $stmt = $this->conn->prepare
      ("UPDATE SoccerMatch
        SET HomeGoals = ?
        , AwayGoals = ?
        , SoccerMatchStatusID = 'PLD'
        WHERE SoccerMatchID = ?
      ");

      $stmt->bind_param("sss", $homeGoals, $awayGoals, $soccerMatchID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    public function updateSoccerMatchFormation($soccerMatchID, $formationID) {
      $stmt = $this->conn->prepare
      ("UPDATE SoccerMatch
        SET FormationID = ?
        WHERE SoccerMatchID = ?
      ");

      $stmt->bind_param("ss", $formationID, $soccerMatchID);
      $result = $stmt->execute();
      $stmt->close();
      if ($this->conn->affected_rows > 0) {
        return $this->conn->affected_rows;
      } else {
        return $this->conn->affected_rows;
      }
    }

    public function cleanSoccerMatchLineup($soccerMatchID) {
      $stmt = $this->conn->prepare
      ("DELETE FROM PlayerSoccerMatch
        WHERE SoccerMatchID = ?
      ");

      $stmt->bind_param("s", $soccerMatchID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    public function createPlayerSoccerMatchEntry($soccerMatchID, $playerID, $positionID) {
      $stmt = $this->conn->prepare
        ("INSERT INTO PlayerSoccerMatch (SoccerMatchID, PlayerID, PositionID)
          VALUES (?, ?, ?)
        ");
        $stmt->bind_param("sss", $soccerMatchID, $playerID, $positionID);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    public function deleteSoccerMatch($soccerMatchID) {
      $stmt = $this->conn->prepare
      ("DELETE FROM SoccerMatch
        WHERE SoccerMatchID = ?
      ");

      $stmt->bind_param("i", $soccerMatchID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    /**
     * Fetches all players in a team in a particular season
     * @param String $user_id id of the user
     * @param String $teamID id of the team to retrieve the players for
     * @param String $seasonID id of the season to retrieve the players for
     */
    public function getSeasonTeamPlayers($teamID, $seasonID) {
        $stmt = $this->conn->prepare
        ("SELECT
            p.PlayerID
          , p.FirstName
          , p.SurName
          , p.SurNamePrefix
          , pt.JerseyNumber
          , pt.EffectiveDate
          , pt.ExpiryDate
          FROM PlayerTeam pt
          JOIN Player p ON pt.PlayerID = p.PlayerID
          WHERE pt.TeamID = ?
            AND pt.SeasonID = ?
        ");
        $stmt->bind_param("ii",$teamID, $seasonID);
        $stmt->execute();
        $players = $stmt->get_result();
        $stmt->close();
        return $players;
    }

    public function createTeamPlayer($teamID, $playerID, $seasonID, $effectiveDate, $jerseyNumber) {
      $stmt = $this->conn->prepare
        ("INSERT INTO PlayerTeam (TeamID, PlayerID, SeasonID, EffectiveDate, JerseyNumber)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssss", $teamID, $playerID, $seasonID, $effectiveDate, $jerseyNumber);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            $newPlayerTeamID = $this->conn->insert_id;
            return $newPlayerTeamID;
        } else {
            return NULL;
        }
    }

    public function createPlayer($firstName, $surName, $surNamePrefix, $dateOfBirth, $relationCode, $emailAddress) {
      $stmt = $this->conn->prepare
        ("INSERT INTO Player (FirstName, SurName, SurNamePrefix, DateOfBirth, RelationCode, EmailAddress)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssss", $firstName, $surName, $surNamePrefix, $dateOfBirth, $relationCode, $emailAddress);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            $newPlayerID = $this->conn->insert_id;
            return $newPlayerID;
        } else {
            return NULL;
        }
    }

    /**
     * Fetches the lineup given match id
     * @param String $user_id id of the user
     * @param String $soccerMatchID id of the match to retrieve the lineup for
     */
    public function getSoccerMatchLineup($user_id, $soccerMatchID) {
        $stmt = $this->conn->prepare
        ("SELECT pm.PositionID
          , pm.PlayerID
          , p.FirstName
          , p.SurName
          , p.SurNamePrefix
          , fp.Name as Position
          , fp.Code as PositionCode
          , post.PositionTypeID
          , post.Name as PositionTypeName
          , fp.GridPosition
          FROM PlayerSoccerMatch pm
          JOIN Player p on pm.PlayerID = p.PlayerID
          JOIN SoccerMatch m on pm.SoccerMatchID = m.SoccerMatchID
          LEFT JOIN FormationPosition fp on pm.PositionID = fp.PositionID AND (fp.FormationID = m.FormationID OR fp.FormationID IS NULL)
          LEFT JOIN Position pos on fp.PositionID = pos.PositionID
          LEFT JOIN PositionType post on pos.PositionTypeID = post.PositionTypeID
          WHERE pm.SoccerMatchID = ?
        ");

        $stmt->bind_param("i", $soccerMatchID);
        $stmt->execute();
        $lineup = $stmt->get_result();
        $stmt->close();
        return $lineup;
    }

    /**
     * Fetches the events that are possible in a soccer match
     */
    public function getEvents() {
        $stmt = $this->conn->prepare
        ("SELECT *
          FROM Event e
        ");
        $stmt->execute();
        $events = $stmt->get_result();
        $stmt->close();
        return $events;
    }

    /**
     * Fetches the possible formations
     */
    public function getFormations() {
        $stmt = $this->conn->prepare
        ("SELECT *
          FROM Formation f
        ");
        $stmt->execute();
        $formations = $stmt->get_result();
        $stmt->close();
        return $formations;
    }

    /**
     * Fetches the positions for a particular formation
     */
    public function getFormationPositions($formationID) {
        $stmt = $this->conn->prepare
        ("SELECT fp.*, p.PositionTypeID
          FROM FormationPosition fp
          JOIN Position p on fp.PositionID = p.PositionID
          WHERE FormationID = ? OR FormationID IS NULL
        ");
        $stmt->bind_param("i", $formationID);
        $stmt->execute();
        $formations = $stmt->get_result();
        $stmt->close();
        return $formations;
    }


    /**
     * Fetches the match events for the given match id
     * @param String $user_id id of the user
     * @param String $soccerMatchID id of the match to retrieve data for
     */
    public function getSoccerMatchEvents($user_id, $soccerMatchID) {
        $stmt = $this->conn->prepare
        ("SELECT sme.SoccerMatchEventID
          ,sme.PlayerID
          , p.FirstName
          , p.SurName
          , p.SurNamePrefix
          , sme.EventID
          , sme.Minute
          , e.Name as EventName
          , e.IsPrimaryEvent
          , sme.ReferenceSoccerMatchEventID
          FROM SoccerMatchEvent sme
          JOIN Event e on sme.EventID = e.EventID
          LEFT JOIN Player p on sme.PlayerID = p.PlayerID
          WHERE sme.SoccerMatchID = ?
        ");

        $stmt->bind_param("i", $soccerMatchID);
        $stmt->execute();
        $events = $stmt->get_result();
        $stmt->close();
        return $events;
    }

    public function createSoccerMatchEvent($soccerMatchID, $eventID, $playerID, $minute, $referenceSoccerMatchEventID) {
      $stmt = $this->conn->prepare
        ("INSERT INTO SoccerMatchEvent (SoccerMatchID, EventID, PlayerID, Minute, ReferenceSoccerMatchEventID)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiii", $soccerMatchID, $eventID, $playerID, $minute, $referenceSoccerMatchEventID);
        $result = $stmt->execute();
        $stmt->close();
        if ($result) {
            // row created
            $new_id = $this->conn->insert_id;
            return $new_id;
        } else {
            // failed to create
            return NULL;
        }
    }

    public function deleteSoccerMatchEvent($soccerMatchEventID) {
       $stmt = $this->conn->prepare
      ("DELETE FROM SoccerMatchEvent
        WHERE SoccerMatchEventID = ?
      ");

      $stmt->bind_param("i", $soccerMatchEventID);
      $result = $stmt->execute();
      $stmt->close();
      return $result;
    }

    /**
     * Fetches the stats for the given competition
     * @param String $user_id id of the user
     * @param String $competitionID id of the competition to retrieve data for
     */
    public function getTeamCompetitionStats($user_id, $competitionID, $teamID) {
        $stmt = $this->conn->prepare
        ("SELECT
          pt.PlayerID,
          GetPlayerName(pt.PlayerID) as FullName,
          pl.SurName,
           CAST(SUM(CASE WHEN sme.EventID = 1 THEN 1 ELSE 0 END) AS UNSIGNED) AS Goals,
           CAST(SUM(CASE WHEN sme.EventID = 5 THEN 1 ELSE 0 END) AS UNSIGNED) AS Penalties,
           CAST(SUM(CASE WHEN sme.EventID = 7 THEN 1 ELSE 0 END) AS UNSIGNED) AS Assists,
           CAST(SUM(CASE WHEN sme.EventID = 2 THEN 1 ELSE 0 END) AS UNSIGNED) AS YellowCards,
           CAST(SUM(CASE WHEN sme.EventID = 3 THEN 1 ELSE 0 END) AS UNSIGNED) AS RedCards,
           CAST(IFNULL(ms.Minutes, 0) AS UNSIGNED) AS Minutes,
           CAST(IFNULL(ps.Appearances, 0) AS UNSIGNED) AS Appearances,
           CAST(IFNULL(ps.MatchesOnBench, 0) AS UNSIGNED) AS MatchesOnBench
          FROM PlayerTeam pt
          JOIN Player pl on pt.PlayerID = pl.PlayerID
          LEFT JOIN Competition c on pt.SeasonID = c.SeasonID
          LEFT JOIN CompetitionRound cr on cr.CompetitionID = c.CompetitionID
          LEFT JOIN SoccerMatch sm on cr.CompetitionRoundID = sm.CompetitionRoundID
          LEFT JOIN SoccerMatchEvent sme on pt.PlayerID = sme.PlayerID and sm.SoccerMatchID = sme.SoccerMatchID
          LEFT JOIN (SELECT
              pt.PlayerID,
              SUM( CASE
              -- Basisspeler
            WHEN p.PositionTypeID IN (1,2)
              THEN 90 -
              CASE
                WHEN sme.EventID = 4
                THEN CASE
                    WHEN sme.ReferenceSoccerMatchEventID IS NOT NULL
                    THEN sme.Minute
                    ELSE 90 - sme.Minute
                    END
                 ELSE 0
               END
              -- Niet gespeeld
              ELSE 0
            END) AS Minutes
          FROM
              PlayerTeam pt
          LEFT JOIN PlayerSoccerMatch psm on pt.PlayerID = psm.PlayerID -- and psm.SoccerMatchID = 298
          LEFT JOIN SoccerMatch sm on psm.SoccerMatchID = sm.SoccerMatchID
          LEFT JOIN Position p on psm.PositionID = p.PositionID
          LEFT JOIN SoccerMatchEvent sme on psm.PlayerID = sme.PlayerID and sme.SoccerMatchID = psm.SoccerMatchID and sme.EventID = 4
          LEFT JOIN CompetitionRound cr on sm.CompetitionRoundID = cr.CompetitionRoundID
          LEFT JOIN Competition c on cr.CompetitionID = c.CompetitionID
          WHERE pt.teamid = ? and cr.CompetitionID = ? and c.SeasonID = pt.SeasonID
          group by pt.PlayerID) ms on pt.PlayerID = ms.PlayerID
          LEFT JOIN (SELECT c.CompetitionID,
                            psm.PlayerID,
                            SUM(CASE WHEN p.PositionTypeID IN (1,2) THEN 1 ELSE 0 END) AS Appearances,
                            SUM(CASE WHEN p.PositionTypeID = 2 THEN 1 ELSE 0 END) AS MatchesOnBench
                            FROM PlayerSoccerMatch psm
                            JOIN SoccerMatch sm on psm.SoccerMatchID = sm.SoccerMatchID
                            JOIN CompetitionRound cr on sm.CompetitionRoundID = cr.CompetitionRoundID
                            JOIN Competition c on cr.CompetitionID = c.CompetitionID
                            JOIN Position p on psm.PositionID = p.PositionID
                            GROUP BY c.CompetitionID, psm.PlayerID ) ps on pt.PlayerID = ps.PlayerID and c.CompetitionID = ps.CompetitionID
          where pt.TeamID = ? and c.CompetitionID = ?
          group by pt.PlayerID
        ");

        $stmt->bind_param("iiii", $teamID, $competitionID, $teamID, $competitionID);
        $stmt->execute();
        $stats = $stmt->get_result();
        $stmt->close();
        return $stats;
    }

    /**
     * Fetches the next scheduled soccer match for the given team
     * @param String $teamID id of the team to retrieve data for
     */
    public function getTeamNextSoccerMatch($teamID) {
        $stmt = $this->conn->prepare
        ("SELECT
          CASE WHEN sm.HomeTeamID = ? THEN th.Name ELSE ta.Name END AS TeamName
          , CASE WHEN sm.AwayTeamID = ? THEN th.Name ELSE ta.Name END AS OpponentName
          , CASE WHEN sm.AwayTeamID = ? THEN th.TeamLogoFile ELSE ta.TeamLogoFile END AS OpponentLogoFile
          , CASE WHEN sm.HomeTeamID = ? THEN 1 ELSE 0 END AS IsHomeMatch
          , CASE WHEN sm.FallbackDateTime IS NOT NULL THEN sm.FallbackDateTime ELSE ADDTIME(md.Date,ct.DefaultStartTime) END AS ActualPlayDateTime
          , c.Name AS Competition
          FROM
              SoccerMatch sm
              JOIN CompetitionRound cr ON sm.CompetitionRoundID = cr.CompetitionRoundID
              JOIN Competition c ON cr.CompetitionID = c.CompetitionID
              JOIN Matchday md on cr.MatchdayID = md.MatchdayID
              JOIN CompetitionTeam ct on sm.HomeTeamID = ct.TeamID and ct.CompetitionID = cr.CompetitionID
              JOIN Team th on sm.HomeTeamID = th.TeamID
              JOIN Team ta on sm.AwayTeamID = ta.TeamID
          WHERE
            HomeGoals IS NULL AND
              AwayGoals IS NULL AND(md.Date >= CAST(NOW() AS DATE) OR sm.FallbackDateTime >= CAST(NOW() AS DATE)) AND
              (HomeTeamID = ? OR AwayTeamID = ?)
          ORDER BY ActualPlayDateTime
          LIMIT 1
        ");

        $stmt->bind_param("iiiiii", $teamID, $teamID, $teamID, $teamID, $teamID, $teamID);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    /**
     * Fetches the last result for the given team
     * @param String $teamID id of the team to retrieve data for
     */
    public function getTeamLastResult($teamID) {
        $stmt = $this->conn->prepare
        ("SELECT
          CASE WHEN sm.HomeTeamID = ? THEN th.Name ELSE ta.Name END AS TeamName
          , CASE WHEN sm.HomeTeamID = ? THEN sm.HomeGoals ELSE sm.AwayGoals END AS TeamGoals
          , CASE WHEN sm.AwayTeamID = ? THEN th.Name ELSE ta.Name END AS OpponentName
          , CASE WHEN sm.AwayTeamID = ? THEN sm.HomeGoals ELSE sm.AwayGoals END AS OpponentGoals
          , CASE WHEN sm.AwayTeamID = ? THEN th.TeamLogoFile ELSE ta.TeamLogoFile END AS OpponentLogoFile
          , CASE WHEN sm.HomeTeamID = ? THEN 1 ELSE 0 END AS IsHomeMatch
          , CASE WHEN sm.FallbackDateTime IS NOT NULL THEN sm.FallbackDateTime ELSE ADDTIME(md.Date,ct.DefaultStartTime) END AS ActualPlayDateTime
          , c.Name AS Competition
          FROM
              SoccerMatch sm
              JOIN CompetitionRound cr ON sm.CompetitionRoundID = cr.CompetitionRoundID
              JOIN Competition c ON cr.CompetitionID = c.CompetitionID
              JOIN Matchday md on cr.MatchdayID = md.MatchdayID
              JOIN CompetitionTeam ct on sm.HomeTeamID = ct.TeamID and ct.CompetitionID = cr.CompetitionID
              JOIN Team th on sm.HomeTeamID = th.TeamID
              JOIN Team ta on sm.AwayTeamID = ta.TeamID
          WHERE
            HomeGoals IS NOT NULL AND
              AwayGoals IS NOT NULL AND
              (HomeTeamID = ? OR AwayTeamID = ?)
          ORDER BY ActualPlayDateTime DESC
          LIMIT 1
        ");

        $stmt->bind_param("iiiiiiii", $teamID, $teamID, $teamID, $teamID, $teamID, $teamID, $teamID, $teamID);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    /**
     * Adds the given match
     * @param String $user_id id of the user
     * @param String $matchdayID the id of the matchday
     * @param String $homeTeamID the id of the home team
     * @param String $awayTeamID the id of the away team
     */
    public function createMatch($user_id, $matchdayID, $homeTeamID, $awayTeamID) {
        $stmt = $this->conn->prepare
        ("INSERT INTO SoccerMatch (MatchdayID, HomeTeamID, AwayTeamID)
          VALUES = (?, ?, ?)
        ");
        $stmt->bind_param("iii", $matchdayID, $homeTeamID, $awayTeamID);
        $stmt->execute();
        $tasks = $stmt->get_result();
        $stmt->close();
        if ($result) {
            // match row created
            $new_match_id = $this->conn->insert_id;
            return $new_match_id;
        } else {
            // match failed to create
            return NULL;
        }
    }

    // /* ------------- `tasks` table method ------------------ */

    // /**
    //  * Creating new task
    //  * @param String $user_id user id to whom task belongs to
    //  * @param String $task task text
    //  */
    // public function createTask($user_id, $task) {
    //     $stmt = $this->conn->prepare("INSERT INTO tasks(task) VALUES(?)");
    //     $stmt->bind_param("s", $task);
    //     $result = $stmt->execute();
    //     $stmt->close();

    //     if ($result) {
    //         // task row created
    //         // now assign the task to user
    //         $new_task_id = $this->conn->insert_id;
    //         $res = $this->createUserTask($user_id, $new_task_id);
    //         if ($res) {
    //             // task created successfully
    //             return $new_task_id;
    //         } else {
    //             // task failed to create
    //             return NULL;
    //         }
    //     } else {
    //         // task failed to create
    //         return NULL;
    //     }
    // }

    // /**
    //  * Fetching single task
    //  * @param String $task_id id of the task
    //  */
    // public function getTask($task_id, $user_id) {
    //     $stmt = $this->conn->prepare("SELECT t.id, t.task, t.status, t.created_at from tasks t, user_tasks ut WHERE t.id = ? AND ut.task_id = t.id AND ut.user_id = ?");
    //     $stmt->bind_param("ii", $task_id, $user_id);
    //     if ($stmt->execute()) {
    //         $task = $stmt->get_result()->fetch_assoc();
    //         $stmt->close();
    //         return $task;
    //     } else {
    //         return NULL;
    //     }
    // }

    // /**
    //  * Fetching all user tasks
    //  * @param String $user_id id of the user
    //  */
    // public function getAllUserTasks($user_id) {
    //     $stmt = $this->conn->prepare("SELECT t.* FROM tasks t, user_tasks ut WHERE t.id = ut.task_id AND ut.user_id = ?");
    //     $stmt->bind_param("i", $user_id);
    //     $stmt->execute();
    //     $tasks = $stmt->get_result();
    //     $stmt->close();
    //     return $tasks;
    // }

    // /**
    //  * Updating task
    //  * @param String $task_id id of the task
    //  * @param String $task task text
    //  * @param String $status task status
    //  */
    // public function updateTask($user_id, $task_id, $task, $status) {
    //     $stmt = $this->conn->prepare("UPDATE tasks t, user_tasks ut set t.task = ?, t.status = ? WHERE t.id = ? AND t.id = ut.task_id AND ut.user_id = ?");
    //     $stmt->bind_param("siii", $task, $status, $task_id, $user_id);
    //     $stmt->execute();
    //     $num_affected_rows = $stmt->affected_rows;
    //     $stmt->close();
    //     return $num_affected_rows > 0;
    // }

    // /**
    //  * Deleting a task
    //  * @param String $task_id id of the task to delete
    //  */
    // public function deleteTask($user_id, $task_id) {
    //     $stmt = $this->conn->prepare("DELETE t FROM tasks t, user_tasks ut WHERE t.id = ? AND ut.task_id = t.id AND ut.user_id = ?");
    //     $stmt->bind_param("ii", $task_id, $user_id);
    //     $stmt->execute();
    //     $num_affected_rows = $stmt->affected_rows;
    //     $stmt->close();
    //     return $num_affected_rows > 0;
    // }

    // /* ------------- `user_tasks` table method ------------------ */

    // /**
    //  * Function to assign a task to user
    //  * @param String $user_id id of the user
    //  * @param String $task_id id of the task
    //  */
    // public function createUserTask($user_id, $task_id) {
    //     $stmt = $this->conn->prepare("INSERT INTO user_tasks(user_id, task_id) values(?, ?)");
    //     $stmt->bind_param("ii", $user_id, $task_id);
    //     $result = $stmt->execute();
    //     $stmt->close();
    //     return $result;
    // }

}

?>
