<?php

namespace Bundle\LichessBundle\Document;
use Bundle\DoctrineUserBundle\Model\User;
use Bundle\LichessBundle\Model;

class GameRepository extends ObjectRepository implements GameRepository
{
    /**
     * Find all games played by a user
     *
     * @return array
     **/
    public function findRecentByUser(User $user)
    {
        return $this->createRecentByUserQuery($user)
            ->getQuery()->execute();
    }

    /**
     * Finds one game by its Id
     *
     * @param string $id
     * @return Game or null
     **/
    public function findOneById($id)
    {
        return $this->find($id);
    }

    /**
     * Tells if a game with this id exists
     *
     * @param string $id
     * @return bool
     */
    public function existsById($id)
    {
        return 1 === $this->createQueryBuilder()
            ->field('id')->equals($id)
            ->getQuery()->count();
    }

    /**
     * Find ids of more recent games
     *
     * @return array
     **/
    public function findRecentStartedGameIds($nb)
    {
        $data = $this->createRecentQuery()
            ->hydrate(false)
            ->field('status')->equals(Game::STARTED)
            ->select('id')
            ->limit($nb)
            ->getQuery()->execute();
        $ids = array_keys(iterator_to_array($data));

        return $ids;
    }

    /**
     * Find games for the given ids, in the ids order
     *
     * @return array
     **/
    public function findGamesByIds($ids)
    {
        if(is_string($ids)) {
            $ids = explode(',', $ids);
        }

        $games = $this->createQueryBuilder()
            ->field('_id')->in($ids)
            ->getQuery()->execute();

        $games = iterator_to_array($games);

        // sort games in the order of ids
        $idPos = array_flip($ids);
        usort($games, function($a, $b) use ($idPos)
        {
            return $idPos[$a->getId()] > $idPos[$b->getId()];
        });

        return $games;
    }

    /**
     * Return the number of games
     *
     * @return int
     **/
    public function getNbGames()
    {
        return $this->createQueryBuilder()->getQuery()->count();
    }

    /**
     * Return the number of mates
     *
     * @return int
     **/
    public function getNbMates()
    {
        return $this->createQueryBuilder()
            ->field('status')->equals(Game::MATE)
            ->getQuery()->count();
    }

    /**
     * Return the number of wins
     *
     * @return int
     **/
    public function getNbWins(User $user)
    {
        return $this->createByUserQuery($user)
            ->field('winnerUserId')->equals((string) $user->getId())
            ->getQuery()->count();
    }

    /**
     * Return the number of losses
     *
     * @return int
     **/
    public function getNbLosses(User $user)
    {
        return $this->createByUserQuery($user)
            ->field('winnerUserId')->exists(true)
            ->field('winnerUserId')->notEqual((string) $user->getId())
            ->getQuery()->count();
    }

    /**
     * Return the number of user games
     *
     * @return int
     **/
    public function getNbUserGames(User $user)
    {
        return $this->createByUserQuery($user)
            ->field('status')->gte(Game::MATE)
            ->getQuery()->count();
    }

    /**
     * Query of all games ordered by updatedAt
     *
     * @return Doctrine\ODM\Mongodb\Query
     **/
    public function createRecentQuery()
    {
        return $this->createQueryBuilder()
            ->sort('updatedAt', 'DESC');
    }

    /**
     * Query of games played by a user ordered by updatedAt
     *
     * @param  User $user
     * @return Doctrine\ODM\Mongodb\Query
     **/
    public function createRecentByUserQuery(User $user)
    {
        return $this->createByUserQuery($user)
            ->sort('updatedAt', 'DESC');
    }

    /**
     * Query of games played by a user
     *
     * @param  User $user
     * @return Doctrine\ODM\Mongodb\Query
     **/
    public function createByUserQuery(User $user)
    {
        return $this->createQueryBuilder()
            ->field('userIds')->equals((string) $user->getId());
    }

    /**
     * Query of at least started games of a user
     *
     * @return Doctrine\ODM\Mongodb\Query
     **/
    public function createRecentStartedOrFinishedByUserQuery(User $user)
    {
        return $this->createRecentByUserQuery($user)
            ->field('status')->gte(Game::STARTED);
    }

    /**
     * Query of at least started games
     *
     * @return Doctrine\ODM\Mongodb\Query
     **/
    public function createRecentStartedOrFinishedQuery()
    {
        return $this->createRecentQuery()
            ->field('status')->gte(Game::STARTED);
    }

    /**
     * Query of at least mate games
     *
     * @return Doctrine\ODM\Mongodb\Query
     **/
    public function createRecentMateQuery()
    {
        return $this->createRecentQuery()
            ->field('status')->equals(Game::MATE);
    }

    public function findSimilar(Model\Game $game, \DateTime $since)
    {
        return $this->createQueryBuilder()
            ->field('id')->notEqual($game->getId())
            ->field('updatedAt')->gt(new \MongoDate($since->getTimestamp()))
            ->field('status')->equals(Game::STARTED)
            ->field('turns')->equals($game->getTurns())
            ->field('pgnMoves')->equals($game->getPgnMoves())
            ->hint(array('updatedAt' => -1))
            ->getQuery()->execute();
    }

    public function findCandidatesToCleanup()
    {
        $date = new \DateTime('-7 day');
        return $this->createQueryBuilder()
            ->field('updatedAt')->lt(new \MongoDate($date->getTimestamp()))
            ->field('status')->lt(Game::MATE)
            ->field('turns')->lt(2)
            ->getQuery()->execute();
    }
}
