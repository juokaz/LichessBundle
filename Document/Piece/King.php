<?php

namespace Bundle\LichessBundle\Document\Piece;
use Bundle\LichessBundle\Document\Piece;
use Bundle\LichessBundle\Chess\Board;
use Bundle\LichessBundle\Model\Piece as Model;

/**
 * @mongodb:EmbeddedDocument
 */
class King extends Piece implements Model\King
{
    public function getClass()
    {
        return 'King';
    }

    public function getBasicTargetKeys()
    {
        $mySquare = $this->getSquare();
        $x = $mySquare->getX();
        $y = $mySquare->getY();
        $keys = array();
        $board = $this->getBoard();

        /**
         * That's ugly and could be done easily in a nicer way.
         * But I needed performance optimization.
         */
        for($dx = -1; $dx <2; $dx++) {
            $_x = $x+$dx;
            if($_x>0 && $_x<9) {
                for($dy=-1; $dy<2; $dy++) {
                    if(0 === $dx && 0 === $dy) {
                        continue;
                    }
                    $_y = $y+$dy;
                    if($_y>0 && $_y<9) {
                        $key = Board::posToKey($_x, $_y);
                        if(($piece = $board->getPieceByKey($key)) && $piece->getColor() === $this->color) {
                            continue;
                        }
                        $keys[] = $key;
                    }
                }
            }
        }

        return $keys;
    }
}
