<?php

namespace Bundle\LichessBundle\Model;

use Bundle\LichessBundle\Chess\Board;
use Bundle\LichessBundle\Util\KeyGenerator;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Bundle\DoctrineUserBundle\Model\User;

abstract class Game {

    const CREATED = 10;
    const STARTED = 20;
    const ABORTED = 25;
    const MATE = 30;
    const RESIGN = 31;
    const STALEMATE = 32;
    const TIMEOUT = 33;
    const DRAW = 34;
    const OUTOFTIME = 35;
    const CHEAT = 36;

    const VARIANT_STANDARD = 1;
    const VARIANT_960 = 2;

    protected $id;

    protected $variant;

    protected $status;

    protected $players;

    protected $userIds = array();

    protected $winnerUserId = null;

    protected $creatorColor;

    protected $turns;

    protected $pgnMoves;

    protected $next;

    protected $initialFen;

    protected $updatedAt;

    protected $createdAt;

    protected $positionHashes = array();

    protected $clock;

    protected $room;

    protected $isRated = false;

    protected $board;

    public function __construct($variant = self::VARIANT_STANDARD)
    {
        $this->generateId();
        $this->setVariant($variant ? $variant : self::VARIANT_STANDARD);
        $this->status   = self::CREATED;
        $this->turns    = 0;
        $this->players  = new ArrayCollection();
        $this->pgnMoves = array();
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Generate a new ID - don't use once the game is saved
     *
     * @return null
     **/
    protected function generateId()
    {
        if(null !== $this->id) {
            throw new \LogicException('Can not change the id of a saved game');
        }
        $this->id = KeyGenerator::generate(8);
    }

    public function addUserId($userId)
    {
        if($userId && !in_array((string) $userId, $this->userIds)) {
            $this->userIds[] = (string) $userId;
        }
    }

    public function setWinner(Player $player)
    {
        $player->setIsWinner(true);
        $player->getOpponent()->setIsWinner(false);

        // Denormalization
        if($user = $player->getUser()) {
            $this->winnerUserId = (string) $user->getId();
        }
        else {
            $this->winnerUserId = false;
        }
    }

    /**
     * Fen notation of initial position
     *
     * @return string
     **/
    public function getInitialFen()
    {
        if(null === $this->initialFen) {
            return 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq';
        }

        return $this->initialFen;
    }

    /**
     * Set initialFen
     * @param  string
     * @return null
     */
    public function setInitialFen($fen)
    {
        $this->initialFen = $fen;
    }

    /**
     * Get variant
     * @return int
     */
    public function getVariant()
    {
        return $this->variant;
    }

    /**
     * Set variant
     * @param  int
     * @return null
     */
    public function setVariant($variant)
    {
        if(!array_key_exists($variant, self::getVariantNames())) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid game variant', $variant));
        }
        if($this->getIsStarted()) {
            throw new \LogicException('Can not change variant, game is already started');
        }
        $this->variant = $variant;
    }

    public function isStandardVariant()
    {
        return static::VARIANT_STANDARD === $this->variant;
    }

    public function getVariantName()
    {
        $variants = self::getVariantNames();

        return $variants[$this->getVariant()];
    }

    static public function getVariantNames()
    {
        return array(
            self::VARIANT_STANDARD => 'standard',
            self::VARIANT_960 => 'chess960'
        );
    }

    /**
     * Get clock
     * @return Clock
     */
    public function getClock()
    {
        return $this->clock;
    }

    /**
     * Set clock
     * @param  Clock
     * @return null
     */
    public function setClock(Clock $clock = null)
    {
        if($this->getIsStarted()) {
            throw new \LogicException('Can not add clock, game is already started');
        }

        $this->clock = $clock;
    }

    abstract public function getClockInstance($time, $moveBonus = null);

    public function setClockTime($time)
    {
        $this->setClock($time ? $this->getClockInstance($time) : null);
    }

