<?php
require_once(__DIR__.'/SpecialK/Evaluate/SevenEval.php');
require_once(__DIR__.'/SpecialK/Card.php');


class HoldEmAPi{
    function __construct($hands, $shared, $num, $exact = false, $iters = 1) {
        //SMDEBUG($num, __LINE__);
        $this->players = $num;
        if(strlen($hands) == 4){
            $hole_cards = str_split($hands, 2);
            asort($hole_cards);
            $hole_cards = implode($hole_cards);
            //SMDEBUG($hole_cards, __LINE__);
            $board_cards = '';
            if(!empty($shared)){
                $board_cards = str_split($shared, 2);
                asort($board_cards);
                $board_cards = implode($board_cards);
            }
            //SMDEBUG($board_cards, __LINE__);
            $this->db_key = $hole_cards.$board_cards;
            $record = $this->checkDB();
            if($exact || empty($record)){
                $this->evaluator = new \SpecialK\Evaluate\SevenEval();
                $this->iterations = $iters;
                if(strlen($hands) % 4 != 0){
                    throw new Exception("Hands must be multiples of 2 cards");
                }
                $hole = str_split($hands, 2);
                asort($hole);
                $this->hole = [];
                $this->hole[] = new \SpecialK\Card($hole[0]);
                $this->hole[] = new \SpecialK\Card($hole[1]);
                //SMDEBUG($this->hole, __LINE__);
                if(!empty($shared)){
                    $community = str_split($shared, 2);
                    asort($community);
                    //SMDEBUG($community, __LINE__);
                    $this->community = [];
                    foreach($community as $c){
                        $this->community[] = new \SpecialK\Card($c);
                    }
                }
                //SMDEBUG($this->community, __LINE__);
                $values = "AKQJT98765432";
                $suits = "sdch";
                $this->deck = [];
                $split_values = str_split($values);
                $split_suit = str_split($suits);
                foreach ($split_values as $v){
                    foreach ($split_suit as $s){
                        if(!in_array_r($v.$s,$this->hole) && (empty($this->community) || !in_array($v.$s,$this->community)))
                            $this->deck[] = new \SpecialK\Card($v.$s);
                    }
                }
                $this->getOdds();
            }else{
                $hand = $record['hand'];
                $odds = $record['odds'];
                $this->results = ['cards'=>substr($hand, 0, 4),'board'=>substr($hand, 4), 'odds'=>$odds];
            }
        }else if(!empty(strlen($hands)) && strlen($hands) % 4 == 0 && strlen($shared) == 10){
            $this->getWinner($hands, $shared);
        }else{
            throw new Exception("Invalid cards parameter");
        }
    }

    function getWinner($cards, $board){
        $evaluator = new \SpecialK\Evaluate\SevenEval();
        $board_cards = [];
        foreach(str_split($board, 2) as $b){
            $board_cards[] = new \SpecialK\Card($b);
        }
        $hands = array_chunk(str_split($cards, 2), 2);
        $complete_hands = [];
        foreach($hands as $hand){
            $player = [];
            foreach($hand as $card){
                 $player[] = new \SpecialK\Card($card);
            }
            $complete_hands[] = array_merge($player, $board_cards);
        }
        $winners = [];
        $max_score = 0;
        foreach($complete_hands as $hands){
            $score = $evaluator->evaluate($hands);
            if($max_score < $score){
                $winners = [$hands];
                $max_score = $score;
            }else if($max_score == $score){
                $winners[] = $hands;
            }
        }
        $winner_string = "";
        foreach($winners as $winner){
            $winner_string .= implode($winner);
        }
        $this->results = ['cards'=>$winner_string,'board'=>$board, 'odds'=>1.0];
    }

    function getResults(){
        if(!empty($this->results)){
            $this->results['odds'] = $this->results['odds'] / log((float)$this->players, 2.0);
            return $this->results;
        }else
            throw new Exception("There was an error. No results. PLease send the request url to the admin. ");
    }

