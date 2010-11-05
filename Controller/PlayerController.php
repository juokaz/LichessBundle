<?php

namespace Bundle\LichessBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Bundle\LichessBundle\Chess\Analyser;
use Bundle\LichessBundle\Chess\Manipulator;
use Bundle\LichessBundle\Document\Stack;
use Bundle\LichessBundle\Document\Player;
use Bundle\LichessBundle\Document\Game;
use Bundle\LichessBundle\Form;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlayerController extends Controller
{
    public function outoftimeAction($id, $version)
    {
        $player = $this->findPlayer($id);
        $opponent = $player->getOpponent();
        $game = $player->getGame();

        if($game->checkOutOfTime()) {
            $events = array(array('type' => 'end'), array('type' => 'possible_moves', 'possible_moves' => null));
            $player->getStack()->addEvents($events);
            $opponent->getStack()->addEvents($events);
            $this['lichess.object_manager']->flush();
            $this['logger']->notice(sprintf('Player:outoftime game:%s', $game->getId()));
        }

        $this['logger']->warn(sprintf('Player:outoftime finished game:%s', $game->getId()));
        return $this->renderJson($this->getPlayerSyncData($player, $version));
    }

    public function rematchAction($id)
    {
        $player = $this->findPlayer($id);
        $opponent = $player->getOpponent();
        $game = $player->getGame();

        if(!$game->getIsFinished()) {
            $this['logger']->warn(sprintf('Player:rematch not finished game:%s', $game->getId()));
            return $this->redirect($this->generateUrl('lichess_player', array('id' => $player->getFullId())));
        }

        if($nextPlayerId = $game->getNext()) {
            $nextOpponent = $this->findPlayer($nextPlayerId);
            if($nextOpponent->getColor() === $player->getColor()) {
                $nextGame = $nextOpponent->getGame();
                $nextPlayer = $nextOpponent->getOpponent();
                if(!$nextGame->getIsStarted()) {
                    $nextGame->setRoom(clone $game->getRoom());
                    if($game->hasClock()) {
                        $nextGame->setClock(clone $game->getClock());
                    }
                    $nextGame->start();
                    $opponent->getStack()->addEvent(array('type' => 'redirect', 'url' => $this->generateUrl('lichess_player', array('id' => $nextOpponent->getFullId()))));
                    $this['lichess.object_manager']->flush();
                    if($this['lichess_synchronizer']->isConnected($opponent)) {
                        $this['lichess_synchronizer']->setAlive($nextOpponent);
                    }
                    $this['logger']->notice(sprintf('Player:rematch join game:%s', $nextGame->getId()));
                }
                else {
                    $this['logger']->warn(sprintf('Player:rematch join already started game:%s', $nextGame->getId()));
                }
                return $this->redirect($this->generateUrl('lichess_player', array('id' => $nextPlayer->getFullId())));
            }
        }
        else {
            $nextPlayer = $this->container->getLichessGeneratorService()->createReturnGame($player);
            $this['lichess.object_manager']->persist($nextPlayer->getGame());
            $opponent->getStack()->addEvent(array('type' => 'reload_table'));
            $this['lichess_synchronizer']->setAlive($player);
            $this['logger']->notice(sprintf('Player:rematch proposal for game:%s', $game->getId()));
            $this['lichess.object_manager']->flush();
        }

        return $this->redirect($this->generateUrl('lichess_player', array('id' => $player->getFullId())));
    }

    public function syncAction($id, $color, $version, $playerFullId)
    {
        $player = $this->findPublicPlayer($id, $color);
        if($playerFullId) {
            $this['lichess_synchronizer']->setAlive($player);
        }
        $player->getGame()->cachePlayerVersions();
        $data = $this->getPlayerSyncData($player, $version);
        // remove private events if user is spectator
        if(!$playerFullId) {
            foreach($data['e'] as $index => $event) {
                if('message' === $event['type'] || 'redirect' === $event['type']) {
                    unset($data['e'][$index]);
                }
            }
        }

        return $this->renderJson($data);
    }

    protected function getPlayerSyncData($player, $clientVersion)
    {
        $game = $player->getGame();
        $version = $player->getStack()->getVersion();
        $isOpponentConnected = $this['lichess_synchronizer']->isConnected($player->getOpponent());
        $currentPlayerColor = $game->getTurnColor();
        try {
            $events = $version != $clientVersion ? $this['lichess_synchronizer']->getDiffEvents($player, $clientVersion) : array();
        }
        catch(\OutOfBoundsException $e) {
            $events = array(array('type' => 'redirect', 'url' => $this->generateUrl('lichess_player', array('id' => $player->getFullId()))));
        }

        $data = array('v' => $version, 'o' => $isOpponentConnected, 'e' => $events, 'p' => $currentPlayerColor, 't' => $game->getTurns());
        $data['ncp'] = $this['lichess_synchronizer']->getNbConnectedPlayers();
        if($game->hasClock()) {
            $data['c'] = $game->getClock()->getRemainingTimes();
        }

        return $data;
    }

    public function forceResignAction($id)
    {
        $player = $this->findPlayer($id);
        $game = $player->getGame();
        if(!$game->getIsFinished() && $this['lichess_synchronizer']->isTimeout($player->getOpponent())) {
            $game->setStatus(Game::TIMEOUT);
            $player->setIsWinner(true);
            $player->getStack()->addEvent(array('type' => 'end'));
            $player->getOpponent()->getStack()->addEvent(array('type' => 'end'));
            $this['lichess.object_manager']->flush();
            $this['logger']->notice(sprintf('Player:forceResign game:%s', $game->getId()));
        }
        else {
            $this['logger']->warn(sprintf('Player:forceResign FAIL game:%s', $game->getId()));
        }

        return $this->redirect($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function claimDrawAction($id)
    {
        $player = $this->findPlayer($id);
        $game = $player->getGame();
        if(!$game->getIsFinished() && $game->isThreefoldRepetition() && $player->isMyTurn()) {
            $game->setStatus(GAME::DRAW);
            $player->getStack()->addEvent(array('type' => 'end'));
            $player->getOpponent()->getStack()->addEvent(array('type' => 'end'));
            $this['lichess.object_manager']->flush();
            $this['logger']->notice(sprintf('Player:claimDraw game:%s', $game->getId()));
        }
        else {
            $this['logger']->warn(sprintf('Player:claimDraw FAIL game:%s', $game->getId()));
        }

        return $this->redirect($this->generateUrl('lichess_player', array('id' => $id)));
    }

    protected function renderJson($data)
    {
        $response = $this->createResponse(json_encode($data));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    public function moveAction($id, $version)
    {
        $player = $this->findPlayer($id);
        $this['lichess_synchronizer']->setAlive($player);
        $game = $player->getGame();
        if(!$player->isMyTurn()) {
            throw new \LogicException(sprintf('Player:move not my turn game:%s', $game->getId()));
        }
        $opponent = $player->getOpponent();
        $postData = $this['request']->request;
        $move = $postData->get('from').' '.$postData->get('to');
        $stack = new Stack();
        $manipulator = new Manipulator($game, $stack);
        $opponentPossibleMoves = $manipulator->play($move, $postData->get('options', array()));
        $player->getStack()->addEvents($stack->getEvents());
        $player->getStack()->addEvent(array('type' => 'possible_moves', 'possible_moves' => null));
        $response = $this->renderJson($this->getPlayerSyncData($player, $version));

        if($opponent->getIsAi()) {
            if(!empty($opponentPossibleMoves)) {
                $stack->reset();
                $ai = $this['lichess_ai'];
                try {
                    $possibleMoves = $manipulator->play($ai->move($game, $opponent->getAiLevel()));
                }
                catch(\Exception $e) {
                    $this['logger']->err(sprintf('Player:move Crafty game:%s, variant:%s, turn:%d - %s %s', $game->getId(), $game->getVariantName(), $game->getTurns(), get_class($e), $e->getMessage()));
                    $ai = $this['lichess_ai_fallback'];
                    $possibleMoves = $manipulator->play($ai->move($game, $opponent->getAiLevel()));
                }
                $player->getStack()->addEvents($stack->getEvents());
                $player->getStack()->addEvent(array('type' => 'possible_moves', 'possible_moves' => $possibleMoves));
            }
        }
        else {
            $opponent->getStack()->addEvents($stack->getEvents());
            $opponent->getStack()->addEvent(array('type' => 'possible_moves', 'possible_moves' => $opponentPossibleMoves));
        }
        $this['lichess.object_manager']->flush();
        if($game->getIsFinished()) {
            $this['logger']->notice(sprintf('Player:move finish game:%s, %s', $game->getId(), $game->getStatusMessage()));
        }

        return $response;
    }

    public function showAction($id)
    {
        $player = $this->findPlayer($id);
        $game = $player->getGame();

        $this['lichess_synchronizer']->setAlive($player);

        if(!$game->getIsStarted()) {
            throw new HttpException(sprintf('Player:show game:%s, Game not started', $game->getId()), 410);
        }

        $analyser = new Analyser($game->getBoard());
        $isKingAttacked = $analyser->isKingAttacked($game->getTurnPlayer());
        if($isKingAttacked) {
            $checkSquareKey = $game->getTurnPlayer()->getKing()->getSquareKey();
        }
        else {
            $checkSquareKey = null;
        }
        return $this->render('LichessBundle:Player:show.php', array(
            'player' => $player,
            'isOpponentConnected' => $this['lichess_synchronizer']->isConnected($player->getOpponent()),
            'checkSquareKey' => $checkSquareKey,
            'parameters' => $this->container->getParameterBag()->all(),
            'possibleMoves' => ($player->isMyTurn() && !$game->getIsFinished()) ? $analyser->getPlayerPossibleMoves($player, $isKingAttacked) : null
        ));
    }

    /**
     * Add a message to the chat room
     */
    public function sayAction($id, $version)
    {
        if('POST' !== $this['request']->getMethod()) {
            throw new NotFoundHttpException(sprintf('Player:say game:%s, POST method required', $id));
        }
        $message = trim($this['request']->get('message'));
        if('' === $message) {
            throw new NotFoundHttpException(sprintf('Player:say game:%s, No message', $id));
        }
        $message = substr($message, 0, 140);
        $player = $this->findPlayer($id);
        $this['lichess_synchronizer']->setAlive($player);
        $player->getGame()->getRoom()->addMessage($player->getColor(), $message);
        $htmlMessage = \Bundle\LichessBundle\Helper\TextHelper::autoLink(htmlentities($message, ENT_COMPAT, 'UTF-8'));
        $sayEvent = array(
            'type' => 'message',
            'html' => sprintf('<li class="%s">%s</li>', $player->getColor(), $htmlMessage)
        );
        $player->getStack()->addEvent($sayEvent);
        $player->getOpponent()->getStack()->addEvent($sayEvent);
        $this['lichess.object_manager']->flush();

        return $this->renderJson($this->getPlayerSyncData($player, $version));
    }

    public function waitAnybodyAction($id)
    {
        try {
            $player = $this->findPlayer($id);
        }
        catch(NotFoundHttpException $e) {
            return $this->redirect($this->generateUrl('lichess_invite_anybody'));
        }
        if($player->getGame()->getIsStarted()) {
            return $this->redirect($this->generateUrl('lichess_player', array('id' => $id)));
        }
        $this['lichess_synchronizer']->setAlive($player);

        $config = new Form\AnybodyGameConfig();
        $config->fromArray($this['session']->get('lichess.game_config.anybody', array()));
        return $this->render('LichessBundle:Player:waitAnybody.php', array(
            'player'     => $player,
            'parameters' => $this->container->getParameterBag()->all(),
            'config'     => $config
        ));
    }

    public function waitFriendAction($id)
    {
        $player = $this->findPlayer($id);
        if($player->getGame()->getIsStarted()) {
            return $this->redirect($this->generateUrl('lichess_player', array('id' => $id)));
        }
        $this['lichess_synchronizer']->setAlive($player);

        return $this->render('LichessBundle:Player:waitFriend.php', array(
            'player'     => $player,
            'parameters' => $this->container->getParameterBag()->all()
        ));
    }

    public function resignAction($id)
    {
        $player = $this->findPlayer($id);
        $game = $player->getGame();
        if($game->getIsFinished()) {
            $this['logger']->warn(sprintf('Player:resign finished game:%s', $game->getId()));
            return $this->redirect($this->generateUrl('lichess_player', array('id' => $id)));
        }
        $opponent = $player->getOpponent();

        $game->setStatus(Game::RESIGN);
        $opponent->setIsWinner(true);
        $player->getStack()->addEvent(array('type' => 'end'));
        $opponent->getStack()->addEvent(array('type' => 'end'));
        $this['lichess.object_manager']->flush();
        $this['logger']->notice(sprintf('Player:resign game:%s', $game->getId()));

        return $this->redirect($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function aiLevelAction($id)
    {
        $player = $this->findPlayer($id);
        $level = min(8, max(1, (int)$this['request']->get('level')));
        $player->getOpponent()->setAiLevel($level);
        $this['lichess.object_manager']->flush();

        return $this->createResponse('done');
    }

    public function tableAction($id, $color, $playerFullId)
    {
        if($playerFullId) {
            $player = $this->findPlayer($playerFullId);
            $template = $player->getGame()->getIsFinished() ? 'tableEnd' : 'table';
            if($nextPlayerId = $player->getGame()->getNext()) {
                $nextGame = $this->findPlayer($nextPlayerId)->getGame();
            }
            else {
                $nextGame = null;
            }
        }
        else {
            $player = $this->findPublicPlayer($id, $color);
            $template = 'watchTable';
            $nextGame = null;
        }
        return $this->render('LichessBundle:Game:'.$template.'.php', array(
            'player'              => $player,
            'isOpponentConnected' => $this['lichess_synchronizer']->isConnected($player->getOpponent()),
            'nextGame'            => $nextGame
        ));
    }

    public function opponentAction($id, $color, $playerFullId)
    {
        if($playerFullId) {
            $player = $this->findPlayer($playerFullId);
            $template = 'opponent';
        }
        else {
            $player = $this->findPublicPlayer($id, $color);
            $template = 'watchOpponent';
        }
        return $this->render('LichessBundle:Player:'.$template.'.php', array(
            'player'              => $player,
            'isOpponentConnected' => $this['lichess_synchronizer']->isConnected($player->getOpponent())
        ));
    }

    /**
     * Get the player for this id
     *
     * @param string $id
     * @return Player
     */
    protected function findPlayer($id)
    {
        $gameId = substr($id, 0, 8);
        $playerId = substr($id, 8, 12);

        $game = $this['lichess.model.game.repository']->findOneById($gameId);
        if(!$game) {
            throw new NotFoundHttpException('Player:findPlayer Can\'t find game '.$gameId);
        }

        $player = $game->getPlayerById($playerId);
        if(!$player) {
            throw new NotFoundHttpException('Player:findPlayer Can\'t find player '.$playerId);
        }

        return $player;
    }

    /**
     * Get the public player for this id
     *
     * @param string $id
     * @return Player
     */
    protected function findPublicPlayer($id, $color)
    {
        $game = $this['lichess.repository.game']->findOneById($id);
        if(!$game) {
            throw new NotFoundHttpException('Player:findPublicPlayer Can\'t find game '.$id);
        }

        $player = $game->getPlayer($color);
        if(!$player) {
            throw new NotFoundHttpException('Player:findPublicPlayer Can\'t find player '.$color);
        }

        return $player;
    }
}