    /**
     * Tell if the game has a clock
     *
     * @return boolean
     **/
    public function hasClock()
    {
        return null !== $this->clock;
    }

    /**
     * @return bool
     */
    public function getIsRated()
    {
        return (bool) $this->isRated;
    }

    /**
     * @param  bool
     * @return null
     */
    public function setIsRated($isRated)
    {
        if($this->getIsStarted()) {
            throw new \LogicException('Can not change ranking mode, game is already started');
        }
        
        $this->isRated = $isRated ? true : false;
    }

    /**
     * Get the minutes of the clock if any, or 0
     *
     * @return int
     **/
    public function getClockMinutes()
    {
        return $this->hasClock() ? $this->getClock()->getLimitInMinutes() : 0;
    }

    public function getClockName()
    {
        return $this->hasClock() ? $this->getClock()->getName() : 'No clock';
    }

    /**
     * Verify if one of the player exceeded his time limit,
     * and terminate the game in this case
     *
     * @return boolean true if the game has been terminated
     **/
    public function checkOutOfTime()
    {
        if(!$this->hasClock()) {
            throw new \LogicException('This game has no clock');
        }
        if($this->getIsFinishedOrAborted()) {
            return;
        }
        foreach($this->getPlayers() as $player) {
            if($this->getClock()->isOutOfTime($player->getColor())) {
                $this->setStatus(static::OUTOFTIME);
                $this->setWinner($player->getOpponent());
                return true;
            }
        }
    }

    /**
     * Add the current position hash to the stack
     */
    public function addPositionHash()
    {
        $hash = '';
        foreach($this->getPieces() as $piece) {
            $hash .= $piece->getContextualHash();
        }
        $this->positionHashes[] = md5($hash);
    }

    /**
     * Sometime we can safely clear the position hashes,
     * for example when a pawn moved
     *
     * @return void
     */
    public function clearPositionHashes()
    {
        $this->positionHashes = array();
    }

    /**
     * Are we in a threefold repetition state?
     *
     * @return bool
     **/
    public function isThreefoldRepetition()
    {
        if(6 > count($this->positionHashes)) {
            return false;
        }
        $hash = end($this->positionHashes);

        return count(array_keys($this->positionHashes, $hash)) >= 3;
    }

    /**
     * Halfmove clock: This is the number of halfmoves since the last pawn advance or capture.
     * This is used to determine if a draw can be claimed under the fifty-move rule.
     *
     * @return int
     **/
    public function getHalfmoveClock()
    {
        return max(0, count($this->positionHashes) - 1);
    }

    /**
     * Fullmove number: The number of the full move. It starts at 1, and is incremented after Black's move.
     *
     * @return int
     **/
    public function getFullmoveNumber()
    {
        return floor(1+$this->getTurns() / 2);
    }

    /**
     * Return true if the game can not be won anymore
     * and can be declared as draw automatically
     *
     * @return boolean
     **/
    public function isCandidateToAutoDraw()
    {
        if(1 === $this->getPlayer('white')->getNbAlivePieces() && 1 === $this->getPlayer('black')->getNbAlivePieces()) {
            return true;
        }

        return false;
    }

    /**
     * Get pgn moves
     * @return array
     */
    public function getPgnMoves()
    {
        return $this->pgnMoves;
    }

    /**
     * Set pgn moves
     * @param  array
     * @return null
     */
    public function setPgnMoves(array $pgnMoves)
    {
        $this->pgnMoves = $pgnMoves;
    }

    /**
     * Add a pgn move
     *
     * @param string
     * @return null
     **/
    public function addPgnMove($pgnMove)
    {
        $this->pgnMoves[] = $pgnMove;
    }

    /**
     * Get next
     * @return string
     */
    public function getNext()
    {
        return $this->next;
    }

    /**
     * Set next
     * @param  string
     * @return null
     */
    public function setNext($next)
    {
        $this->next = $next;
    }

