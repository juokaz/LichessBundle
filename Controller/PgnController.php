<?php

namespace Bundle\LichessBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Bundle\LichessBundle\Notation\PgnDumper;

class PgnController extends Controller
{
    public function analyseAction($id, $color)
    {
        $game = $this->findGame($id);
        $pgn = $this->get('lichess_pgn_dumper')->dumpGame($game);

        return $this->render('LichessBundle:Pgn:analyse.twig', array(
            'game'         => $game,
            'color'        => $color,
            'reverseColor' => 'white' === $color ? 'black' : 'white',
            'pgn'          => $pgn
        ));
    }

    public function exportAction($id)
    {
        $game = $this->findGame($id);
        $pgn = $this->get('lichess_pgn_dumper')->dumpGame($game);

        $response = $this->createResponse($pgn);
        $response->headers->set('Content-Type', 'text/plain');
        return $response;
    }

    /**
     * Return the game for this id
     *
     * @param string $id
     * @return Game
     */
    protected function findGame($id)
    {
        $game = $this->get('lichess.repository.game')->findOneById($id);

        if(!$game) {
            throw new NotFoundHttpException('Can\'t find game '.$id);
        }

        return $game;
    }
}
