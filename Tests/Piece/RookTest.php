<?php

namespace Bundle\LichessBundle\Tests\Piece;

use Bundle\LichessBundle\Tests\ChessTest;
use Bundle\LichessBundle\Chess\Board;

class RookTest extends ChessTest
{
    public function testGetBoard()
    {
        $generator = $this->getGenerator();
        $game = $generator->createGame();
        $board = $game->getBoard();

        return $board;
    }

    /**
     * @depends testGetBoard
     */
    public function testGetBasicTargetSquaresFirstMove(Board $board)
    {
        $piece = $board->getPieceByKey('a1');
        $expected = array();
        $this->assertEquals($expected, $piece->getBasicTargetKeys());
    }

    /**
     * @depends testGetBoard
     */
    public function testGetBasicTargetSquaresSecondMove(Board $board)
    {
        $piece = $board->getPieceByKey('a1');
        $piece->setX(3);
        $piece->setY(4);
        $board->compile();
        $expected = array('a4', 'b4', 'd4', 'e4', 'f4', 'g4', 'h4', 'c3', 'c5', 'c6', 'c7');
        $this->assertSquareKeys($expected, $piece->getBasicTargetKeys());
    }

    protected function assertSquareKeys($expected, $result)
    {
        $this->assertEquals(array(), array_diff($expected, $result));
        $this->assertEquals(array(), array_diff($result, $expected));
    }

}