    /**
     * Get status
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    public function getStatusMessage()
    {
        switch($this->getStatus()) {
            case self::ABORTED: $message   = 'Game aborted'; break;
            case self::MATE: $message      = 'Checkmate'; break;
            case self::RESIGN: $message    = ucfirst($this->getWinner()->getOpponent()->getColor()).' resigned'; break;
            case self::STALEMATE: $message = 'Stalemate'; break;
            case self::TIMEOUT: $message   = ucfirst($this->getWinner()->getOpponent()->getColor()).' left the game'; break;
            case self::DRAW: $message      = 'Draw'; break;
            case self::OUTOFTIME: $message = 'Time out'; break;
            case self::CHEAT: $message     = 'Cheat detected'; break;
            default: $message              = '';
        }
        return $message;
    }

    /**
     * Set status
     * @param  int
     * @return null
     */
    public function setStatus($status)
    {
        if($this->getIsFinishedOrAborted()) {
            return;
        }

        $this->status = $status;

        if($this->getIsFinishedOrAborted() && $this->hasClock()) {
            $this->getClock()->stop();
        }
    }

    /**
     * Start a game
     *
     * @return null
     **/
    public function start()
    {
        // The game can only be ranked if both players are logged in
        if($this->getIsRated() && !($this->getPlayer('white')->getUser() && $this->getPlayer('black')->getUser())) {
            $this->setIsRated(false);
        }
        
        $this->setStatus(static::STARTED);
        $this->addRoomMessage('system', ucfirst($this->getCreator()->getColor()).' creates the game');
        $this->addRoomMessage('system', ucfirst($this->getInvited()->getColor()).' joins the game');

        if($this->getIsRated()) {
            $this->addRoomMessage('system', 'This game is ranked');
        }
    }

    /**
     * Get room
     * @return Room
     */
    public function getRoom()
    {
        return $this->room;
    }

    public function hasRoom()
    {
        return null !== $this->room;
    }

    /**
     * Set room
     * @param  Room
     * @return null
     */
    public function setRoom($room)
    {
        $this->room = $room;
    }

    abstract public function getRoomInstance(array $messages = array());

    public function addRoomMessage($author, $message)
    {
        if(!$this->getInvited()->getIsAi()) {
            if(!$this->hasRoom()) {
                $this->setRoom($this->getRoomInstance());
            }
            $this->getRoom()->addMessage($author, $message);
        }
    }

    /**
     * @return Board
     */
    public function getBoard()
    {
        if(null === $this->board) {
            $this->ensureDependencies();
        }

        return $this->board;
    }

    /**
     * @param Board
     */
    public function setBoard($board)
    {
        $this->board = $board;
    }

    /**
     * @return boolean
     */
    public function getIsFinished()
    {
        return $this->getStatus() >= self::MATE;
    }

    /**
     * @return boolean
     */
    public function getIsStarted()
    {
        return $this->getStatus() >= self::STARTED;
    }

    /**
     * @return boolean
     */
    public function getIsTimeOut()
    {
        return $this->getStatus() === self::TIMEOUT;
    }

    /**
     * Whether this game can be aborted or not
     *
     * @return bool
     **/
    public function getIsAbortable()
    {
        return self::STARTED === $this->getStatus() && 2 > $this->getTurns();
    }

    /**
     * Whether this game can be resigned or not
     *
     * @return bool
     **/
    public function isResignable()
    {
        return $this->getIsPlayable() && !$this->getIsAbortable();
    }

    public function getIsAborted()
    {
        return self::ABORTED === $this->getStatus();
    }

    public function getIsFinishedOrAborted()
    {
        return self::ABORTED <= $this->getStatus();
    }

    public function getIsPlayable()
    {
        return self::ABORTED > $this->getStatus();
    }

    /**
     * @return Collection
     */
    public function getPlayers()
    {
        return $this->players;
    }

    /**
     * @return Player
     */
    public function getPlayer($color)
    {
        foreach($this->getPlayers() as $player) {
            if($color === $player->getColor()) {
                return $player;
            }
        }
    }

