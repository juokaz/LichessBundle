<?php $winner = $player->getGame()->getWinner() ?>
<div class="lichess_table finished">
    <div class="lichess_opponent">
     <?php echo $player->getOpponent()->getIsAi() ? 'Opponent is Crafty A.I. level '.$player->getOpponent()->getAiLevel() : 'Human opponent' ?>
    </div>
    <div class="lichess_separator"></div>
    <div class="lichess_current_player">
        <?php if($winner): ?>
            <div class="lichess_player <?php echo $winner->getColor() ?>">
                <div class="lichess_piece king <?php echo $winner->getColor() ?>"></div>
                <p><?php echo $winner->getColor() ?> is victorious</p>
            </div>
        <?php else: ?>
            <div class="lichess_player">
                <p>Stalemate.</p>
            </div>
        <?php endif; ?>
    </div>
    <div class="lichess_control clearfix">
        <a class="lichess_new_game" href="<?php echo $view->router->generate('lichess_homepage') ?>">New game</a>
    </div>
</div>