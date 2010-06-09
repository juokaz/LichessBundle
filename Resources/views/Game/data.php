<?php
$game = $player->getGame();
$playerFullHash = $player->getFullHash();
$data = array(
    'game' => array(
        'hash' => $game->getHash(),
        'started' => $game->getIsStarted(),
        'finished' => $game->getIsFinished(),
        'turns' => $game->getTurns(),
        'updatedAt' => $game->getUpdatedAt()
    ),
    'player' => array(
        'hash' => $player->getHash(),
        'fullHash' => $playerFullHash,
        'color' => $player->getColor()
    ),
    'opponent' => array(
        'color' => $player->getOpponent()->getColor()
    ),
    'beat' => array(
        'delay' => 2000,
    ),
    'url' => array(
        'beat' => $view->router->generate('lichess_beat', array('hash' => $playerFullHash)),
        'beatCache' => '/bundle/lichess/cache/'.$playerFullHash,
        'wait' => $view->router->generate('lichess_wait', array('hash' => $playerFullHash, 'updatedAt' => $game->getUpdatedAt())),
    ),
    'i18n' => array(
        'Game Over' => 'Game Over',
        'Waiting for opponent' => 'Waiting for opponent',
        'Your turn' => 'Your turn'
    ),
    'possible_moves' => $possibleMoves
);
?>
<script type="text/javascript">var lichess_data = <?php echo json_encode($data) ?>;</script>