    /**
     * @return Player
     */
    public function getPlayerById($id)
    {
        foreach($this->getPlayers() as $player) {
            if($player->getId() === $id) {
                return $player;
            }
        }
    }

    public function getPlayerByUser(User $user = null)
    {
        if(null === $user) {
            return null;
        }
        foreach($this->getPlayers() as $p) {
            if($user->is($p->getUser())) {
                return $p;
            }
        }
    }

    public function getPlayerByUserOrCreator(User $user = null)
    {
        $player = $this->getPlayerByUser($user);
        if(empty($player)) {
            $player = $this->getCreator();
        }

        return $player;
    }

    /**
     * @return Player
     */
    public function getTurnPlayer()
    {
        return $this->getPlayer($this->getTurnColor());
    }

    /**
     * Add an event to both players stack
     *
     * @return null
     **/
    public function addEventToStacks(array $event)
    {
        foreach($this->getPlayers() as $player) {
            $player->addEventToStack($event);
        }
    }

    /**
     * Color who plays
     *
     * @return string
     **/
    public function getTurnColor()
    {
        return $this->turns%2 ? 'black' : 'white';
    }

    /**
     * @return string
     */
    public function getCreatorColor()
    {
        return $this->creatorColor;
    }

    /**
     * @param  string
     * @return null
     */
    public function setCreatorColor($creatorColor)
    {
        $this->creatorColor = $creatorColor;
    }

    /**
     * @return Player
     */
    public function getCreator()
    {
        return $this->getPlayer($this->getCreatorColor());
    }

    /**
     * @return Player
     */
    public function getInvited()
    {
        if($this->getCreator()->isWhite()) {
            return $this->getPlayer('black');
        } elseif($this->getCreator()->isBlack()) {
            return $this->getPlayer('white');
        }
    }

    public function setCreator(Player $player)
    {
        $this->setCreatorColor($player->getColor());
    }

    public function getWinner()
    {
        foreach($this->getPlayers() as $player) {
            if($player->getIsWinner()) {
                return $player;
            }
        }
    }

    public function addPlayer(Player $player)
    {
        $this->players->add($player);
        $player->setGame($this);
    }

    /**
     * @return integer
     */
    public function getTurns()
    {
        return $this->turns;
    }

    /**
     * @param integer
     */
    public function setTurns($turns)
    {
        $this->turns = $turns;
    }

    public function addTurn()
    {
        ++$this->turns;
    }

    public function getPieces()
    {
        $pieces = array();
        foreach($this->getPlayers() as $player) {
            $pieces = array_merge($pieces, $player->getPieces());
        }

        return $pieces;
    }

    /**
     * Get updatedAt
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt ?: $this->getCreatedAt();
    }

    /**
     * Set updatedAt
     * @param  \DateTime
     * @return null
     */
    public function setUpdatedAt(\DateTime $updatedAt)
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Tell if the game is being played right now
     * This method is not accurate
     *
     * @return bool
     **/
    public function isBeingPlayed()
    {
        if($this->getIsFinishedOrAborted()) {
            return false;
        }

        $interval = time() - $this->getUpdatedAt()->getTimestamp();

        return $interval < 20;
    }

    /**
     * Get createdAt
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set createdAt
     * @param  \DateTime
     * @return null
     */
    public function setCreatedAt(\DateTime $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    public function __toString()
    {
        return '#'.$this->getId(). 'turn '.$this->getTurns();
    }

    /**
     * @orm:PrePersist
     */
    public function setCreatedNow()
    {
        $this->createdAt = new \DateTime();
    }

    /**
     * @orm:PreUpdate
     */
    public function setUpdatedNow()
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * @orm:PostLoad
     */
    public function ensureDependencies()
    {
        $this->board = new Board($this);

        foreach($this->getPlayers() as $player) {
            $player->setGame($this);
            foreach($player->getPieces() as $piece) {
                $piece->setPlayer($player);
                $piece->setBoard($this->board);
            }
        }
    }
}