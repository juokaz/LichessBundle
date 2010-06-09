<?php

namespace Bundle\LichessBundle\Tests\Piece;

use Bundle\LichessBundle\Chess\Generator;
use Bundle\LichessBundle\Chess\Board;
use Bundle\LichessBundle\Entities\Piece\King;
use Bundle\LichessBundle\Entities\Piece;

require_once __DIR__.'/../gameBootstrap.php';

class KingTest extends \PHPUnit_Framework_TestCase
{
    protected $board;

    public function setup()
    {
        $generator = new Generator();
        $game = $generator->createGame();
        $this->board = $game->getBoard();
    }

    public function testGetBasicTargetSquaresFirstMove()
    {
        $piece = $this->board->getPieceByKey('e1');
        $this->assertTrue($piece instanceof King);
        $expected = array();
        $squares = $piece->getBasicTargetSquares();
        $squares = $this->board->cleanSquares($squares);

        $this->assertSquareKeys($expected, $this->board->squaresToKeys($squares));
    }

    public function testGetBasicTargetSquaresSecondMove()
    {
        $piece = $this->board->getPieceByKey('e1');
        $this->assertTrue($piece instanceof King);
        $piece->setX(3);
        $piece->setY(4);
        $piece->setFirstMove(1);
        $this->board->compile();
        $expected = array('b5', 'c5', 'd5', 'd4', 'd3', 'c3', 'b3', 'b4');
        $squares = $piece->getBasicTargetSquares();
        $squares = $this->board->cleanSquares($squares);
        $this->assertSquareKeys($expected, $this->board->squaresToKeys($squares));
    }

    protected function assertSquareKeys($expected, $result)
    {
        $this->assertEquals(array(), array_diff($expected, $result));
        $this->assertEquals(array(), array_diff($result, $expected));
    }

}
