<?php
header('Content-Type: application/json; charset=utf8');
include_once('holdemapi.php');

//2-8 players
$num_players = isset($_REQUEST['num_players']) ? max(2, min(8, (int)$_REQUEST['num_players'])) : 2;
//shared cards
$shared = isset($_REQUEST['board']) ? $_REQUEST['board'] : '';
//players hand
$hand = isset($_REQUEST['cards']) ? $_REQUEST['cards'] : '';
//players hand
$exact = isset($_REQUEST['exact']) ? $_REQUEST['exact'] : false;
$SMDEBUG_API = isset($_REQUEST['debug']) ? $_REQUEST['debug'] : false;
try{
    $game = new HoldEmAPI($hand, $shared, $num_players, $exact, 300);
    echo json_encode($game->getResults());
}catch (Exception $e){
    echo json_encode(['error'=>$e->getMessage()]);
}