    function checkDB(){
        $this->dbhandle = new SQLite3('pokerodds.db');
        if(!$this->dbhandle){
            throw new Exception("Database Error");
        }
        $this->dbhandle->query("CREATE TABLE IF NOT EXISTS hands (hand text NOT NULL, odds real, PRIMARY KEY(hand))");
        //SMDEBUG($this->db_key, __LINE__);
        $dbresults = $this->dbhandle->query("SELECT * FROM hands WHERE hand='{$this->db_key}' LIMIT 1");
        $record = $dbresults->fetchArray(SQLITE3_ASSOC);
        //SMDEBUG($record, __LINE__);
        return $record;
    }

    function printDeck(){
        foreach ($this->deck as $c){
            echo $c;
        }
    }

    function getOdds(){
        if(empty($this->results)){
            $num_runs = 0;
            $accum = 0;
            foreach ($this->deck as $c1){
                foreach($this->deck as $c2){
                    if(!$c1->equal($c2)){
                        $accum += $this->psim($c1, $c2);
                        $num_runs += 1;
                    }
                }
            }
            $odds = ($accum/$num_runs); //TODO play around with this
            $this->results = ['cards'=>implode($this->hole),'board'=>(empty($this->community))?"":implode($this->community), 'odds'=>$odds];
            $this->insertIntoDatabase($this->results['cards'],$this->results['board'], $this->results['odds']);
        }
        return $this->results;
    }

    function insertIntoDatabase($cards, $board, $odds){
        $query = "INSERT OR REPLACE into hands (hand, odds) VALUES ('{$this->db_key}',{$odds})";
        //SMDEBUG($query, __LINE__);
        if(!$this->dbhandle->exec($query)){
            throw new Exception("Database Error: Please contact Admin with a screenshot.");
        }
    }

    function psim($c1, $c2){
        # Run simulations
        $accum = 0;
        if(5 - count($this->community) > 0){
            foreach($this->generate_boards() as $board){
                //SMDEBUG([$c1, $c2], __LINE__);
                //SMDEBUG($this->hole, __LINE__);
                //SMDEBUG($board, __LINE__);
                $result = $this->evaluator->compare(array_merge($this->hole, $board), array_merge([$c1, $c2], $board));
                if($GLOBALS['SMDEBUG_API'] && $result <= 0){
                    $resultstr = implode(" ", array_map(function($c){return strval($c);}, array_merge([$c1, $c2], $board)));
                    $resultstr .= " Beats or ties by {$result} against ->";
                    $resultstr .= implode(" ", array_map(function($c){return strval($c);}, array_merge($this->hole, $board)));
                    SMDEBUG($resultstr,__LINE__);
                    die();
                }
                $accum += ($result > 0) ? 1 : 0;
            }
            //SMDEBUG($accum, __LINE__);
            return $accum / $this->iterations;
        }else{
            $result = $this->evaluator->compare(array_merge([$c1, $c2], $this->community), array_merge($this->hole, $this->community));
            return ($result > 0) ? 1 : 0;
        }
    }


    function generate_boards(){
        $boards = [];
        foreach(range(0, $this->iterations-1) as $i){
            $rand_cards = (5 - count($this->community) == 1 ) ? [array_rand($this->deck)]: array_rand($this->deck, 5 - count($this->community));
            //SMDEBUG($rand_cards, __LINE__);
            foreach($rand_cards as $card){
                $boards[$i][] = $this->deck[$card];
            }
            if (!empty($this->community)) $boards[$i] = array_merge($boards[$i],$this->community);
            //SMDEBUG($boards[$i], __LINE__);
        }
        return $boards;
    }
}

function in_array_r($needle, $haystack, $strict = false) {
    foreach ($haystack as $item) {
        if (($strict ? $item === $needle : $item == $needle) ||
                (is_array($item) && in_array_r($needle, $item, $strict))) {
            return true;
        }
    }
    return false;
}

function SMDEBUG($value, $line){
    if($GLOBALS['SMDEBUG_API']){
         echo basename(__FILE__, '.php')." : ".$line." -- ";
         var_dump($value);
         echo '<br/>=====<br/>';
    }
}
